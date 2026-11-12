<?php
declare(strict_types=1);
require_once __DIR__ . '/settings_helpers.php';

$pdo = db();
$tableAvailable = true;
try {
    $pdo->query('SELECT 1 FROM resource_kind_type_catalog LIMIT 1');
} catch (Throwable $e) {
    $tableAvailable = false;
}

function resource_catalogue_clean(string $value, int $max, string $label): string {
    $value = trim($value);
    if ($value === '') {
        throw new RuntimeException($label . ' is required.');
    }
    if (mb_strlen($value) > $max) {
        throw new RuntimeException($label . ' must be ' . $max . ' characters or fewer.');
    }
    return $value;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf_token();

    try {
        if (!$tableAvailable) {
            throw new RuntimeException('The resource catalogue database table is not available.');
        }

        $category = resource_catalogue_clean((string)($_POST['category_name'] ?? ''), 100, 'Category');
        $kind = resource_catalogue_clean((string)($_POST['kind_name'] ?? ''), 150, 'Kind');
        $primaryUnit = resource_catalogue_clean((string)($_POST['primary_unit'] ?? ''), 50, 'Primary unit');
        $alternateUnits = trim((string)($_POST['alternate_units'] ?? ''));
        if (mb_strlen($alternateUnits) > 255) {
            throw new RuntimeException('Alternate units must be 255 characters or fewer.');
        }

        // A named generic option is safer than a NULL type label and prevents duplicate generic records.
        $typeLabel = trim((string)($_POST['type_label'] ?? ''));
        if ($typeLabel === '') {
            $typeLabel = 'General / unspecified';
        }
        if (mb_strlen($typeLabel) > 100) {
            throw new RuntimeException('Type / capability label must be 100 characters or fewer.');
        }

        $typeLevelText = trim((string)($_POST['type_level'] ?? ''));
        $typeLevel = $typeLevelText === '' ? null : filter_var($typeLevelText, FILTER_VALIDATE_INT);
        if ($typeLevelText !== '' && ($typeLevel === false || $typeLevel < 1 || $typeLevel > 99)) {
            throw new RuntimeException('Type level must be a whole number from 1 to 99, or blank.');
        }

        $sortText = trim((string)($_POST['sort_order'] ?? ''));
        if ($sortText === '') {
            $next = $pdo->prepare('SELECT COALESCE(MAX(sort_order), 0) + 10 FROM resource_kind_type_catalog WHERE category_name = ?');
            $next->execute([$category]);
            $sortOrder = (int)$next->fetchColumn();
        } else {
            $sortOrder = filter_var($sortText, FILTER_VALIDATE_INT);
            if ($sortOrder === false || $sortOrder < 0) {
                throw new RuntimeException('Sort order must be a positive whole number or zero.');
            }
        }

        $isActive = isset($_POST['is_active']) ? 1 : 0;
        $id = (int)($_POST['id'] ?? 0);

        if ($id > 0) {
            $stmt = $pdo->prepare(
                'UPDATE resource_kind_type_catalog
                 SET category_name=?, kind_name=?, primary_unit=?, alternate_units=?, type_label=?, type_level=?, sort_order=?, is_active=?, updated_at=NOW()
                 WHERE id=?'
            );
            $stmt->execute([$category, $kind, $primaryUnit, $alternateUnits ?: null, $typeLabel, $typeLevel, $sortOrder, $isActive, $id]);
            audit_log((int)current_user()['id'], 'resource_catalogue_updated', 'resource_kind_type_catalog', $id, $category . ': ' . $kind . ' - ' . $typeLabel);
            flash('success', 'Resource catalogue entry updated.');
        } else {
            $stmt = $pdo->prepare(
                'INSERT INTO resource_kind_type_catalog (category_name, kind_name, primary_unit, alternate_units, type_label, type_level, sort_order, is_active, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())'
            );
            $stmt->execute([$category, $kind, $primaryUnit, $alternateUnits ?: null, $typeLabel, $typeLevel, $sortOrder, $isActive]);
            $id = (int)$pdo->lastInsertId();
            audit_log((int)current_user()['id'], 'resource_catalogue_created', 'resource_kind_type_catalog', $id, $category . ': ' . $kind . ' - ' . $typeLabel);
            flash('success', 'Resource catalogue entry created.');
        }

        header('Location: ' . app_url('/admin/resource_catalogue.php?category=' . rawurlencode($category) . '&show_inactive=' . (!empty($_POST['show_inactive']) ? '1' : '0')));
        exit;
    } catch (Throwable $e) {
        flash('error', $e->getMessage());
        header('Location: ' . app_url('/admin/resource_catalogue.php'));
        exit;
    }
}

$categories = [];
$entries = [];
$selectedCategory = trim((string)($_GET['category'] ?? ''));
$showInactive = !empty($_GET['show_inactive']);

