<?php
declare(strict_types=1);
require_once __DIR__ . '/settings_helpers.php';
$categories = ['Agencies','Jurisdictions','Incident Types','Facilities','Resource Kinds','Resource Types','Positions','Contact Categories'];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf_token();
    $action = $_POST['action'] ?? '';
    if ($action === 'add') {
        $category = trim((string)($_POST['category'] ?? ''));
        $value = trim((string)($_POST['value'] ?? ''));
        if (in_array($category, $categories, true) && $value !== '') {
            $stmt = db()->prepare('INSERT INTO lookup_list_items (list_category, item_value, item_description, is_active, created_at, updated_at) VALUES (?, ?, ?, 1, NOW(), NOW())');
            $stmt->execute([$category, $value, trim((string)($_POST['description'] ?? ''))]);
            audit_log((int)current_user()['id'], 'lookup_added', 'lookup_list', (int)db()->lastInsertId(), $category . ': ' . $value);
            flash('success', 'Lookup item added.');
        } else { flash('error', 'Select a category and enter a value.'); }
    } elseif ($action === 'toggle') {
        $id=(int)($_POST['id']??0); $stmt=db()->prepare('UPDATE lookup_list_items SET is_active = IF(is_active=1,0,1), updated_at=NOW() WHERE id=?'); $stmt->execute([$id]);
        audit_log((int)current_user()['id'], 'lookup_status_changed', 'lookup_list', $id, 'Lookup item enabled/disabled');
        flash('success','Lookup item updated.');
    }
    header('Location: ' . app_url('/admin/lookups.php')); exit;
}
$items = db()->query('SELECT * FROM lookup_list_items ORDER BY list_category, item_value')->fetchAll();
render_header('Lookup Lists'); settings_menu('lookups'); ?>
<div class="card"><h2>Lookup Lists</h2><p>Maintain local operational values. Changes do not alter historical form records.</p>
<form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="add">
<label>List<select name="category"><?php foreach($categories as $c): ?><option><?= e($c) ?></option><?php endforeach ?></select></label>
<label>Value<input name="value" maxlength="150" required></label>
<label>Description (optional)<input name="description" maxlength="255"></label><button class="btn">Add item</button></form></div>
<div class="card"><h3>Configured values</h3><table class="cb-cards"><thead><tr><th>List</th><th>Value</th><th>Description</th><th>Status</th><th></th></tr></thead><tbody>
<?php foreach($items as $item): ?><tr><td><?=e($item['list_category'])?></td><td><?=e($item['item_value'])?></td><td><?=e($item['item_description'])?></td><td><?= $item['is_active']?'Active':'Disabled' ?></td><td><form method="post"><?=csrf_field()?><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?= (int)$item['id'] ?>"><button class="btn btn-secondary"><?= $item['is_active']?'Disable':'Enable' ?></button></form></td></tr><?php endforeach ?></tbody></table></div>
<?php render_footer(); ?>