<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/sync.php';

/* Retained for compatibility with the existing systemd timer. This command
 * performs no outbound network activity. Command Bridge polls the gateway. */
try {
    $result = ['requeued' => gateway_requeue_stale_outbound_events()];
    echo json_encode($result, JSON_UNESCAPED_SLASHES) . PHP_EOL;
} catch (Throwable $e) {
    gateway_log('outbound_lease_recovery_failure', ['error' => $e->getMessage()]);
    fwrite(STDERR, "Outbound lease recovery error\n");
    exit(1);
}
