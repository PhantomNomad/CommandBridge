<?php
declare(strict_types=1);

require_once dirname(__DIR__, 4) . '/app/bootstrap.php';
require_once dirname(__DIR__, 4) . '/app/sync.php';

$request = gateway_validate_control_request(['events.pull']);
$limit = isset($request['limit']) ? (int) $request['limit'] : 25;

if ($limit < 1 || $limit > 100) {
    gateway_json(422, ['ok' => false, 'error' => 'limit must be between 1 and 100.']);
}

try {
    $events = gateway_pull_events(gateway_db(), $limit);
    gateway_json(200, [
        'ok' => true,
        'events' => $events,
        'lease_seconds' => gateway_config()['pull_lease_seconds'],
    ]);
} catch (Throwable $exception) {
    gateway_log('error', 'Unable to retrieve queued check-in events.', ['error' => $exception->getMessage()]);
    gateway_json(500, ['ok' => false, 'error' => 'Unable to retrieve queued events.']);
}