if ($tableAvailable) {
    $categories = $pdo->query('SELECT DISTINCT category_name FROM resource_kind_type_catalog ORDER BY category_name')->fetchAll(PDO::FETCH_COLUMN);
    if ($selectedCategory === '') {
        $selectedCategory = in_array('Equipment', $categories, true) ? 'Equipment' : (string)($categories[0] ?? '');
    }

    $sql = 'SELECT id, category_name, kind_name, primary_unit, alternate_units, type_label, type_level, sort_order, is_active
            FROM resource_kind_type_catalog
            WHERE (? = \'\' OR category_name = ?)';
    if (!$showInactive) {
        $sql .= ' AND is_active = 1';
    }
    $sql .= ' ORDER BY category_name, kind_name, sort_order, type_level, type_label';
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$selectedCategory, $selectedCategory]);
    $entries = $stmt->fetchAll();
}


$editId = (int)($_GET['edit'] ?? 0);
$editEntry = null;
if ($editId > 0 && $tableAvailable) {
    $editStmt = $pdo->prepare('SELECT * FROM resource_kind_type_catalog WHERE id = ? LIMIT 1');
    $editStmt->execute([$editId]);
    $editEntry = $editStmt->fetch() ?: null;
}

render_header('Resource Catalogue');
settings_menu('resource_catalogue');
?>
<style>
.rc-wrap { max-width: 1480px; margin: 0 auto; }
.rc-intro { max-width: 950px; color: #566575; margin: 0 0 18px; }
.rc-card { margin: 0 0 18px; }
.rc-grid { display:grid; grid-template-columns:repeat(4,minmax(150px,1fr)); gap:12px; align-items:end; }
.rc-grid .wide { grid-column:span 2; }
.rc-grid label { display:block; margin:0 0 4px; font-weight:600; }
.rc-grid input, .rc-grid select { width:100%; box-sizing:border-box; min-height:38px; }
.rc-actions { display:flex; gap:8px; align-items:center; flex-wrap:wrap; }
.rc-filter { display:flex; gap:14px; align-items:end; flex-wrap:wrap; }
.rc-filter label { display:block; margin:0 0 4px; font-weight:600; }
.rc-filter select { min-width:220px; min-height:38px; }
.rc-filter .check { display:flex; gap:7px; align-items:center; padding-bottom:9px; }
.rc-table-wrap { overflow-x:auto; }
.rc-table { width:100%; min-width:1040px; border-collapse:collapse; }
.rc-table th { text-align:left; white-space:nowrap; padding:9px; background:#eef4f8; border-bottom:1px solid #cbd5df; }
.rc-table td { padding:9px; border-bottom:1px solid #e2e8f0; vertical-align:middle; }
.rc-table tbody tr:nth-child(even) { background:#fbfcfd; }
.rc-table tbody tr:hover { background:#f1f7fb; }
.rc-status { display:inline-block; padding:3px 8px; border-radius:999px; font-size:.8rem; font-weight:600; }
.rc-status.active { background:#dff4e6; color:#1d6b38; }
.rc-status.inactive { background:#f6e3e3; color:#922b2b; }
.rc-muted { color:#667085; }
@media (max-width:900px) { .rc-grid { grid-template-columns:repeat(2,minmax(0,1fr)); } .rc-grid .wide { grid-column:span 2; } }
@media (max-width:560px) { .rc-grid { grid-template-columns:1fr; } .rc-grid .wide { grid-column:span 1; } }
</style>

<main class="rc-wrap">
  <div class="card rc-card">
    <h2>Resource Catalogue</h2>
    <p class="rc-intro">Maintain resource categories, equipment kinds, capability types, units, and display order used in ICS resource requests. Use a specific type where capability matters, and retain <em>General / unspecified</em> where a broad selection is appropriate.</p>
  </div>

  <?php if ($message): ?><div class="alert alert-success"><?= e($message) ?></div><?php endif; ?>
  <?php foreach ($errors as $error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endforeach; ?>

  <?php if (!$tableAvailable): ?>
    <div class="alert alert-danger">The resource catalogue table is not available. Apply the resource catalogue database update before using this page.</div>
  <?php else: ?>
    <section class="card rc-card" id="resource-form">
      <h3><?= $editEntry ? 'Edit Resource Type' : 'Add Resource Type' ?></h3>
      <p class="small"><?= $editEntry ? 'Update the selected resource catalogue entry, then save your changes.' : 'Add a new kind of resource or a new capability/type for an existing resource kind.' ?></p>
      <form method="post"><?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= (int)($editEntry['id'] ?? 0) ?>">
        <input type="hidden" name="show_inactive" value="<?= $showInactive ? '1' : '0' ?>">
        <div class="rc-grid">
          <div><label>Category <input name="category_name" maxlength="100" required value="<?= e((string)($editEntry['category_name'] ?? $selectedCategory)) ?>" placeholder="e.g., Equipment"></label></div>
          <div class="wide"><label>Resource kind <input name="kind_name" maxlength="150" required value="<?= e((string)($editEntry['kind_name'] ?? '')) ?>" placeholder="e.g., Dozer"></label></div>
          <div><label>Type / capability <input name="type_label" maxlength="100" value="<?= e((string)($editEntry['type_label'] ?? '')) ?>" placeholder="Blank = General / unspecified"></label></div>
          <div><label>Primary unit <input name="primary_unit" maxlength="50" required value="<?= e((string)($editEntry['primary_unit'] ?? 'unit')) ?>"></label></div>
          <div><label>Alternate units <input name="alternate_units" maxlength="255" value="<?= e((string)($editEntry['alternate_units'] ?? '')) ?>" placeholder="Optional"></label></div>
          <div><label>Type level <input name="type_level" type="number" min="1" max="99" value="<?= isset($editEntry['type_level']) && $editEntry['type_level'] !== null ? (int)$editEntry['type_level'] : '' ?>" placeholder="Optional"></label></div>
          <div><label>Sort order <input name="sort_order" type="number" min="0" value="<?= isset($editEntry['sort_order']) ? (int)$editEntry['sort_order'] : 0 ?>"></label></div>
          <div><label>Status <select name="is_active"><option value="1" <?= !$editEntry || !empty($editEntry['is_active']) ? 'selected' : '' ?>>Active</option><option value="0" <?= $editEntry && empty($editEntry['is_active']) ? 'selected' : '' ?>>Inactive</option></select></label></div>
          <div class="rc-actions">
            <button class="btn btn-primary" type="submit"><?= $editEntry ? 'Save Changes' : 'Add Resource Type' ?></button>
            <?php if ($editEntry): ?><a class="btn btn-secondary" href="<?= e(app_url('admin/resource_catalogue.php')) ?>">Cancel Edit</a><?php endif; ?>
          </div>
        </div>
      </form>
    </section>

    <section class="card rc-card">
      <h3>Filter Resources</h3>
      <form method="get" class="rc-filter">
        <div><label>Category
          <select name="category"><option value="">All categories</option>
          <?php foreach ($categories as $category): ?><option value="<?= e($category) ?>" <?= $category === $selectedCategory ? 'selected' : '' ?>><?= e($category) ?></option><?php endforeach; ?>
          </select>
        </label></div>
        <label class="check"><input type="checkbox" name="show_inactive" value="1" <?= $showInactive ? 'checked' : '' ?>> Include inactive entries</label>
        <button class="btn btn-secondary" type="submit">Apply Filter</button>
        <a class="btn btn-secondary" href="<?= e(app_url('admin/resource_catalogue.php')) ?>">Clear</a>
      </form>
    </section>

    <section class="card rc-card">
      <h3>Resource List <span class="small rc-muted">(<?= count($entries) ?> shown)</span></h3>
      <div class="rc-table-wrap">
        <table class="rc-table">
          <thead><tr><th>Category</th><th>Resource Kind</th><th>Type / Capability</th><th>Primary Unit</th><th>Alternate Units</th><th>Level</th><th>Sort</th><th>Status</th><th></th></tr></thead>
          <tbody>
          <?php foreach ($entries as $entry): ?>
            <?php
              $editUrl = app_url('admin/resource_catalogue.php') . '?edit=' . (int)$entry['id'];
              if ($selectedCategory !== '') { $editUrl .= '&category=' . rawurlencode($selectedCategory); }
              if ($showInactive) { $editUrl .= '&show_inactive=1'; }
            ?>
            <tr>
              <td><?= e((string)$entry['category_name']) ?></td>
              <td><?= e((string)$entry['kind_name']) ?></td>
              <td><?= e((string)($entry['type_label'] ?: 'General / unspecified')) ?></td>
              <td><?= e((string)$entry['primary_unit']) ?></td>
              <td><?= e((string)($entry['alternate_units'] ?: '—')) ?></td>
              <td><?= $entry['type_level'] === null ? '—' : (int)$entry['type_level'] ?></td>
              <td><?= (int)$entry['sort_order'] ?></td>
              <td><span class="rc-status <?= !empty($entry['is_active']) ? 'active' : 'inactive' ?>"><?= !empty($entry['is_active']) ? 'Active' : 'Inactive' ?></span></td>
              <td><a class="btn btn-secondary" href="<?= e($editUrl) ?>#resource-form">Edit</a></td>
            </tr>
          <?php endforeach; ?>
          <?php if (!$entries): ?><tr><td colspan="9">No entries found for this category.</td></tr><?php endif; ?>
          </tbody>
        </table>
      </div>
    </section>
  <?php endif; ?>
</main>
<?php render_footer(); ?>
