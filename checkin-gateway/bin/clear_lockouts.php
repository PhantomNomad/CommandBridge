#!/usr/bin/env php
<?php
declare(strict_types=1);

/*
 * Show or clear PIN sign-in lockouts on the Check-In Gateway, so people who
 * were paused by the throttle can sign in again immediately instead of waiting.
 *
 *   php bin/clear_lockouts.php --list
 *   php bin/clear_lockouts.php --all
 *   php bin/clear_lockouts.php --location=ID
 *   php bin/clear_lockouts.php --ip=ADDRESS
 *   php bin/clear_lockouts.php --location=ID --ip=ADDRESS
 *
 * Run as root or a user that can read /etc/commandbridge/checkin_gateway.env:
 *   sudo php /var/www/commandbridge-gateway/bin/clear_lockouts.php --list
 *
 * Clearing removes failure records only. It does not change who is authorized,
 * and the action is written to the gateway audit log.
 *
 * A forgotten or compromised PIN is fixed in Command Bridge (Users > Reset PIN);
 * the new PIN reaches the gateway on the next synchronization.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only.\n");
}

require_once dirname(__DIR__) . '/app/bootstrap.php';

$list = false;
$all = false;
$locationId = null;
$ip = null;
foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--list') {
        $list = true;
    } elseif ($arg === '--all') {
        $all = true;
    } elseif (preg_match('/^--location=(\d+)$/', $arg, $m)) {
        $locationId = (int)$m[1];
    } elseif (preg_match('/^--ip=([0-9A-Fa-f:.]{3,45})$/', $arg, $m)) {
        $ip = $m[1];
    } else {
        fwrite(STDERR, "Unknown or invalid option: {$arg}\n");
        exit(2);
    }
}
if (!$list && !$all && $locationId === null && $ip === null) {
    fwrite(STDERR, "Usage: php bin/clear_lockouts.php --list | --all | --location=ID | --ip=ADDRESS\n");
    exit(2);
}

try {
    $pdo = gateway_db();

    if ($list) {
        $stmt = $pdo->query(
            'SELECT a.location_id, l.location_name, a.remote_ip, COUNT(*) AS failures, MAX(a.attempted_at) AS last_failure
               FROM gateway_access_attempts a
               LEFT JOIN gateway_locations l ON l.id = a.location_id
              WHERE a.attempted_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)
              GROUP BY a.location_id, l.location_name, a.remote_ip
              ORDER BY last_failure DESC'
        );
        $rows = $stmt->fetchAll();
        if (!$rows) {
            echo "No failed PIN attempts in the last 24 hours.\n";
            exit(0);
        }
        printf("%-4s %-28s %-40s %-9s %-20s %s\n", 'LOC', 'LOCATION', 'ADDRESS', 'FAILURES', 'LAST FAILURE', 'STATUS');
        $locationsSeen = [];
        foreach ($rows as $row) {
            $status = gateway_throttle_check($pdo, (int)$row['location_id'], (string)$row['remote_ip'], '');
            $text = $status['blocked']
                ? 'BLOCKED ' . gateway_throttle_wait_text($status['retry_after']) . ' (' . $status['reason'] . ')'
                : 'ok';
            printf("%-4d %-28s %-40s %-9d %-20s %s\n",
                (int)$row['location_id'], mb_substr((string)$row['location_name'], 0, 28),
                (string)$row['remote_ip'], (int)$row['failures'], (string)$row['last_failure'], $text);
        }
        exit(0);
    }

    $removed = gateway_throttle_clear($pdo, $locationId, $ip);

    $details = json_encode([
        'removed' => $removed,
        'location_id' => $locationId,
        'remote_ip' => $ip,
        'source' => 'clear_lockouts.php',
    ], JSON_UNESCAPED_SLASHES);
    $stmt = $pdo->prepare(
        'INSERT INTO gateway_audit_log (location_id, action, actor_name, remote_ip, details_json)
         VALUES (?, ?, ?, NULL, ?)'
    );
    $stmt->execute([$locationId, 'lockouts_cleared', 'server console', $details]);

    echo "Cleared {$removed} failed-attempt record(s). Sign-in is available again.\n";
} catch (Throwable $e) {
    gateway_log('clear_lockouts_failure', ['error' => $e->getMessage()]);
    fwrite(STDERR, 'clear_lockouts failed: ' . $e->getMessage() . "\n");
    exit(1);
}
