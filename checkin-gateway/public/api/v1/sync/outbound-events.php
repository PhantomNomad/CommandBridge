<?php
declare(strict_types=1);
require_once dirname(__DIR__, 4) . '/app/sync.php';

try {
    $request = gateway_validate_commandbridge_poll_request();
    if ($request['action'] === 'poll') {
        $event = gateway_reserve_outbound_event_for_poll();
        gateway_json(200, ['ok'=>true, 'event'=>$event]);
    }
    $acknowledged = gateway_acknowledge_outbound_event((string)$request['message_id']);
    gateway_json($acknowledged ? 200 : 404, ['ok'=>$acknowledged, 'acknowledged'=>$acknowledged]);
} catch (InvalidArgumentException $e) {
    gateway_log('invalid_poll_request', ['error'=>$e->getMessage()]);
    gateway_json(422, ['ok'=>false, 'error'=>'invalid_request']);
} catch (Throwable $e) {
    gateway_log('outbound_poll_failure', ['error'=>$e->getMessage()]);
    gateway_json(500, ['ok'=>false, 'error'=>'internal_error']);
}
