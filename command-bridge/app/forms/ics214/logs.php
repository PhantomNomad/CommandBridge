<?php
declare(strict_types=1);
/*
 * ICS 214 Activity Logs: everyone's log for the selected operational period that you are allowed to open
 * (IC/UC and SysAdmins see everyone, supervisors see the people who report to them, the DOCL sees everyone read-only).
 */
require_once __DIR__ . '/../../includes/security.php';
require_once dirname(__DIR__, 2) . '/includes/layout.php';
require_once dirname(__DIR__, 2) . '/includes/ics214_log.php';
require_login();

$incident = active_incident();
$incidentId = (int)($incident['id'] ?? 0);
if ($incidentId <= 0) {
    render_header('ICS 214 - Activity Logs');
    echo '<div class="card"><p>No active incident is available.</p></div>';
    render_footer(); exit;
}
require_active_operational_period($incidentId);
$op = current_operational_period($incidentId);
$opId = (int)($op['id'] ?? 0);
$myId = (int)(current_user()['id'] ?? 0);

if (!ics214_access($incidentId, $myId, $opId)['list']) {
    header('Location: ' . ics214_url());
    exit;
}

$people = ics214_list_people($incidentId, $opId);
$statusLabels = ['not_started' => 'Not started', 'draft' => 'Open', 'rejected' => 'Returned', 'submitted' => 'Signed', 'approved' => 'Approved'];

render_header('ICS 214 - Activity Logs');
?>
<div class="card">
  <h2>ICS 214 - Activity Logs</h2>
  <p>Operational period: <strong><?= e(trim((string)($op['period_name'] ?? ''))) ?></strong>.
    Change the operational period from the top bar to see earlier logs.</p>
  <p class="small">Every person has their own activity log for each operational period. This list shows the logs you are allowed to open.
    <a href="<?= e(ics214_url()) ?>">Open my own log</a></p>
  <table class="table cb-cards">
    <thead><tr><th>Person</th><th>Position(s)</th><th>Status</th><th>Entries</th><th>Last update</th><th></th></tr></thead>
    <tbody>
    <?php if (!$people): ?><tr><td colspan="6" class="muted">No one is assigned to this incident yet.</td></tr><?php endif; ?>
    <?php foreach ($people as $p):
        $st = strtolower((string)$p['status']);
        $url = $p['record_id'] > 0 ? ics214_url(0, 0, (int)$p['record_id']) : ics214_url((int)$p['user_id'], $opId); ?>
      <tr>
        <td><?= e($p['name']) ?><?= $p['user_id'] === $myId ? ' <span class="small">(you)</span>' : '' ?></td>
        <td><?= e($p['positions']) ?></td>
        <td><?= e($statusLabels[$st] ?? ucfirst($st)) ?></td>
        <td><?= (int)$p['entries'] ?></td>
        <td><?= $p['updated_at'] !== '' ? e(date('Y-m-d H:i', (int)strtotime((string)$p['updated_at']))) : '' ?></td>
        <td><a class="btn btn-small" href="<?= e($url) ?>">Open</a></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php render_footer(); ?>
