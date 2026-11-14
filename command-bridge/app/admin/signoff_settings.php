<?php
declare(strict_types=1);
require_once __DIR__ . '/settings_helpers.php';
require_once dirname(__DIR__) . '/includes/signoff.php';
require_csrf_for_post();

$defaults = signoff_default_approver_map();
$positions = [];
try {
    $positions = db()->query("SELECT acronym, full_title, group_name FROM incident_position_catalog WHERE is_active = 1
        ORDER BY COALESCE(group_sort, 9999), COALESCE(sort_order, 9999), full_title")->fetchAll();
} catch (Throwable $e) { $positions = []; }
$valid = [];
foreach ($positions as $p) { $valid[strtoupper((string)$p['acronym'])] = true; }
$valid['IC'] = $valid['UC'] = true;

$formNames = [
    'ICS201' => 'ICS 201 Incident Briefing', 'ICS203' => 'ICS 203 Organization Assignment List', 'ICS205' => 'ICS 205 Incident Radio Communications Plan',
    'ICS205A' => 'ICS 205A Communications List', 'ICS206' => 'ICS 206 Medical Plan', 'ICS207' => 'ICS 207 Incident Organization Chart',
    'ICS208' => 'ICS 208 Safety Message / Plan', 'ICS209' => 'ICS 209 Incident Status Summary', 'ICS209WF' => 'ICS 209-WF Wildfire Status Summary',
    'ICS210' => 'ICS 210 Resource Status Change', 'ICS211' => 'ICS 211 Check-In List', 'ICS213' => 'ICS 213 General Message',
    'ICS214' => 'ICS 214 Activity Log', 'ICS215A' => 'ICS 215A Incident Action Plan Safety Analysis', 'ICS218' => 'ICS 218 Support Vehicle / Equipment Inventory',
    'ICS230' => 'ICS 230 Daily Meeting Schedule',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $uid = (int)(current_user()['id'] ?? 0);
    if (($_POST['action'] ?? '') === 'reset') {
        save_app_setting('signoff_approver_map', '');
        audit_log($uid, 'signoff_settings', 'app_settings', null, 'Sign-off approvers reset to defaults');
        flash('success', 'Sign-off approvers reset to the defaults.');
    } else {
        $new = []; $changes = [];
        $old = signoff_approver_map();
        foreach ($defaults as $form => $_) {
            $pri = strtoupper(trim((string)($_POST['primary'][$form] ?? '')));
            if ($pri === '@SUPERVISOR') { $pri = '@supervisor'; }
            $fb = strtoupper(trim((string)($_POST['fallback'][$form] ?? '')));
            if ($pri !== '@supervisor' && !isset($valid[$pri])) { $pri = $defaults[$form]['primary']; }
            if ($fb !== '' && !isset($valid[$fb])) { $fb = ''; }
            $new[$form] = ['primary' => $pri, 'fallback' => $fb];
            if (($old[$form]['primary'] ?? '') !== $pri || ($old[$form]['fallback'] ?? '') !== $fb) {
                $changes[] = $form . ': ' . $pri . ($fb !== '' ? ' (fallback ' . $fb . ')' : '');
            }
        }
        save_app_setting('signoff_approver_map', json_encode($new, JSON_UNESCAPED_UNICODE));
        audit_log($uid, 'signoff_settings', 'app_settings', null, $changes ? 'Sign-off approvers changed: ' . implode('; ', $changes) : 'Sign-off approvers saved (no changes)');
        flash('success', $changes ? 'Sign-off approvers saved.' : 'No changes to save.');
    }
    header('Location: ' . app_url('/admin/signoff_settings.php'));
    exit;
}

$map = signoff_approver_map();
$opt = static function (string $selected, bool $supervisor, bool $blank) use ($positions): string {
    $h = '';
    if ($blank) { $h .= '<option value=""' . ($selected === '' ? ' selected' : '') . '>(none - go up the chain of command)</option>'; }
    if ($supervisor) { $h .= '<option value="@supervisor"' . ($selected === '@supervisor' ? ' selected' : '') . '>Signer&rsquo;s own supervisor</option>'; }
    foreach ($positions as $p) {
        $a = (string)$p['acronym'];
        $h .= '<option value="' . e($a) . '"' . (strtoupper($a) === $selected ? ' selected' : '') . '>' . e($a . ' - ' . $p['full_title']) . '</option>';
    }
    return $h;
};
render_header('Sign-off Approvers');
?>
<div class="card">
<h2>Sign-off Approvers</h2>
<p>When a form is signed, the system picks the approver from this list, whoever signed it. If nobody holds the position it moves up the chain of command (then IC, UC, and a SysAdmin). If the signer holds the approving position, it moves up one level. The signer can still change the suggested approver. Changes apply immediately and are written to the audit log. ICS 202 and ICS 204 keep their own approval steps; ICS 213RR keeps its own workflow.</p>
<form method="post">
<?= csrf_field() ?>
<table class="table">
<thead><tr><th>Form</th><th>Approved by</th><th>Fallback if vacant</th></tr></thead>
<tbody>
<?php foreach ($defaults as $form => $_): ?>
<tr>
<td><?= e($formNames[$form] ?? $form) ?></td>
<td><select name="primary[<?= e($form) ?>]"><?= $opt((string)$map[$form]['primary'], true, false) ?></select></td>
<td><select name="fallback[<?= e($form) ?>]"><?= $opt((string)$map[$form]['fallback'], false, true) ?></select></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
<p><button class="btn btn-primary" type="submit" name="action" value="save">Save</button>
<button class="btn btn-outline-secondary" type="submit" name="action" value="reset" onclick="return confirm('Reset every form to the default approver?');">Reset to defaults</button></p>
</form>
</div>
<?php render_footer(); ?>
