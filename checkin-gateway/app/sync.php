<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';

function gateway_signature(string $secret, string $timestamp, string $nonce, string $body): string {
    return base64_encode(hash_hmac('sha256', $timestamp . "\n" . $nonce . "\n" . $body, $secret, true));
}
function gateway_sync_ready(): bool {
    $c = gateway_config();
    return $c['sync_enabled'] && $c['hmac_key_id'] !== '' && strlen($c['hmac_secret']) >= 32;
}
function gateway_validate_internal_request(): array {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') gateway_json(405, ['ok'=>false,'error'=>'method_not_allowed']);
    if (!gateway_sync_ready()) gateway_json(503, ['ok'=>false,'error'=>'service_unavailable']);
    if (!str_starts_with(strtolower((string)($_SERVER['CONTENT_TYPE'] ?? '')), 'application/json')) gateway_json(415, ['ok'=>false,'error'=>'json_required']);
    $length = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
    if ($length <= 0 || $length > GATEWAY_MAX_REQUEST_BYTES) gateway_json(413, ['ok'=>false,'error'=>'payload_too_large']);
    $timestamp = trim((string)($_SERVER['HTTP_X_COMMANDBRIDGE_TIMESTAMP'] ?? ''));
    $nonce = trim((string)($_SERVER['HTTP_X_COMMANDBRIDGE_NONCE'] ?? ''));
    $keyId = trim((string)($_SERVER['HTTP_X_COMMANDBRIDGE_KEY_ID'] ?? ''));
    $signature = trim((string)($_SERVER['HTTP_X_COMMANDBRIDGE_SIGNATURE'] ?? ''));
    $c = gateway_config();
    if (!preg_match('/^\d{10}$/', $timestamp) || !preg_match('/^[A-Za-z0-9._:-]{16,128}$/', $nonce)
        || $keyId === '' || !hash_equals($c['hmac_key_id'], $keyId) || abs(time() - (int)$timestamp) > $c['request_ttl_seconds']) {
        gateway_json(401, ['ok'=>false,'error'=>'invalid_authentication']);
    }
    $body = file_get_contents('php://input');
    if (!is_string($body) || $body === '' || strlen($body) > GATEWAY_MAX_REQUEST_BYTES
        || !hash_equals(gateway_signature($c['hmac_secret'], $timestamp, $nonce, $body), $signature)) {
        gateway_json(401, ['ok'=>false,'error'=>'invalid_authentication']);
    }
    try { $envelope = json_decode($body, true, 32, JSON_THROW_ON_ERROR); }
    catch (JsonException) { gateway_json(400, ['ok'=>false,'error'=>'invalid_json']); }
    if (!is_array($envelope)) gateway_json(400, ['ok'=>false,'error'=>'invalid_envelope']);
    $messageId = trim((string)($envelope['message_id'] ?? ''));
    $type = trim((string)($envelope['event_type'] ?? ''));
    if (!preg_match('/^[a-f0-9]{64}$/', $messageId) || !in_array($type, ['location.upsert','expected_resource.upsert','companion_user.upsert','companion_user.revoke'], true)
        || (int)($envelope['incident_id'] ?? 0) <= 0 || !is_array($envelope['data'] ?? null)) {
        gateway_json(422, ['ok'=>false,'error'=>'invalid_event']);
    }
    return ['body'=>$body,'nonce'=>$nonce,'message_id'=>$messageId,'envelope'=>$envelope];
}
function gateway_sync_boolean(mixed $value, string $field): bool {
    if (!is_bool($value)) throw new InvalidArgumentException('Invalid ' . $field . ' value.');
    return $value;
}

function gateway_sync_companion_roles(mixed $value): array {
    if (!is_array($value)) throw new InvalidArgumentException('Invalid companion-user roles.');
    $roles = array_values(array_unique(array_filter(array_map(
        static fn($role): string => strtoupper(trim((string)$role)),
        $value
    ), static fn(string $role): bool => in_array($role, ['CIR', 'SAM'], true))));
    if (!$roles) throw new InvalidArgumentException('Companion user must have CIR or SAM role.');
    return $roles;
}

function gateway_sync_datetime(mixed $value): ?string {
    if ($value === null || $value === '') return null;
    if (!is_string($value)) throw new InvalidArgumentException('Invalid companion-user timestamp.');
    try {
        return (new DateTimeImmutable($value))
            ->setTimezone(new DateTimeZone('UTC'))
            ->format('Y-m-d H:i:s');
    } catch (Throwable) {
        throw new InvalidArgumentException('Invalid companion-user timestamp.');
    }
}

