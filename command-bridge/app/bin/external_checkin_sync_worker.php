#!/usr/bin/env php
<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only.\n");
}

require_once dirname(__DIR__) . '/includes/external_checkin_sync.php';
require_once dirname(__DIR__) . '/includes/external_checkin_status.php';

$limit = 25;
foreach (array_slice($argv, 1) as $argument) {
    if (preg_match('/^--limit=(\d+)$/', $argument, $matches)) {
        $limit = (int)$matches[1];
    }
}

try {
    // Outbound queue: locations and expected resources are pushed to the
    // public gateway. Inbound queue: check-ins are pulled back over HTTPS.
    $outbound = external_checkin_run_queue_worker($limit);
    $inbound = external_checkin_poll_gateway($limit);

    // Heartbeat for the sync status banner. A run counts as good when the gateway answered and nothing failed to send.
    external_checkin_record_heartbeat([
        'success' => ($outbound['failed'] === 0),
        'error' => $outbound['failed'] > 0 ? $outbound['failed'] . ' update(s) could not be delivered to the gateway.' : '',
        'outbound' => $outbound,
        'inbound' => $inbound,
    ]);

    echo 'outbound_processed=' . $outbound['processed']
        . ' outbound_delivered=' . $outbound['delivered']
        . ' outbound_failed=' . $outbound['failed']
        . ' inbound_received=' . $inbound['received']
        . ' inbound_processed=' . $inbound['processed']
        . ' inbound_rejected=' . $inbound['rejected']
        . ' inbound_failed=' . $inbound['failed'] . PHP_EOL;

    exit(($outbound['failed'] + $inbound['failed']) > 0 ? 1 : 0);
} catch (Throwable $e) {
    security_log($e, 'external_checkin_sync_worker');
    external_checkin_record_heartbeat(['success' => false, 'error' => external_checkin_normalize_error($e->getMessage())]);
    fwrite(STDERR, "External check-in synchronization worker failed.\n");
    exit(1);
}
