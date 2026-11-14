<?php
declare(strict_types=1);
/*
 * Dashboard panels per position (includes/dashboard_panels.php). Saved in app_settings.dashboard_panel_map.
 */
require_once __DIR__ . '/settings_helpers.php';
require_once dirname(__DIR__) . '/includes/dashboard_panels.php';
require_csrf_for_post();

$catalogue = cb_dashboard_panel_catalogue();
$positions = [];
try {
    $positions = db()->query("SELECT acronym, full_title, group_name FROM incident_position_catalog WHERE is_active = 1
        ORDER BY COALESCE(group_sort, 9999), COALESCE(sort_order, 9999), full_title")->fetchAll();
} catch (Throwable $e) { $positions = []; }
$rows = [CB_DASHBOARD_EVERYONE => ['title' => 'Everyone', 'group' => 'Shown to every signed-in user, whatever their position']];
foreach ($positions as $p) {
    $a = strtoupper((string)$p['acronym']);
    if ($a === '' || $a === 'SYSADMIN') { continue; }
    $rows[$a] = ['title' => (string)$p['full_title'], 'group' => (string)($p['group_name'] ?? '')];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $uid = (int)(current_user()['id'] ?? 0);
    $old = cb_dashboard_panel_map();
    if (($_POST['action'] ?? '') === 'reset') {
        save_app_setting('dashboard_panel_map', json_encode(cb_dashboard_default_panel_map(), JSON_UNESCAPED_UNICODE));
        audit_log($uid, 'dashboard_settings', 'app_settings', null, 'Dashboard panels reset to the defaults');
        flash('success', 'Dashboard panels reset to the defaults.');
    } else {
        $new = [];
        $changes = [];
        $posted = (array)($_POST['panels'] ?? []);
        foreach ($rows as $role => $_) {
            $chosen = array_values(array_filter(array_keys($catalogue), static fn($k) => !empty($posted[$role][$k])));
            if ($chosen || $role === CB_DASHBOARD_EVERYONE) { $new[$role] = $chosen; }
            $before = $old[$role] ?? [];
            sort($before); $after = $chosen; sort($after);
            if ($before !== $after) { $changes[] = ($role === CB_DASHBOARD_EVERYONE ? 'Everyone' : $role) . ': ' . ($chosen ? implode(', ', $chosen) : 'none'); }
        }
        save_app_setting('dashboard_panel_map', json_encode($new, JSON_UNESCAPED_UNICODE));
        audit_log($uid, 'dashboard_settings', 'app_settings', null,
            $changes ? 'Dashboard panels changed: ' . mb_substr(implode('; ', $changes), 0, 1500) : 'Dashboard panels saved (no changes)');
        flash('success', $changes ? 'Dashboard panels saved.' : 'No changes to save.');
    }
    header('Location: ' . app_url('/admin/dashboard_settings.php'));
    exit;
}

$map = cb_dashboard_panel_map();
render_header('Dashboard Panels');
?>
<div class="card">
<h2>Dashboard Panels</h2>
<p>Choose which panels each position sees on its dashboard. People see the panels ticked for <strong>Everyone</strong>
plus the panels of every position they hold, so someone covering two positions gets both sets. SysAdmins see every panel.
Anyone can still press <strong>Show everything</strong> on the dashboard. This only decides what is shown first; what
people may open is still set in <a href="<?= e(app_url('/admin/form_permissions.php')) ?>">Form Permissions</a>.
Changes apply at once and are written to the audit log.</p>
<details class="small"><summary>What each panel shows</summary>
<ul><?php foreach ($catalogue as $key => $p): ?><li><strong><?= e($p['title']) ?>:</strong> <?= e($p['help']) ?></li><?php endforeach; ?></ul>
</details>
<form method="post">
<?= csrf_field() ?>
<div class="table-scroll">
<table class="table dashboard-panel-matrix">
<thead><tr><th>Position</th><?php foreach ($catalogue as $key => $p): ?><th title="<?= e($p['help']) ?>"><?= e($p['title']) ?></th><?php endforeach; ?></tr></thead>
<tbody>
<?php foreach ($rows as $role => $r): $have = $map[$role] ?? []; ?>
<tr<?= $role === CB_DASHBOARD_EVERYONE ? ' class="dashboard-panel-everyone"' : '' ?>>
<th scope="row"><?= e($role === CB_DASHBOARD_EVERYONE ? 'Everyone' : $role . ' - ' . $r['title']) ?><?php if ($r['group'] !== ''): ?><br><span class="small"><?= e($r['group']) ?></span><?php endif; ?></th>
<?php foreach ($catalogue as $key => $p): ?>
<td style="text-align:center"><input type="checkbox" name="panels[<?= e($role) ?>][<?= e($key) ?>]" value="1" aria-label="<?= e(($role === CB_DASHBOARD_EVERYONE ? 'Everyone' : $role) . ': ' . $p['title']) ?>"<?= in_array($key, $have, true) ? ' checked' : '' ?>></td>
<?php endforeach; ?>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
<p><button class="btn btn-primary" type="submit" name="action" value="save">Save</button>
<button class="btn btn-outline-secondary" type="submit" name="action" value="reset" onclick="return confirm('Reset every position to the default dashboard panels?');">Reset to defaults</button></p>
</form>
</div>
<?php render_footer(); ?>
