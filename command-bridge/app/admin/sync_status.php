<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/security.php';
require_csrf_for_post();

require_once dirname(__DIR__) . '/includes/layout.php';
require_once dirname(__DIR__) . '/includes/external_checkin_status.php';
require_login();
require_user_management_access();

$pdo = db();
$isTsc = !empty(current_user()['is_tsc_admin']);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'retry_failed') {
    if (!$isTsc) {
        http_response_code(403);
        echo 'Forbidden';
        exit;
    }
    $n = external_checkin_requeue_stuck($pdo);
    unset($_SESSION['ec_sync_status']);
    try {
        audit_log((int)(current_user()['id'] ?? 0), 'external_checkin_sync_retry', 'external_checkin_sync', null, $n . ' stuck sync item(s) re-queued');
    } catch (Throwable $e) {
    }
    flash('success', $n . ' stuck item(s) re-queued. They will be sent on the next worker run (within a minute).');
    header('Location: ' . app_url('/admin/sync_status.php'));
    exit;
}

unset($_SESSION['ec_sync_status']);
$status = external_checkin_sync_status($pdo);
$hb = $status['heartbeat'];
$fmt = static fn(int $t): string => $t > 0 ? gmdate('Y-m-d H:i:s', $t) . ' UTC (' . external_checkin_age_text(max(0, time() - $t)) . ' ago)' : 'never';
$labels = ['ok' => 'Healthy', 'warn' => 'Warning', 'fail' => 'Problem', 'off' => 'Not configured'];

render_header('Check-in Sync Status');
?>
<div class="card">
  <h2>External check-in sync: <?= e($labels[$status['level']] ?? $status['level']) ?></h2>
  <?php if ($status['level'] === 'off'): ?>
    <p>The external check-in gateway is not configured on this server, so there is nothing to monitor.</p>
  <?php else: ?>
    <?php if (!$status['problems']): ?>
      <p>Everything looks fine. The worker is running and nothing is stuck.</p>
    <?php else: ?>
      <ul>
        <?php foreach ($status['problems'] as $p): ?>
          <li><strong><?= $p['level'] === 'fail' ? 'Problem' : 'Warning' ?>:</strong> <?= e($p['text']) ?></li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>

    <table class="table">
      <tr><th>Worker last ran</th><td><?= e($fmt((int)($hb['run_at'] ?? 0))) ?></td></tr>
      <tr><th>Last successful sync</th><td><?= e($fmt((int)($hb['success_at'] ?? 0))) ?></td></tr>
      <tr><th>Updates waiting to send</th><td><?= (int)$status['queue']['waiting'] ?></td></tr>
      <tr><th>Updates failing and retrying</th><td><?= (int)$status['queue']['retrying'] ?></td></tr>
      <tr><th>Updates stuck or failed permanently</th><td><?= (int)$status['queue']['failed'] ?></td></tr>
      <tr><th>Received check-ins not yet processed</th><td><?= (int)$status['receipts']['stuck'] ?></td></tr>
      <tr><th>Check-ins rejected (last 24 h)</th><td><?= (int)$status['receipts']['rejected_24h'] ?></td></tr>
      <?php if ($status['queue']['last_error'] !== ''): ?>
      <tr><th>Latest error</th><td><?= e($status['queue']['last_error']) ?><?= $status['queue']['last_http_status'] ? ' (HTTP ' . (int)$status['queue']['last_http_status'] . ')' : '' ?></td></tr>
      <?php endif; ?>
    </table>

    <?php if ($isTsc && (int)$status['queue']['failed'] > 0): ?>
      <form method="post" style="margin-top:12px">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="retry_failed">
        <button class="btn" type="submit">Retry failed items</button>
      </form>
    <?php endif; ?>
    <p class="muted" style="margin-top:12px">The worker runs every minute. This page refreshes its figures each time it is opened.</p>
  <?php endif; ?>
</div>
<?php render_footer(); ?>
