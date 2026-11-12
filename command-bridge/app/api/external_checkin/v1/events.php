<?php
declare(strict_types=1);
// This endpoint belongs to the public gateway application and does nothing on the main CommandBridge server.
$gatewaySync = dirname(__DIR__, 4) . '/app/sync.php';
if (!is_file($gatewaySync)) {
    http_response_code(404);
    header('Content-Type: application/json');
    echo '{"ok":false,"error":"not_found"}';
    exit;
}
require_once $gatewaySync;
try {
    $request = gateway_validate_internal_request();
    $result = gateway_apply_sync_event($request);
    gateway_json(202, ['ok'=>true,'accepted'=>true,'duplicate'=>$result['duplicate']]);
} catch (InvalidArgumentException $e) {
    gateway_log('invalid_sync_event',['error'=>$e->getMessage()]);
    gateway_json(422,['ok'=>false,'error'=>'invalid_event']);
} catch (Throwable $e) {
    gateway_log('sync_failure',['error'=>$e->getMessage()]);
    gateway_json(500,['ok'=>false,'error'=>'internal_error']);
}
