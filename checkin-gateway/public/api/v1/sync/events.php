<?php
declare(strict_types=1);
require_once dirname(__DIR__, 4) . '/app/sync.php';
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
