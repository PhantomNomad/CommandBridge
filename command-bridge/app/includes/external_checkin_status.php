<?php
declare(strict_types=1);

/**
 * Health of the external check-in synchronization (main app <-> public gateway).
 *
 * The worker (bin/external_checkin_sync_worker.php, run every minute by the systemd timer) writes a heartbeat into
 * app_settings after every run. external_checkin_sync_status() combines that heartbeat with the queue and receipt
 * tables and returns a level: off (sync not configured), ok, warn or fail, plus plain-language problems.
 */

const EXTERNAL_CHECKIN_HEARTBEAT_KEY = 'external_checkin_sync_heartbeat';
const EXTERNAL_CHECKIN_STALE_SECONDS = 300;   // the timer runs every minute; 5 minutes of silence is a problem

/** @param array<string,mixed> $info run_at, success, error, outbound[], inbound[] */
function external_checkin_record_heartbeat(array $info): void
{
    try {
        $prev = external_checkin_read_heartbeat();
        $now = time();
        $beat = [
            'run_at' => $now,
            'success_at' => !empty($info['success']) ? $now : (int)($prev['success_at'] ?? 0),
            'error' => !empty($info['success']) ? '' : substr((string)($info['error'] ?? ''), 0, 500),
            'error_at' => !empty($info['success']) ? (int)($prev['error_at'] ?? 0) : $now,
            'outbound' => $info['outbound'] ?? [],
            'inbound' => $info['inbound'] ?? [],
        ];
        // Direct PDO: the CLI worker does not load auth.php, where save_app_setting() lives.
        require_once __DIR__ . '/db.php';
        db()->prepare('INSERT INTO app_settings (setting_key, setting_value, updated_at) VALUES (?, ?, NOW())
                       ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW()')
            ->execute([EXTERNAL_CHECKIN_HEARTBEAT_KEY, json_encode($beat, JSON_UNESCAPED_SLASHES)]);
    } catch (Throwable $e) {
        security_log($e, 'external_checkin_heartbeat');
    }
}

function external_checkin_read_heartbeat(): array
{
    try {
        require_once __DIR__ . '/db.php';
        $st = db()->prepare('SELECT setting_value FROM app_settings WHERE setting_key = ? LIMIT 1');
        $st->execute([EXTERNAL_CHECKIN_HEARTBEAT_KEY]);
        $raw = $st->fetchColumn();
        $data = $raw ? json_decode((string)$raw, true) : null;
        return is_array($data) ? $data : [];
    } catch (Throwable $e) {
        return [];
    }
}

function external_checkin_age_text(int $seconds): string
{
    if ($seconds < 90) { return $seconds . ' seconds'; }
    if ($seconds < 5400) { return (int)round($seconds / 60) . ' minutes'; }
    if ($seconds < 172800) { return round($seconds / 3600, 1) . ' hours'; }
    return (int)round($seconds / 86400) . ' days';
}

/** @return array<string,mixed> */
function external_checkin_sync_status(PDO $pdo): array
{
    require_once __DIR__ . '/external_checkin_sync.php';
    $s = ['configured' => false, 'level' => 'off', 'problems' => [], 'heartbeat' => [], 'queue' => [
        'waiting' => 0, 'oldest_waiting_seconds' => 0, 'retrying' => 0, 'failed' => 0, 'last_error' => '', 'last_http_status' => null],
        'receipts' => ['stuck' => 0, 'rejected_24h' => 0]];

    try {
        $s['configured'] = external_checkin_sync_is_configured();
    } catch (Throwable $e) { return $s; }
    if (!$s['configured']) { return $s; }

    $level = 'ok';
    $raise = function (string $to, string $msg) use (&$level, &$s) {
        $rank = ['ok' => 0, 'warn' => 1, 'fail' => 2];
        if ($rank[$to] > $rank[$level]) { $level = $to; }
        $s['problems'][] = ['level' => $to, 'text' => $msg];
    };

    // 1. Worker heartbeat
    $hb = external_checkin_read_heartbeat();
    $s['heartbeat'] = $hb;
    $now = time();
    $runAt = (int)($hb['run_at'] ?? 0);
    $successAt = (int)($hb['success_at'] ?? 0);
    if ($runAt === 0) {
        $raise('fail', 'The sync worker has never reported in. Check that commandbridge-external-checkin-sync.timer is enabled on the server.');
    } elseif ($now - $runAt > EXTERNAL_CHECKIN_STALE_SECONDS) {
        $raise('fail', 'The sync worker has not run for ' . external_checkin_age_text($now - $runAt) . '. Check the commandbridge-external-checkin-sync timer.');
    }
    if ($runAt > 0 && !empty($hb['error']) && ($now - $successAt) > EXTERNAL_CHECKIN_STALE_SECONDS) {
        $since = $successAt > 0 ? external_checkin_age_text($now - $successAt) . ' ago' : 'never';
        $raise('fail', 'The gateway cannot be reached or is refusing requests (last good sync: ' . $since . '). Last error: ' . $hb['error']);
    }

    // 2. Outbound queue
    try {
        $row = $pdo->query("SELECT
                SUM(delivery_status IN ('pending','failed') AND next_attempt_at IS NOT NULL AND next_attempt_at <= NOW() AND attempt_count < max_attempts) AS waiting,
                MAX(CASE WHEN delivery_status IN ('pending','failed') AND next_attempt_at IS NOT NULL AND next_attempt_at <= NOW() AND attempt_count < max_attempts
                         THEN TIMESTAMPDIFF(SECOND, updated_at, NOW()) END) AS oldest,
                SUM(delivery_status = 'failed' AND next_attempt_at IS NOT NULL AND attempt_count < max_attempts) AS retrying,
                SUM(delivery_status IN ('pending','failed') AND (next_attempt_at IS NULL OR attempt_count >= max_attempts)) AS dead
            FROM external_checkin_sync_queue")->fetch() ?: [];
        $q = &$s['queue'];
        $q['waiting'] = (int)($row['waiting'] ?? 0);
        $q['oldest_waiting_seconds'] = (int)($row['oldest'] ?? 0);
        $q['retrying'] = (int)($row['retrying'] ?? 0);
        $q['failed'] = (int)($row['dead'] ?? 0);
        $last = $pdo->query("SELECT last_error, last_http_status FROM external_checkin_sync_queue
                             WHERE delivery_status = 'failed' ORDER BY updated_at DESC, id DESC LIMIT 1")->fetch();
        if ($last) { $q['last_error'] = (string)($last['last_error'] ?? ''); $q['last_http_status'] = $last['last_http_status']; }
        unset($q);

        if ($s['queue']['failed'] > 0) {
            $raise('fail', $s['queue']['failed'] . ' update(s) to the gateway failed permanently and need attention.'
                . ($s['queue']['last_error'] !== '' ? ' Last error: ' . $s['queue']['last_error'] : ''));
        }
        if ($s['queue']['retrying'] > 0) {
            $raise('warn', $s['queue']['retrying'] . ' update(s) to the gateway are failing and being retried.'
                . ($s['queue']['last_error'] !== '' ? ' Last error: ' . $s['queue']['last_error'] : ''));
        }
        if ($s['queue']['waiting'] > 0 && $s['queue']['oldest_waiting_seconds'] > EXTERNAL_CHECKIN_STALE_SECONDS) {
            $raise('warn', $s['queue']['waiting'] . ' update(s) have been waiting to send for ' . external_checkin_age_text($s['queue']['oldest_waiting_seconds']) . '.');
        }
    } catch (Throwable $e) { /* tables not installed: nothing to report */ }

    // 3. Inbound check-ins
    try {
        $row = $pdo->query("SELECT
                SUM(receipt_status = 'received' AND created_at < DATE_SUB(NOW(), INTERVAL 5 MINUTE)) AS stuck,
                SUM(receipt_status = 'rejected' AND created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)) AS rejected
            FROM external_checkin_api_receipts")->fetch() ?: [];
        $s['receipts']['stuck'] = (int)($row['stuck'] ?? 0);
        $s['receipts']['rejected_24h'] = (int)($row['rejected'] ?? 0);
        if ($s['receipts']['stuck'] > 0) {
            $raise('warn', $s['receipts']['stuck'] . ' check-in(s) received from the gateway are not processed yet.');
        }
        if ($s['receipts']['rejected_24h'] > 0) {
            $raise('warn', $s['receipts']['rejected_24h'] . ' check-in(s) from the gateway were rejected in the last 24 hours.');
        }
    } catch (Throwable $e) {}

    $s['level'] = $level;
    return $s;
}

/** Status for the banner, cached in the session for 20 seconds so ordinary pages stay fast. */
function external_checkin_sync_status_cached(PDO $pdo): array
{
    $c = $_SESSION['ec_sync_status'] ?? null;
    if (is_array($c) && (time() - (int)($c['at'] ?? 0)) < 20) { return $c['status']; }
    $status = external_checkin_sync_status($pdo);
    $_SESSION['ec_sync_status'] = ['at' => time(), 'status' => $status];
    return $status;
}

/** HTML banner shown at the top of every page to people who manage users; empty when sync is healthy or off. */
function external_checkin_sync_banner_html(): string
{
    try {
        $status = external_checkin_sync_status_cached(db());
    } catch (Throwable $e) {
        return '';
    }
    if (!in_array($status['level'], ['warn', 'fail'], true) || empty($status['problems'])) { return ''; }

    $fail = $status['level'] === 'fail';
    $bg = $fail ? '#fdecea' : '#fff4e5';
    $bd = $fail ? '#d93025' : '#e8710a';
    $first = $status['problems'][0]['text'];
    $more = count($status['problems']) - 1;
    return '<div class="no-print" role="alert" style="margin:0 0 14px;padding:10px 14px;border:1px solid ' . $bd . ';border-left-width:6px;background:' . $bg . ';border-radius:4px;">'
        . '<strong>Check-in sync ' . ($fail ? 'problem' : 'warning') . ':</strong> ' . htmlspecialchars($first, ENT_QUOTES, 'UTF-8')
        . ($more > 0 ? ' (+' . $more . ' more)' : '')
        . ' <a href="' . htmlspecialchars(app_url('/admin/sync_status.php'), ENT_QUOTES, 'UTF-8') . '">Details</a></div>';
}


/**
 * Puts stuck outbound items back in the queue: pending with no next attempt, failed with no next attempt, or out of attempts.
 * Returns how many rows were reset. Used by the Check-in Sync Status page ("Retry failed items") and the smoke test.
 */
function external_checkin_requeue_stuck(PDO $pdo): int
{
    $stmt = $pdo->prepare("UPDATE external_checkin_sync_queue
        SET delivery_status = 'pending', attempt_count = 0, next_attempt_at = NOW(), last_error = NULL,
            locked_at = NULL, locked_by = NULL, failed_at = NULL
        WHERE delivery_status IN ('pending','failed') AND (next_attempt_at IS NULL OR attempt_count >= max_attempts)");
    $stmt->execute();
    return $stmt->rowCount();
}
