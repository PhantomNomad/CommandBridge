<?php
declare(strict_types=1);

require_once dirname(__DIR__, 4) . '/app/bootstrap.php';
require_once dirname(__DIR__, 4) . '/app/sync.php';

$request = gateway_validate_control_request(['events.ack']);
$messageId = $request['message_id'] ?? null;
$outcome = $request['outcome'] ?? null;
$error = trim((string) ($request['error'] ?? ''));

if (!is_string($messageId) || !preg_match('/^[a-f0-9]{32,128}$/i', $messageId)
    || !is_string($outcome) || !in_array($outcome, ['processed', 'retry', 'rejected'], true)
    || strlen($error) > 1000) {
    gateway_json(422, ['ok' => false, 'error' => 'Invalid acknowledgement payload.']);
}

try {
    $result = gateway_acknowledge_pulled_event(gateway_db(), strtolower($messageId), $outcome, $error);
    if (!$result['found']) {
        gateway_json(404, ['ok' => false, 'error' => 'Queued event was not found.']);
    }

    gateway_json(200, [
        'ok' => true,
        'message_id' => strtolower($messageId),
        'already_delivered' => $result['already_delivered'],
    ]);
} catch (Throwable $exception) {
    gateway_log('error', 'Unable to acknowledge pulled check-in event.', ['error' => $exception->getMessage()]);
    gateway_json(500, ['ok' => false, 'error' => 'Unable to acknowledge queued event.']);
}
