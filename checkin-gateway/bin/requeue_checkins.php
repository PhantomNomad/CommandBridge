#!/usr/bin/env php
<?php
declare(strict_types=1);

/*
 * List or re-send check-ins that the gateway has stopped offering to Command Bridge.
 *
 * A check-in is "stuck" when Command Bridge collected it the maximum number of times
 * (GATEWAY_MAX_ATTEMPTS, default 12) without confirming it, or when Command Bridge rejected it.
 * Stuck check-ins are never deleted, but they are not sent again until you re-queue them.
 *
 *   php bin/requeue_checkins.php --list
 *   php bin/requeue_checkins.php --id=ID          (one check-in, by the ID shown in --list)
 *   php bin/requeue_checkins.php --all
 *
 * Run as root or a user that can read /etc/commandbridge/checkin_gateway.env:
 *   sudo php /var/www/commandbridge-gateway/bin/requeue_checkins.php --list
 *
 * Re-queuing resets the attempt counter so Command Bridge picks the check-in up on its next poll.
 * Command Bridge ignores check-ins it has already recorded, so re-sending is safe.
 * The action is written to the gateway audit log.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only.\n");
}

require_once dirname(__DIR__) . '/app/bootstrap.php';

const REQUEUE_STUCK_WHERE = "delivery_status <> 'delivered' AND delivery_status <> 'processing'
    AND (next_attempt_at IS NULL OR attempt_count >= max_attempts)";

$list = false;
$all = false;
$id = null;
foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--list') {
        $list = true;
    } elseif ($arg === '--all') {
        $all = true;
    } elseif (preg_match('/^--id=(\d+)$/', $arg, $m)) {
        $id = (int)$m[1];
    } else {
        fwrite(STDERR, "Unknown or invalid option: {$arg}\n");
        exit(2);
    }
}
if ((int)$list + (int)$all + (int)($id !== null) !== 1) {
    fwrite(STDERR, "Usage: php bin/requeue_checkins.php --list | --id=ID | --all\n");
    exit(2);
}

try {
    $pdo = gateway_db();

    if ($list) {
        $rows = $pdo->query(
            'SELECT id, event_type, incident_id, location_id, attempt_count, max_attempts, created_at, last_error
               FROM gateway_outbound_events
              WHERE ' . REQUEUE_STUCK_WHERE . '
              ORDER BY id'
        )->fetchAll();
        if (!$rows) {
            echo "No stuck check-ins. Everything is either delivered or still being sent.\n";
            exit(0);
        }
        printf("%-8s %-20s %-5s %-5s %-8s %-20s %s\n", 'ID', 'TYPE', 'INC', 'LOC', 'TRIES', 'RECEIVED', 'LAST ERROR');
        foreach ($rows as $row) {
            printf("%-8d %-20s %-5d %-5d %-8s %-20s %s\n",
                (int)$row['id'], mb_substr((string)$row['event_type'], 0, 20), (int)$row['incident_id'],
                (int)$row['location_id'], $row['attempt_count'] . '/' . $row['max_attempts'],
                (string)$row['created_at'], mb_substr((string)($row['last_error'] ?? ''), 0, 80));
        }
        echo "\n" . count($rows) . " stuck check-in(s). Re-send with --id=ID or --all.\n";
        exit(0);
    }

    $sql = "UPDATE gateway_outbound_events
               SET delivery_status = 'pending', attempt_count = 0, max_attempts = ?, next_attempt_at = NOW(),
                   failed_at = NULL, locked_at = NULL
             WHERE " . REQUEUE_STUCK_WHERE;
    $params = [gateway_config()['max_attempts']];
    if ($id !== null) {
        $sql .= ' AND id = ?';
        $params[] = $id;
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $count = $stmt->rowCount();

    $pdo->prepare(
        'INSERT INTO gateway_audit_log (action, actor_name, remote_ip, details_json)
         VALUES (?, ?, NULL, ?)'
    )->execute([
        'checkins_requeued',
        'server console',
        json_encode(['requeued' => $count, 'id' => $id, 'source' => 'requeue_checkins.php'], JSON_UNESCAPED_SLASHES),
    ]);

    if ($count === 0) {
        echo $id !== null
            ? "Check-in {$id} is not stuck (it may already be delivered or still being sent). Nothing changed.\n"
            : "No stuck check-ins. Nothing changed.\n";
        exit(0);
    }
    echo "Re-queued {$count} check-in(s). Command Bridge will collect them on its next poll (within a minute).\n";
} catch (Throwable $e) {
    gateway_log('requeue_checkins_failure', ['error' => $e->getMessage()]);
    fwrite(STDERR, 'requeue_checkins failed: ' . $e->getMessage() . "\n");
    exit(1);
}
