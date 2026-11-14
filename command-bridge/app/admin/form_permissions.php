<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/security.php';
require_csrf_for_post();

require_once dirname(__DIR__) . '/includes/layout.php';
require_permissions_admin_access();

$pdo = db();
$formCatalog = get_form_catalog();
$formCode = strtoupper(trim((string)($_GET['form_code'] ?? $_POST['form_code'] ?? 'ICS201')));
if (!isset($formCatalog[$formCode])) {
    $formCode = 'ICS201';
}

if (!current_user_can_manage_form_permissions($formCode) && empty(current_user()['is_sysadmin'])) {
    http_response_code(403);
    echo 'Forbidden';
    exit;
}

$roles = get_roles();
$roleOptions = [];
foreach ($roles as $role) {
    $roleOptions[strtoupper((string)$role['code'])] = strtoupper((string)$role['code']) . ' - ' . (string)$role['name'];
}
$roleOptions['ALL_AUTHENTICATED'] = 'ALL_AUTHENTICATED - All signed-in users';
ksort($roleOptions);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (isset($_POST['save_default_mode'])) {
            update_form_default_non_editor_mode($formCode, (string)($_POST['default_non_editor_mode'] ?? 'read_only'));
            flash('success', 'Default non-editor mode updated.');
            audit_log((int)(current_user()['id'] ?? 0), 'update_form_default_mode', 'form_permissions', null, 'Updated default non-editor mode for ' . $formCode);
        } elseif (isset($_POST['save_permission'])) {
            $roleCode = strtoupper(trim((string)($_POST['role_code'] ?? '')));
            if ($roleCode === '') {
                throw new RuntimeException('Role code is required.');
            }
            upsert_form_permission_row($formCode, $roleCode, $_POST, current_user() ?? []);
            flash('success', 'Permissions saved for ' . $roleCode . '.');
            audit_log((int)(current_user()['id'] ?? 0), 'save_form_permission', 'form_permissions', null, 'Saved form permission for ' . $formCode . '/' . $roleCode);
        }
        header('Location: ' . app_url('/admin/form_permissions.php?form_code=' . urlencode($formCode)));
        exit;
    } catch (Throwable $e) {
        flash('error', 'Unable to save permissions: ' . $e->getMessage());
    }
}

$rows = get_form_matrix_rows($formCode);
$defaultMode = get_form_default_non_editor_mode($formCode);

render_header('Form Permissions');
?>
<div class="card">
  <h2>Permissions Matrix</h2>
  <p>Use this page to manage who can view, create, edit, submit final, and manage permissions for each form. Intended for SysAdmin-controlled administration.</p>
  <form method="get" class="inline-form">
    <label>Form</label>
    <select name="form_code" onchange="this.form.submit()">
      <?php foreach ($formCatalog as $code => $label): ?>
        <option value="<?= e($code) ?>" <?= $formCode === $code ? 'selected' : '' ?>><?= e($label) ?></option>
      <?php endforeach; ?>
    </select>
    <noscript><button class="btn btn-secondary" type="submit">Load</button></noscript>
  </form>
</div>

<div class="card">
  <h2>Default Non-Editor Experience</h2>
  <form method="post" class="inline-form">
    <?= csrf_field() ?>
    <input type="hidden" name="form_code" value="<?= e($formCode) ?>">
    <input type="hidden" name="save_default_mode" value="1">
    <label>Default mode for users who can view but not edit</label>
    <select name="default_non_editor_mode">
      <?php foreach (['read_only' => 'Read-only', 'print_preview' => 'Print Preview', 'hidden' => 'Hidden'] as $modeValue => $modeLabel): ?>
        <option value="<?= e($modeValue) ?>" <?= $defaultMode === $modeValue ? 'selected' : '' ?>><?= e($modeLabel) ?></option>
      <?php endforeach; ?>
    </select>
    <button class="btn" type="submit">Save Default</button>
  </form>
</div>

<div class="card">
  <h2>Current Permission Rows</h2>
  <table>
    <thead>
      <tr>
        <th>Role</th>
        <th>View</th>
        <th>Create</th>
        <th>Edit</th>
        <th>Submit Final</th>
        <th>Manage Matrix</th>
        <th>Non-Editor Override</th>
      </tr>
    </thead>
    <tbody>
    <?php if (!$rows): ?>
      <tr><td colspan="7">No permission rows found for this form.</td></tr>
    <?php else: ?>
      <?php foreach ($rows as $row): ?>
      <tr>
        <td><?= e((string)$row['role_code']) ?></td>
        <td><?= !empty($row['can_view']) ? 'Yes' : 'No' ?></td>
        <td><?= !empty($row['can_create']) ? 'Yes' : 'No' ?></td>
        <td><?= !empty($row['can_edit']) ? 'Yes' : 'No' ?></td>
        <td><?= !empty($row['can_submit_final']) ? 'Yes' : 'No' ?></td>
        <td><?= !empty($row['can_manage_permissions']) ? 'Yes' : 'No' ?></td>
        <td><?= e((string)($row['non_editor_mode_override'] ?: 'Use default')) ?></td>
      </tr>
      <?php endforeach; ?>
    <?php endif; ?>
    </tbody>
  </table>
</div>

<div class="card">
  <h2>Add / Update Permission Row</h2>
  <form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="form_code" value="<?= e($formCode) ?>">
    <div class="grid">
      <div>
        <label>Role</label>
        <select name="role_code" required>
          <?php foreach ($roleOptions as $code => $label): ?>
            <option value="<?= e($code) ?>"><?= e($label) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label>Non-Editor Override</label>
        <select name="non_editor_mode_override">
          <option value="">Use form default</option>
          <option value="read_only">Read-only</option>
          <option value="print_preview">Print Preview</option>
          <option value="hidden">Hidden</option>
        </select>
      </div>
      <div class="checkbox-cell"><label class="checkbox-row"><input type="checkbox" name="can_view" value="1"> Can view</label></div>
      <div class="checkbox-cell"><label class="checkbox-row"><input type="checkbox" name="can_create" value="1"> Can create</label></div>
      <div class="checkbox-cell"><label class="checkbox-row"><input type="checkbox" name="can_edit" value="1"> Can edit</label></div>
      <div class="checkbox-cell"><label class="checkbox-row"><input type="checkbox" name="can_submit_final" value="1"> Can submit final</label></div>
      <div class="checkbox-cell"><label class="checkbox-row"><input type="checkbox" name="can_manage_permissions" value="1"> Can manage permissions</label></div>
    </div>
    <p><button class="btn" type="submit" name="save_permission" value="1">Save Permission Row</button></p>
  </form>
</div>
<?php render_footer(); ?>
