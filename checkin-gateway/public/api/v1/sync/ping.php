<?php
declare(strict_types=1);

/*
 * Signed connection test for Command Bridge (action "sync.ping").
 *
 * Proves that Command Bridge can reach this gateway over HTTPS, that both sides hold the same key ID and
 * shared secret, and that the two clocks agree (signed requests are only accepted within
 * GATEWAY_REQUEST_TTL_SECONDS). It reads queue counts but never leases, changes or deletes anything,
 * so it is safe to call at any time, including during an incident.
 */

require_once dirname(__DIR__, 4) . '/app/bootstrap.php';
require_once dirname(__DIR__, 4) . '/app/sync.php';

gateway_validate_control_request(['sync.ping']);
$c = gateway_config();

try {
    $row = gateway_db()->query(
        "SELECT
            SUM(delivery_status <> 'delivered' AND next_attempt_at IS NOT NULL AND attempt_count < max_attempts) AS waiting,
            SUM(delivery_status <> 'delivered' AND delivery_status <> 'processing'
                AND (next_attempt_at IS NULL OR attempt_count >= max_attempts)) AS stuck
         FROM gateway_outbound_events"
    )->fetch();
} catch (Throwable $exception) {
    gateway_log('ping_database_error', ['error' => $exception->getMessage()]);
    gateway_json(500, ['ok' => false, 'error' => 'database_unavailable']);
}

gateway_json(200, [
    'ok' => true,
    'gateway_version' => GATEWAY_VERSION,
    'server_time' => time(),
    'delivery_mode' => $c['delivery_mode'],
    'checkins_waiting' => (int)($row['waiting'] ?? 0),
    'checkins_stuck' => (int)($row['stuck'] ?? 0),
]);
