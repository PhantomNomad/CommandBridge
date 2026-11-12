<?php
require_once __DIR__ . '/settings_helpers.php';

require_system_administrator();
$pdo = db();

$roleCatalogueUser = current_user();
$roleCatalogueAuditUserId = isset($roleCatalogueUser['id']) && is_numeric($roleCatalogueUser['id'])
    ? (int) $roleCatalogueUser['id']
    : null;

function rc_bool(string $name): int
{
    return isset($_POST[$name]) && $_POST[$name] === '1' ? 1 : 0;
}

function rc_catalogue_ready(): bool
{
    return in_array('is_active', table_columns('roles'), true);
}

function rc_role(PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare("
        SELECT r.id, r.role_code, r.role_name, r.is_command_role, r.is_active,
               pc.group_name, pc.group_sort, pc.sort_order,
               pc.reports_to_acronym, pc.is_section_chief_or_command
        FROM roles r
        LEFT JOIN incident_position_catalog pc ON pc.acronym = r.role_code
        WHERE r.id = ?
        LIMIT 1
    ");
    $stmt->execute([$id]);
    return $stmt->fetch() ?: null;
}

$catalogueReady = rc_catalogue_ready();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf_token();

    if (!$catalogueReady) {
        flash('error', 'Database migration required. The database is out of date. Run update.sh (see docs/UPDATING.md), then try again.');
        header('Location: /admin/role_catalogue.php');
        exit;
    }

    $action = (string) ($_POST['action'] ?? '');

    try {
        if ($action === 'toggle') {
            $id = (int) ($_POST['id'] ?? 0);
            $role = rc_role($pdo, $id);
            if (!$role) {
                throw new RuntimeException('Position not found.');
            }

            $newStatus = $role['is_active'] ? 0 : 1;
            $pdo->beginTransaction();
            $stmt = $pdo->prepare('UPDATE roles SET is_active = ? WHERE id = ?');
            $stmt->execute([$newStatus, $id]);
            $stmt = $pdo->prepare('UPDATE incident_position_catalog SET is_active = ? WHERE acronym = ?');
            $stmt->execute([$newStatus, $role['role_code']]);
            $pdo->commit();

            audit_log(
                $roleCatalogueAuditUserId,
                'role_catalogue_status',
                'role',
                $id,
                sprintf(
                    'Position %s (%s) was %s.',
                    $role['role_name'],
                    $role['role_code'],
                    $newStatus ? 'reactivated' : 'deactivated'
                )
            );
            flash('success', $newStatus ? 'Position reactivated.' : 'Position deactivated. It remains available in historical records.');
        } elseif ($action === 'save') {
            $id = (int) ($_POST['id'] ?? 0);
            $isNew = $id === 0;
            $existing = $isNew ? null : rc_role($pdo, $id);
            if (!$isNew && !$existing) {
                throw new RuntimeException('Position not found.');
            }

            $roleCode = $isNew ? strtoupper(trim((string) ($_POST['role_code'] ?? ''))) : $existing['role_code'];
            $roleName = trim((string) ($_POST['role_name'] ?? ''));
            $groupName = trim((string) ($_POST['group_name'] ?? 'Other'));
            $groupSort = max(0, min(9999, (int) ($_POST['group_sort'] ?? 9999)));
            $sortOrder = max(0, min(9999, (int) ($_POST['sort_order'] ?? 9999)));
            $reportsTo = strtoupper(trim((string) ($_POST['reports_to_acronym'] ?? '')));
            $commandRole = rc_bool('is_command_role');
            $sectionChief = rc_bool('is_section_chief_or_command');
            $isActive = rc_bool('is_active');

            if (!preg_match('/^[A-Z0-9][A-Z0-9_-]{0,49}$/', $roleCode)) {
                throw new RuntimeException('Position code must contain only uppercase letters, numbers, hyphens, or underscores.');
            }
            if ($roleName === '' || mb_strlen($roleName) > 150) {
                throw new RuntimeException('Position title is required and must be 150 characters or fewer.');
            }
            if ($groupName === '' || mb_strlen($groupName) > 100) {
                throw new RuntimeException('Group name is required and must be 100 characters or fewer.');
            }
            if ($reportsTo === $roleCode) {
                throw new RuntimeException('A position cannot report to itself.');
            }

            $codes = $pdo->query('SELECT id, role_code FROM roles')->fetchAll();
            foreach ($codes as $candidate) {
                if ($isNew && $candidate['role_code'] === $roleCode) {
                    throw new RuntimeException('That position code already exists.');
                }
            }
            if ($reportsTo !== '') {
                $validParent = false;
                foreach ($codes as $candidate) {
                    if ($candidate['role_code'] === $reportsTo) {
                        $validParent = true;
                        break;
                    }
                }
                if (!$validParent) {
                    throw new RuntimeException('The selected reporting position does not exist.');
                }
            }

            $pdo->beginTransaction();
            if ($isNew) {
                $stmt = $pdo->prepare('INSERT INTO roles (role_code, role_name, is_command_role, is_active) VALUES (?, ?, ?, ?)');
                $stmt->execute([$roleCode, $roleName, $commandRole, $isActive]);
                $id = (int) $pdo->lastInsertId();
            } else {
                $stmt = $pdo->prepare('UPDATE roles SET role_name = ?, is_command_role = ?, is_active = ? WHERE id = ?');
                $stmt->execute([$roleName, $commandRole, $isActive, $id]);
            }

            $stmt = $pdo->prepare("
                INSERT INTO incident_position_catalog
                    (group_name, group_sort, sort_order, acronym, full_title, reports_to_acronym, is_section_chief_or_command, is_active)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    group_name = VALUES(group_name),
                    group_sort = VALUES(group_sort),
                    sort_order = VALUES(sort_order),
                    full_title = VALUES(full_title),
                    reports_to_acronym = VALUES(reports_to_acronym),
                    is_section_chief_or_command = VALUES(is_section_chief_or_command),
                    is_active = VALUES(is_active)
            ");
            $stmt->execute([
                $groupName, $groupSort, $sortOrder, $roleCode, $roleName,
                $reportsTo !== '' ? $reportsTo : null, $sectionChief, $isActive
            ]);
            $pdo->commit();

            audit_log(
                $roleCatalogueAuditUserId,
                $isNew ? 'role_catalogue_create' : 'role_catalogue_update',
                'role',
                $id,
                sprintf(
                    'Position %s (%s) was %s.',
                    $roleName,
                    $roleCode,
                    $isNew ? 'created' : 'updated'
                )
            );
            flash('success', $isNew ? 'Position added to the catalogue.' : 'Position catalogue entry updated.');
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('Role catalogue update failed: ' . $e->getMessage());
        flash('error', $e instanceof RuntimeException ? $e->getMessage() : 'The position catalogue could not be updated.');
    }

    header('Location: /admin/role_catalogue.php');
    exit;
}

$showInactive = isset($_GET['show_inactive']) && $_GET['show_inactive'] === '1';
$editId = (int) ($_GET['edit'] ?? 0);
$editing = ($catalogueReady && $editId > 0) ? rc_role($pdo, $editId) : null;

$roles = [];
$roleChoices = [];
$groupChoices = [];
if ($catalogueReady) {
    $sql = "
        SELECT r.id, r.role_code, r.role_name, r.is_command_role, r.is_active,
               pc.group_name, pc.group_sort, pc.sort_order,
               pc.reports_to_acronym, pc.is_section_chief_or_command
        FROM roles r
        LEFT JOIN incident_position_catalog pc ON pc.acronym = r.role_code
    ";
    if (!$showInactive) {
        $sql .= ' WHERE r.is_active = 1 ';
    }
    $sql .= ' ORDER BY COALESCE(pc.group_sort, 9999), COALESCE(pc.sort_order, 9999), r.role_name ';
    $roles = $pdo->query($sql)->fetchAll();
    $roleChoices = $pdo->query('SELECT role_code, role_name, is_active FROM roles ORDER BY role_name')->fetchAll();
    $groupChoices = $pdo->query("
        SELECT DISTINCT TRIM(group_name) AS group_name
        FROM incident_position_catalog
        WHERE group_name IS NOT NULL AND TRIM(group_name) <> ''
        ORDER BY group_name
    ")->fetchAll(PDO::FETCH_COLUMN);
}

$form = [
    'id' => $editing['id'] ?? 0,
    'role_code' => $editing['role_code'] ?? '',
    'role_name' => $editing['role_name'] ?? '',
    'group_name' => $editing['group_name'] ?? 'Other',
    'group_sort' => $editing['group_sort'] ?? 9999,
    'sort_order' => $editing['sort_order'] ?? 9999,
    'reports_to_acronym' => $editing['reports_to_acronym'] ?? '',
    'is_command_role' => $editing['is_command_role'] ?? 0,
    'is_section_chief_or_command' => $editing['is_section_chief_or_command'] ?? 0,
    'is_active' => $editing['is_active'] ?? 1,
];

render_header($editing ? 'Edit Position Catalogue Entry' : 'Position Catalogue');
settings_menu('position_catalogue');
?>
<style>
.catalogue-grid { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:16px; }
.catalogue-wide { grid-column:1/-1; }
.catalogue-actions { display:flex; gap:8px; align-items:center; flex-wrap:wrap; }
.catalogue-code { font-family:monospace; font-weight:700; }
.catalogue-note { margin:0 0 16px; color:#4b5563; }
@media (max-width: 760px) { .catalogue-grid { grid-template-columns:1fr; } }
</style>

<main class="container">
  <div class="page-header">
    <div>
      <h1>Position Catalogue</h1>
      <p>Manage ECC and ICS position names, ordering, reporting relationships, and availability.</p>
    </div>
  </div>

  <?php if (!$catalogueReady): ?>
    <div class="alert alert-error">The database is out of date. Run <code>update.sh</code> (see docs/UPDATING.md) before using this page.</div>
  <?php else: ?>
    <section class="panel">
      <h2><?= $editing ? 'Edit Position' : 'Add Position' ?></h2>
      <p class="catalogue-note">Position codes are fixed after creation because they may be used by permissions, forms, and historic assignments. To replace a code, create a new position and deactivate the prior one.</p>
      <form method="post">
        <?= csrf_field(); ?>
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="id" value="<?= (int) $form['id'] ?>">
        <div class="catalogue-grid">
          <label>
            Position code
            <?php if ($editing): ?>
              <input value="<?= e($form['role_code']) ?>" disabled>
            <?php else: ?>
              <input name="role_code" maxlength="50" required placeholder="e.g. DEPUTY_ECC_DIRECTOR">
            <?php endif; ?>
          </label>
          <label>
            Position title
            <input name="role_name" maxlength="150" required value="<?= e($form['role_name']) ?>" placeholder="e.g. Deputy ECC Director">
          </label>
          <label>
            Group
            <select name="group_name" required>
              <?php foreach ($groupChoices as $groupChoice): ?>
                <option value="<?= e($groupChoice) ?>" <?= $groupChoice === $form['group_name'] ? 'selected' : '' ?>>
                  <?= e($groupChoice) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </label>
          <label>
            Reports to
            <select name="reports_to_acronym">
              <option value="">No reporting position</option>
              <?php foreach ($roleChoices as $choice): ?>
                <?php if ($choice['role_code'] !== $form['role_code']): ?>
                  <option value="<?= e($choice['role_code']) ?>" <?= $choice['role_code'] === $form['reports_to_acronym'] ? 'selected' : '' ?>>
                    <?= e($choice['role_name']) ?><?= $choice['is_active'] ? '' : ' (inactive)' ?>
                  </option>
                <?php endif; ?>
              <?php endforeach; ?>
            </select>
          </label>
          <label>
            Group order
            <input type="number" name="group_sort" min="0" max="9999" value="<?= (int) $form['group_sort'] ?>">
          </label>
          <label>
            Position order
            <input type="number" name="sort_order" min="0" max="9999" value="<?= (int) $form['sort_order'] ?>">
          </label>
          <div class="catalogue-wide catalogue-actions">
            <label><input type="checkbox" name="is_command_role" value="1" <?= $form['is_command_role'] ? 'checked' : '' ?>> Command role</label>
            <label><input type="checkbox" name="is_section_chief_or_command" value="1" <?= $form['is_section_chief_or_command'] ? 'checked' : '' ?>> Section Chief / Command position</label>
            <label><input type="checkbox" name="is_active" value="1" <?= $form['is_active'] ? 'checked' : '' ?>> Available for new assignments</label>
          </div>
        </div>
        <div class="form-actions">
          <button class="btn btn-primary" type="submit"><?= $editing ? 'Save Changes' : 'Add Position' ?></button>
          <?php if ($editing): ?><a class="btn" href="/admin/role_catalogue.php">Cancel</a><?php endif; ?>
        </div>
      </form>
    </section>

    <section class="panel">
      <div class="page-header">
        <h2><?= $showInactive ? 'All Positions' : 'Active Positions' ?></h2>
        <a class="btn" href="/admin/role_catalogue.php<?= $showInactive ? '' : '?show_inactive=1' ?>"><?= $showInactive ? 'Hide Inactive' : 'Show Inactive' ?></a>
      </div>
      <p class="catalogue-note">Deactivating a position removes it from new assignment selectors while retaining historical records.</p>
      <div class="table-wrap">
        <table>
          <thead><tr><th>Code</th><th>Position</th><th>Group</th><th>Reports To</th><th>Status</th><th>Actions</th></tr></thead>
          <tbody>
          <?php foreach ($roles as $role): ?>
            <tr>
              <td class="catalogue-code"><?= e($role['role_code']) ?></td>
              <td><?= e($role['role_name']) ?></td>
              <td><?= e($role['group_name'] ?: 'Other') ?></td>
              <td><?= e($role['reports_to_acronym'] ?: '—') ?></td>
              <td><?= $role['is_active'] ? 'Active' : 'Inactive' ?></td>
              <td class="catalogue-actions">
                <a class="btn btn-small" href="/admin/role_catalogue.php?edit=<?= (int) $role['id'] ?>">Edit</a>
                <form method="post" style="display:inline">
                  <?= csrf_field(); ?>
                  <input type="hidden" name="action" value="toggle">
                  <input type="hidden" name="id" value="<?= (int) $role['id'] ?>">
                  <button class="btn btn-small" type="submit"><?= $role['is_active'] ? 'Deactivate' : 'Reactivate' ?></button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
          <?php if (!$roles): ?><tr><td colspan="6">No positions found.</td></tr><?php endif; ?>
          </tbody>
        </table>
      </div>
    </section>
  <?php endif; ?>
</main>
<?php render_footer(); ?>
