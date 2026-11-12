<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/external_checkin_sync.php';
require_once __DIR__ . '/../includes/external_checkin_sync.php';

$limit = 25;
foreach ($argv as $argument) {
    if (str_starts_with($argument, '--limit=')) {
        $limit = (int) substr($argument, 8);
    }
}

echo json_encode(
    external_checkin_process_pending_inbound_receipts($limit),
    JSON_UNESCAPED_SLASHES
) . PHP_EOL;