function gateway_sync_audit_companion_user(PDO $pdo, string $action, ?int $gatewayUserId, ?string $displayName, ?string $role, array $details): void {
    $stmt = $pdo->prepare(
        'INSERT INTO gateway_audit_log
            (gateway_user_id,action,actor_name,actor_position,actor_role,details_json)
         VALUES (?,?,?,?,?,?)'
    );
    $stmt->execute([
        $gatewayUserId,
        $action,
        $displayName,
        $role,
        $role,
        json_encode($details, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
    ]);
}

function gateway_apply_sync_event(array $request): array {
    $pdo = gateway_db(); $e = $request['envelope']; $data = $e['data'];
    $pdo->beginTransaction();
    try {
        $existing = $pdo->prepare('SELECT id FROM gateway_sync_receipts WHERE message_id=? OR request_nonce=? LIMIT 1');
        $existing->execute([$request['message_id'], $request['nonce']]);
        if ($existing->fetch()) { $pdo->commit(); return ['duplicate'=>true]; }

        if ($e['event_type'] === 'location.upsert') {
            $locationId = (int)($data['location_id'] ?? 0);
            $token = (string)($data['location_token'] ?? '');
            $codeHash = (string)($data['access_code_hash'] ?? '');
            $lifecycle = (string)($data['lifecycle_status'] ?? '');
            if ($locationId <= 0 || !preg_match('/^[A-Za-z0-9]{32}$/', $token) || $codeHash === ''
                || !in_array($lifecycle, ['draft','active','closed','archived'], true)) throw new InvalidArgumentException('Invalid location payload.');
            $stmt = $pdo->prepare('INSERT INTO gateway_locations (internal_location_id,incident_id,location_name,location_type,street_address,location_token,access_code_hash,lifecycle_status,updated_at)
                VALUES (?,?,?,?,?,?,?,?,NOW()) ON DUPLICATE KEY UPDATE incident_id=VALUES(incident_id),location_name=VALUES(location_name),location_type=VALUES(location_type),street_address=VALUES(street_address),location_token=VALUES(location_token),access_code_hash=VALUES(access_code_hash),lifecycle_status=VALUES(lifecycle_status),updated_at=NOW()');
            $stmt->execute([$locationId,(int)$e['incident_id'],gateway_normalize_text((string)($data['name'] ?? ''),160),gateway_normalize_text((string)($data['type'] ?? ''),80),gateway_normalize_text((string)($data['street_address'] ?? ''),255),$token,$codeHash,$lifecycle]);
        } elseif ($e['event_type'] === 'expected_resource.upsert') {
            $resourceId=(int)($data['expected_resource_id'] ?? 0); $locationId=(int)($data['location_id'] ?? 0);
            $status=(string)($data['publication_status'] ?? '');
            if ($resourceId<=0 || $locationId<=0 || !in_array($status,['draft','published','withdrawn','checked_in','demobilized'],true)) throw new InvalidArgumentException('Invalid expected-resource payload.');
            $lookup=$pdo->prepare('SELECT id FROM gateway_locations WHERE internal_location_id=? AND incident_id=? LIMIT 1');
            $lookup->execute([$locationId,(int)$e['incident_id']]); $gatewayLocationId=(int)$lookup->fetchColumn();
            if ($gatewayLocationId<=0) throw new InvalidArgumentException('Location has not been synchronized.');
            $stmt=$pdo->prepare('INSERT INTO gateway_expected_resources (internal_expected_resource_id,gateway_location_id,incident_id,resource_unit_intake_id,reference_number,provider_name,provider_confirmed_at,publication_status,published_at,withdrawn_at,resource_category,resource_kind,resource_type,resource_description,quantity,updated_at)
              VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW()) ON DUPLICATE KEY UPDATE gateway_location_id=VALUES(gateway_location_id),reference_number=VALUES(reference_number),provider_name=VALUES(provider_name),provider_confirmed_at=VALUES(provider_confirmed_at),publication_status=VALUES(publication_status),published_at=VALUES(published_at),withdrawn_at=VALUES(withdrawn_at),resource_category=VALUES(resource_category),resource_kind=VALUES(resource_kind),resource_type=VALUES(resource_type),resource_description=VALUES(resource_description),quantity=VALUES(quantity),updated_at=NOW()');
            $stmt->execute([$resourceId,$gatewayLocationId,(int)$e['incident_id'],(int)($data['resource_unit_intake_id'] ?? 0),gateway_normalize_text((string)($data['reference_number'] ?? ''),100),gateway_normalize_text((string)($data['provider_name'] ?? ''),160),$data['provider_confirmed_at'] ?: null,$status,$data['published_at'] ?: null,$data['withdrawn_at'] ?: null,gateway_normalize_text((string)($data['resource_category'] ?? ''),120),gateway_normalize_text((string)($data['resource_kind'] ?? ''),120),gateway_normalize_text((string)($data['resource_type'] ?? ''),160),gateway_normalize_text((string)($data['resource_description'] ?? ''),500),gateway_normalize_text((string)($data['quantity'] ?? ''),50)]);
        } elseif ($e['event_type'] === 'companion_user.upsert') {
            $gatewayUserId = (int)($data['gateway_user_id'] ?? 0);
            $displayName = gateway_normalize_text((string)($data['display_name'] ?? ''), 160);
            $roles = gateway_sync_companion_roles($data['roles'] ?? null);
            $locationIds = array_values(array_unique(array_filter(array_map('intval', (array)($data['authorized_location_ids'] ?? [])), static fn(int $id): bool => $id > 0)));
            $active = gateway_sync_boolean($data['active'] ?? null, 'active');
            $revoked = gateway_sync_boolean($data['revoked'] ?? null, 'revoked');
            $pinVerifier = (string)($data['pin_verifier'] ?? '');
            $pinLastResetAt = gateway_sync_datetime($data['pin_last_reset_at'] ?? null);

            if ($gatewayUserId <= 0 || $displayName === '' || !$locationIds || !$active || $revoked
                || !preg_match('/^\$2[aby]\$\d{2}\$[.\/A-Za-z0-9]{53}$/', $pinVerifier)) {
                throw new InvalidArgumentException('Invalid companion-user payload.');
            }

            $marks = implode(',', array_fill(0, count($locationIds), '?'));
            $locationLookup = $pdo->prepare(
                'SELECT id,internal_location_id FROM gateway_locations
                  WHERE incident_id=? AND internal_location_id IN (' . $marks . ')'
            );
            $locationLookup->execute(array_merge([(int)$e['incident_id']], $locationIds));
            $gatewayLocations = $locationLookup->fetchAll();
            if (count($gatewayLocations) !== count($locationIds)) {
                throw new InvalidArgumentException('Authorized location has not been synchronized.');
            }

            $upsert = $pdo->prepare(
                'INSERT INTO gateway_companion_users
                    (incident_id,gateway_user_id,display_name,roles_json,pin_verifier,active,revoked_at,pin_last_reset_at,updated_at)
                 VALUES (?,?,?,?,?,1,NULL,?,NOW())
                 ON DUPLICATE KEY UPDATE
                    display_name=VALUES(display_name),roles_json=VALUES(roles_json),pin_verifier=VALUES(pin_verifier),
                    active=1,revoked_at=NULL,pin_last_reset_at=VALUES(pin_last_reset_at),updated_at=NOW()'
            );
            $upsert->execute([
                (int)$e['incident_id'],
                $gatewayUserId,
                $displayName,
                json_encode($roles, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                $pinVerifier,
                $pinLastResetAt,
            ]);
            $userLookup = $pdo->prepare('SELECT id FROM gateway_companion_users WHERE incident_id=? AND gateway_user_id=? LIMIT 1');
            $userLookup->execute([(int)$e['incident_id'], $gatewayUserId]);
            $localUserId = (int)$userLookup->fetchColumn();
            if ($localUserId <= 0) throw new RuntimeException('Companion user synchronization failed.');

            $gatewayLocationIds = array_map(static fn(array $location): int => (int)$location['id'], $gatewayLocations);
            $gatewayMarks = implode(',', array_fill(0, count($gatewayLocationIds), '?'));
            $revokeRemoved = $pdo->prepare(
                'UPDATE gateway_companion_user_locations SET revoked_at=NOW(),updated_at=NOW()
                  WHERE gateway_companion_user_id=? AND revoked_at IS NULL
                    AND gateway_location_id NOT IN (' . $gatewayMarks . ')'
            );
            $revokeRemoved->execute(array_merge([$localUserId], $gatewayLocationIds));

            $assignment = $pdo->prepare(
                'INSERT INTO gateway_companion_user_locations
                    (gateway_companion_user_id,gateway_location_id,assigned_at,revoked_at,updated_at)
                 VALUES (?,?,NOW(),NULL,NOW())
                 ON DUPLICATE KEY UPDATE
                    assigned_at=IF(revoked_at IS NOT NULL,NOW(),assigned_at),
                    revoked_at=NULL,updated_at=NOW()'
            );
            foreach ($gatewayLocationIds as $gatewayLocationId) {
                $assignment->execute([$localUserId, $gatewayLocationId]);
            }
            gateway_sync_audit_companion_user(
                $pdo,
                'companion_user_synchronized',
                $gatewayUserId,
                $displayName,
                in_array('CIR', $roles, true) ? 'CIR' : 'SAM',
                ['incident_id'=>(int)$e['incident_id'], 'authorized_location_ids'=>$locationIds, 'roles'=>$roles]
            );
        } else {
            $gatewayUserId = (int)($data['gateway_user_id'] ?? 0);
            if ($gatewayUserId <= 0) throw new InvalidArgumentException('Invalid companion-user revoke payload.');
            $lookup = $pdo->prepare(
                'SELECT id,display_name,roles_json FROM gateway_companion_users
                  WHERE incident_id=? AND gateway_user_id=? LIMIT 1'
            );
            $lookup->execute([(int)$e['incident_id'], $gatewayUserId]);
            $user = $lookup->fetch();
            if ($user) {
                $pdo->prepare('UPDATE gateway_companion_users SET active=0,revoked_at=COALESCE(revoked_at,NOW()),updated_at=NOW() WHERE id=?')
                    ->execute([(int)$user['id']]);
                $pdo->prepare('UPDATE gateway_companion_user_locations SET revoked_at=COALESCE(revoked_at,NOW()),updated_at=NOW() WHERE gateway_companion_user_id=?')
                    ->execute([(int)$user['id']]);
                $roles = gateway_companion_roles($user);
                gateway_sync_audit_companion_user(
                    $pdo,
                    'companion_user_revoked',
                    $gatewayUserId,
                    (string)$user['display_name'],
                    gateway_companion_primary_role($user) ?: null,
                    ['incident_id'=>(int)$e['incident_id'], 'roles'=>$roles]
                );
            }
        }

        $receipt=$pdo->prepare('INSERT INTO gateway_sync_receipts (message_id,request_nonce,event_type,payload_hash) VALUES (?,?,?,?)');
        $receipt->execute([$request['message_id'],$request['nonce'],(string)$e['event_type'],hash('sha256',$request['body'])]);
        $pdo->commit(); return ['duplicate'=>false];
    } catch (Throwable $ex) { if($pdo->inTransaction())$pdo->rollBack(); throw $ex; }
}
function gateway_queue_event(string $type, int $incidentId, int $locationId, array $data): int {
    $payload=['protocol_version'=>'1.0','message_id'=>bin2hex(random_bytes(24)),'event_type'=>$type,'occurred_at'=>gmdate('c'),'incident_id'=>$incidentId,'location_id'=>$locationId,'data'=>$data];
    $json=json_encode($payload,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
    $stmt=gateway_db()->prepare('INSERT INTO gateway_outbound_events (message_id,event_type,incident_id,location_id,event_payload,delivery_status,attempt_count,max_attempts,next_attempt_at) VALUES (?,?,?,?,?,"pending",0,?,NOW())');
    $stmt->execute([$payload['message_id'],$type,$incidentId,$locationId,$json,gateway_config()['max_attempts']]);
    return (int)gateway_db()->lastInsertId();
}
function gateway_dispatch_one(): ?array {
    $c=gateway_config(); if(($c['delivery_mode'] ?? 'push') !== 'push' || !gateway_sync_ready() || $c['commandbridge_events_url']==='') return null;
    $pdo=gateway_db(); $pdo->beginTransaction();
    try {
        $stmt=$pdo->query('SELECT * FROM gateway_outbound_events WHERE delivery_status IN ("pending","failed") AND next_attempt_at<=NOW() AND attempt_count<max_attempts ORDER BY id LIMIT 1 FOR UPDATE');
        $row=$stmt->fetch(); if(!$row){$pdo->commit();return null;}
        $pdo->prepare('UPDATE gateway_outbound_events SET delivery_status="processing",attempt_count=attempt_count+1,locked_at=NOW() WHERE id=?')->execute([$row['id']]);
        $pdo->commit(); $row['attempt_count']++;
    } catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    $timestamp=(string)time();$nonce=bin2hex(random_bytes(24));$body=(string)$row['event_payload'];
    $ch=curl_init($c['commandbridge_events_url']);
    curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$body,CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>$c['connect_timeout_seconds'],CURLOPT_TIMEOUT=>$c['request_timeout_seconds'],CURLOPT_HTTPHEADER=>['Content-Type: application/json','Accept: application/json','X-CommandBridge-Key-Id: '.$c['hmac_key_id'],'X-CommandBridge-Timestamp: '.$timestamp,'X-CommandBridge-Nonce: '.$nonce,'X-CommandBridge-Signature: '.gateway_signature($c['hmac_secret'],$timestamp,$nonce,$body)]]);
    $response=curl_exec($ch);$errno=curl_errno($ch);$error=curl_error($ch);$status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);
    if($response!==false && $status>=200 && $status<300){$pdo->prepare('UPDATE gateway_outbound_events SET delivery_status="delivered",delivered_at=NOW(),locked_at=NULL,last_http_status=?,last_error=NULL WHERE id=?')->execute([$status,$row['id']]);return ['delivered'=>true];}
    $permanent=$status>=400&&$status<500&&$status!==408&&$status!==429;$final=$permanent||$row['attempt_count']>=$row['max_attempts'];$delay=min(1800,30*(2**min(6,max(0,$row['attempt_count']-1))));
    $pdo->prepare('UPDATE gateway_outbound_events SET delivery_status="failed",locked_at=NULL,failed_at=IF(?,NOW(),NULL),next_attempt_at=IF(?,NULL,DATE_ADD(NOW(),INTERVAL ? SECOND)),last_http_status=?,last_error=? WHERE id=?')->execute([$final,$final,$delay,$status?:null,substr($response===false?($error?:'HTTP request failed'):'CommandBridge returned HTTP '.$status,0,1000),$row['id']]);
    return ['delivered'=>false];
}


/**
 * Verifies an HMAC-authenticated control request made by the internal
 * CommandBridge poller. It uses the existing CommandBridge header format.
 */
function gateway_validate_control_request(array $allowedActions): array {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') gateway_json(405, ['ok'=>false,'error'=>'method_not_allowed']);
    if (!gateway_sync_ready()) gateway_json(503, ['ok'=>false,'error'=>'service_unavailable']);
    if (!str_starts_with(strtolower((string)($_SERVER['CONTENT_TYPE'] ?? '')), 'application/json')) gateway_json(415, ['ok'=>false,'error'=>'json_required']);

    $length = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
    if ($length <= 0 || $length > GATEWAY_MAX_REQUEST_BYTES) gateway_json(413, ['ok'=>false,'error'=>'payload_too_large']);

    $timestamp = trim((string)($_SERVER['HTTP_X_COMMANDBRIDGE_TIMESTAMP'] ?? ''));
    $nonce = trim((string)($_SERVER['HTTP_X_COMMANDBRIDGE_NONCE'] ?? ''));
    $keyId = trim((string)($_SERVER['HTTP_X_COMMANDBRIDGE_KEY_ID'] ?? ''));
    $signature = trim((string)($_SERVER['HTTP_X_COMMANDBRIDGE_SIGNATURE'] ?? ''));
    $c = gateway_config();

    if (!preg_match('/^\d{10}$/', $timestamp) || !preg_match('/^[A-Za-z0-9._:-]{16,128}$/', $nonce)
        || $keyId === '' || !hash_equals($c['hmac_key_id'], $keyId)
        || abs(time() - (int)$timestamp) > $c['request_ttl_seconds']) {
        gateway_json(401, ['ok'=>false,'error'=>'invalid_authentication']);
    }

    $body = file_get_contents('php://input');
    if (!is_string($body) || $body === '' || strlen($body) > GATEWAY_MAX_REQUEST_BYTES
        || !hash_equals(gateway_signature($c['hmac_secret'], $timestamp, $nonce, $body), $signature)) {
        gateway_log('invalid_poller_signature', ['nonce'=>$nonce]);
        gateway_json(401, ['ok'=>false,'error'=>'invalid_authentication']);
    }

    try { $request = json_decode($body, true, 32, JSON_THROW_ON_ERROR); }
    catch (JsonException) { gateway_json(400, ['ok'=>false,'error'=>'invalid_json']); }

    if (!is_array($request) || ($request['protocol_version'] ?? null) !== '1.0'
        || !is_string($request['action'] ?? null) || !in_array($request['action'], $allowedActions, true)
        || !is_string($request['request_id'] ?? null) || !preg_match('/^[a-f0-9]{32,128}$/i', $request['request_id'])) {
        gateway_json(422, ['ok'=>false,'error'=>'invalid_control_request']);
    }

    return $request;
}

/**
 * Leases queued check-ins to the internal poller. An event is not delivered
 * until the poller sends a processed acknowledgement.
 */
function gateway_pull_events(PDO $pdo, int $limit): array {
    $c = gateway_config();
    $limit = max(1, min(100, $limit));
    $leaseSeconds = (int)$c['pull_lease_seconds'];

    $pdo->beginTransaction();
    try {
        $pdo->exec("UPDATE gateway_outbound_events SET delivery_status='pending',locked_at=NULL
            WHERE delivery_status='processing' AND locked_at IS NOT NULL
              AND locked_at < DATE_SUB(NOW(), INTERVAL {$leaseSeconds} SECOND)");

        $select = $pdo->prepare("SELECT id,message_id,event_payload FROM gateway_outbound_events
            WHERE delivery_status IN ('pending','failed') AND next_attempt_at IS NOT NULL
              AND next_attempt_at<=NOW() AND attempt_count<max_attempts
            ORDER BY id LIMIT {$limit} FOR UPDATE");
        $select->execute();

        $lease = $pdo->prepare("UPDATE gateway_outbound_events
            SET delivery_status='processing',attempt_count=attempt_count+1,locked_at=NOW(),last_error=NULL WHERE id=?");
        $invalid = $pdo->prepare("UPDATE gateway_outbound_events SET delivery_status='failed',
            next_attempt_at=NULL,locked_at=NULL,failed_at=NOW(),last_error='Stored event payload is invalid JSON.' WHERE id=?");

        $events = [];
        foreach ($select->fetchAll() as $row) {
            try { $event = json_decode((string)$row['event_payload'], true, 32, JSON_THROW_ON_ERROR); }
            catch (JsonException) {
                $invalid->execute([$row['id']]);
                gateway_log('invalid_queued_event', ['message_id'=>$row['message_id']]);
                continue;
            }
            if (!is_array($event)) { $invalid->execute([$row['id']]); continue; }
            $lease->execute([$row['id']]);
            $events[] = $event;
        }
        $pdo->commit();
        return $events;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

/**
 * Finalizes a pull lease. A retry returns an event to the poll queue after
 * a short delay; a rejection retains it for operational review.
 */
function gateway_acknowledge_pulled_event(PDO $pdo, string $messageId, string $outcome, string $error=''): array {
    $pdo->beginTransaction();
    try {
        $select = $pdo->prepare('SELECT id,delivery_status FROM gateway_outbound_events WHERE message_id=? FOR UPDATE');
        $select->execute([$messageId]);
        $event = $select->fetch();
        if (!$event) { $pdo->rollBack(); return ['found'=>false,'already_delivered'=>false]; }
        if ($event['delivery_status'] === 'delivered') { $pdo->commit(); return ['found'=>true,'already_delivered'=>true]; }

        if ($outcome === 'processed') {
            $stmt = $pdo->prepare("UPDATE gateway_outbound_events SET delivery_status='delivered',delivered_at=NOW(),
                failed_at=NULL,locked_at=NULL,next_attempt_at=NULL,last_error=NULL WHERE id=?");
            $stmt->execute([$event['id']]);
        } elseif ($outcome === 'retry') {
            $stmt = $pdo->prepare("UPDATE gateway_outbound_events SET delivery_status='failed',failed_at=NULL,
                locked_at=NULL,next_attempt_at=DATE_ADD(NOW(), INTERVAL 30 SECOND),last_error=? WHERE id=?");
            $stmt->execute([$error !== '' ? $error : 'Internal poller requested retry.', $event['id']]);
        } else {
            $stmt = $pdo->prepare("UPDATE gateway_outbound_events SET delivery_status='failed',failed_at=NOW(),
                locked_at=NULL,next_attempt_at=NULL,last_error=? WHERE id=?");
            $stmt->execute([$error !== '' ? $error : 'Internal poller rejected the event.', $event['id']]);
        }
        $pdo->commit();
        return ['found'=>true,'already_delivered'=>false];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}
