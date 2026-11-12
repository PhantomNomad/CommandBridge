<?php
declare(strict_types=1);

/*
 * External check-in synchronization support.
 *
 * This file deliberately reads the shared HMAC secret from a server-side
 * environment file, not from app_settings or the web root. See
 * config/external_checkin_sync.env.example.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/helpers.php';

const EXTERNAL_CHECKIN_PROTOCOL_VERSION = '1.0';
const EXTERNAL_CHECKIN_MAX_REQUEST_BYTES = 65536;
const EXTERNAL_CHECKIN_DEFAULT_ENV_PATH = '/etc/commandbridge/external_checkin_sync.env';

/**
 * Load the external synchronization configuration from an environment file.
 * The actual file must be outside the Apache document root and readable only
 * by the CommandBridge web/CLI service account.
 */
function external_checkin_sync_config(): array {
    static $config = null;
    if (is_array($config)) {
        return $config;
    }

    $config = [
        'enabled' => false,
        'gateway_url' => '',
        'gateway_pull_url' => '',
        'gateway_ack_url' => '',
        'public_entry_base_url' => '',
        'hmac_key_id' => '',
        'hmac_secret' => '',
        'request_ttl_seconds' => 300,
        'max_attempts' => 12,
        'connect_timeout_seconds' => 5,
        'request_timeout_seconds' => 20,
    ];

    $path = getenv('EXTERNAL_CHECKIN_SYNC_ENV_PATH');
    if (!is_string($path) || trim($path) === '') {
        $path = EXTERNAL_CHECKIN_DEFAULT_ENV_PATH;
    }

    if (!is_file($path) || !is_readable($path)) {
        return $config;
    }

    $values = [];
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        return $config;
    }

    foreach ($lines as $line) {
        $line = trim((string)$line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
            continue;
        }
        [$key, $value] = explode('=', $line, 2);
        $values[trim($key)] = trim($value);
    }

    $enabled = strtolower((string)($values['EXTERNAL_CHECKIN_ENABLED'] ?? '0'));
    $config['enabled'] = in_array($enabled, ['1', 'true', 'yes', 'on'], true);
    $config['gateway_url'] = trim((string)($values['EXTERNAL_CHECKIN_GATEWAY_URL'] ?? ''));
    $config['gateway_pull_url'] = trim((string)($values['EXTERNAL_CHECKIN_GATEWAY_PULL_URL'] ?? ''));
    $config['gateway_ack_url'] = trim((string)($values['EXTERNAL_CHECKIN_GATEWAY_ACK_URL'] ?? ''));
    $config['public_entry_base_url'] = trim((string)($values['EXTERNAL_CHECKIN_PUBLIC_ENTRY_BASE_URL'] ?? ''));
    $config['hmac_key_id'] = trim((string)($values['EXTERNAL_CHECKIN_HMAC_KEY_ID'] ?? ''));
    $config['hmac_secret'] = trim((string)($values['EXTERNAL_CHECKIN_HMAC_SECRET'] ?? ''));
    $config['request_ttl_seconds'] = max(60, min(900, (int)($values['EXTERNAL_CHECKIN_REQUEST_TTL_SECONDS'] ?? 300)));
    $config['max_attempts'] = max(1, min(50, (int)($values['EXTERNAL_CHECKIN_MAX_ATTEMPTS'] ?? 12)));
    $config['connect_timeout_seconds'] = max(1, min(30, (int)($values['EXTERNAL_CHECKIN_CONNECT_TIMEOUT'] ?? 5)));
    $config['request_timeout_seconds'] = max(5, min(60, (int)($values['EXTERNAL_CHECKIN_REQUEST_TIMEOUT'] ?? 20)));

    return $config;
}

function external_checkin_sync_is_configured(): bool {
    $config = external_checkin_sync_config();
    return $config['enabled'] === true
        && $config['hmac_key_id'] !== ''
        && $config['hmac_secret'] !== '';
}

function external_checkin_json_encode(array $data): string {
    return json_encode(
        $data,
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
    );
}

function external_checkin_json_response(int $status, array $body): never {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo external_checkin_json_encode($body);
    exit;
}

/**
 * The HMAC source is timestamp + LF + nonce + LF + exact raw JSON body.
 */
function external_checkin_request_signature(string $secret, string $timestamp, string $nonce, string $body): string {
    return base64_encode(hash_hmac('sha256', $timestamp . "\n" . $nonce . "\n" . $body, $secret, true));
}

function external_checkin_generate_nonce(): string {
    return bin2hex(random_bytes(24));
}

function external_checkin_normalize_error(string $error): string {
    $error = trim(preg_replace('/\s+/', ' ', $error) ?? '');
    return substr($error, 0, 1000);
}

/**
 * Validate an HMAC-signed inbound gateway request. Returns the decoded
 * envelope and request metadata; it does not apply the operational event.
 */
function external_checkin_validate_inbound_request(): array {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        external_checkin_json_response(405, ['ok' => false, 'error' => 'method_not_allowed']);
    }

    if (!external_checkin_sync_is_configured()) {
        external_checkin_json_response(503, ['ok' => false, 'error' => 'service_unavailable']);
    }

    $contentType = strtolower((string)($_SERVER['CONTENT_TYPE'] ?? ''));
    if (!str_starts_with($contentType, 'application/json')) {
        external_checkin_json_response(415, ['ok' => false, 'error' => 'json_required']);
    }

    $contentLength = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
    if ($contentLength <= 0 || $contentLength > EXTERNAL_CHECKIN_MAX_REQUEST_BYTES) {
        external_checkin_json_response(413, ['ok' => false, 'error' => 'payload_too_large']);
    }

    $timestamp = trim((string)($_SERVER['HTTP_X_COMMANDBRIDGE_TIMESTAMP'] ?? ''));
    $nonce = trim((string)($_SERVER['HTTP_X_COMMANDBRIDGE_NONCE'] ?? ''));
    $keyId = trim((string)($_SERVER['HTTP_X_COMMANDBRIDGE_KEY_ID'] ?? ''));
    $signature = trim((string)($_SERVER['HTTP_X_COMMANDBRIDGE_SIGNATURE'] ?? ''));

    if (!preg_match('/^\d{10}$/', $timestamp) || !preg_match('/^[A-Za-z0-9._:-]{16,128}$/', $nonce)) {
        external_checkin_json_response(401, ['ok' => false, 'error' => 'invalid_authentication_headers']);
    }

    $config = external_checkin_sync_config();
    if ($keyId === '' || !hash_equals($config['hmac_key_id'], $keyId)) {
        external_checkin_json_response(401, ['ok' => false, 'error' => 'invalid_authentication']);
    }

    if (abs(time() - (int)$timestamp) > $config['request_ttl_seconds']) {
        external_checkin_json_response(401, ['ok' => false, 'error' => 'expired_request']);
    }

    $rawBody = file_get_contents('php://input');
    if (!is_string($rawBody) || $rawBody === '' || strlen($rawBody) > EXTERNAL_CHECKIN_MAX_REQUEST_BYTES) {
        external_checkin_json_response(400, ['ok' => false, 'error' => 'invalid_payload']);
    }

    $expected = external_checkin_request_signature($config['hmac_secret'], $timestamp, $nonce, $rawBody);
    if ($signature === '' || !hash_equals($expected, $signature)) {
        external_checkin_json_response(401, ['ok' => false, 'error' => 'invalid_authentication']);
    }

    try {
        $envelope = json_decode($rawBody, true, 32, JSON_THROW_ON_ERROR);
    } catch (JsonException $e) {
        external_checkin_json_response(400, ['ok' => false, 'error' => 'invalid_json']);
    }

    if (!is_array($envelope)) {
        external_checkin_json_response(400, ['ok' => false, 'error' => 'invalid_envelope']);
    }

    $messageId = trim((string)($envelope['message_id'] ?? ''));
    $eventType = trim((string)($envelope['event_type'] ?? ''));
    $incidentId = (int)($envelope['incident_id'] ?? 0);
    $locationId = (int)($envelope['location_id'] ?? 0);
    $data = $envelope['data'] ?? null;
    $allowedEventTypes = [
        'checkin.recorded',
        'checkin.exception',
        'checkin.no_show',
        'checkin.unscheduled_arrival',
    ];

    if (!preg_match('/^[A-Za-z0-9._:-]{16,128}$/', $messageId)
        || !in_array($eventType, $allowedEventTypes, true)
        || $incidentId <= 0
        || $locationId <= 0
        || !is_array($data)
    ) {
        external_checkin_json_response(422, ['ok' => false, 'error' => 'invalid_event']);
    }

    return [
        'envelope' => $envelope,
        'raw_body' => $rawBody,
        'timestamp' => $timestamp,
        'nonce' => $nonce,
        'remote_ip' => substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45),
    ];
}

/**
 * Store an inbound gateway event once. Step 2 will process these receipts into
 * controlled Resources Unit workflow changes.
 */
function external_checkin_record_inbound_receipt(array $request): array {
    $envelope = $request['envelope'];
    $messageId = (string)$envelope['message_id'];
    $nonce = (string)$request['nonce'];

    $existing = db()->prepare(
        'SELECT id, receipt_status FROM external_checkin_api_receipts
         WHERE message_id=? OR request_nonce=? LIMIT 1'
    );
    $existing->execute([$messageId, $nonce]);
    $row = $existing->fetch();

    if ($row) {
        db()->prepare(
            'UPDATE external_checkin_api_receipts SET last_received_at=NOW() WHERE id=?'
        )->execute([(int)$row['id']]);

        return ['duplicate' => true, 'receipt_id' => (int)$row['id'], 'status' => (string)$row['receipt_status']];
    }

    $pdo = db();
    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare(
            'INSERT INTO external_checkin_api_receipts
             (incident_id,location_id,message_id,request_nonce,event_type,payload_hash,event_payload,received_ip)
             VALUES (?,?,?,?,?,?,?,?)'
        );
        $stmt->execute([
            (int)$envelope['incident_id'],
            (int)$envelope['location_id'],
            $messageId,
            $nonce,
            (string)$envelope['event_type'],
            hash('sha256', (string)$request['raw_body']),
            (string)$request['raw_body'],
            (string)$request['remote_ip'],
        ]);
        $receiptId = (int)$pdo->lastInsertId();

        $eventStmt = $pdo->prepare(
            'INSERT INTO external_checkin_events (incident_id,event_type,event_payload,created_by)
             VALUES (?,?,?,NULL)'
        );
        $eventStmt->execute([
            (int)$envelope['incident_id'],
            'gateway_event_received',
            external_checkin_json_encode([
                'receipt_id' => $receiptId,
                'message_id' => $messageId,
                'event_type' => (string)$envelope['event_type'],
                'location_id' => (int)$envelope['location_id'],
            ]),
        ]);

        $pdo->commit();
        return ['duplicate' => false, 'receipt_id' => $receiptId, 'status' => 'received'];
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        // Concurrent retries may collide after the initial duplicate check.
        if ($e->getCode() === '23000') {
            $existing->execute([$messageId, $nonce]);
            $row = $existing->fetch();
            if ($row) {
                return ['duplicate' => true, 'receipt_id' => (int)$row['id'], 'status' => (string)$row['receipt_status']];
            }
        }
        throw $e;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}


/**
 * Processes received check-in reports from the public gateway.
 * This also recovers reports that were accepted by an earlier endpoint version
 * before inbound processing was enabled.
 */
function external_checkin_process_pending_inbound_receipts(int $limit = 25): array
{
    $limit = max(1, min($limit, 100));
    $pdo = db();

    $statement = $pdo->query(
        'SELECT r.id
           FROM external_checkin_api_receipts r
          WHERE r.receipt_status = "received"
             OR (
                 r.receipt_status = "processed"
                 AND r.event_type = "checkin.recorded"
                 AND EXISTS (
                     SELECT 1
                       FROM external_checkin_reports cr
                       INNER JOIN resource_unit_intake rui
                               ON rui.id = cr.resource_unit_intake_id
                       INNER JOIN form_213rr_headers h
                               ON h.id = rui.source_213rr_header_id
                       INNER JOIN form_records fr
                               ON fr.id = h.form_record_id
                      WHERE cr.receipt_id = r.id
                        AND fr.is_deleted = 0
                        AND h.workflow_status NOT IN (
                        "fulfilled",
                        "partially_fulfilled",
                        "closed",
                        "cancelled",
                        "unable_to_fill"
                    )
                 )
             )
          ORDER BY r.created_at ASC, r.id ASC
          LIMIT ' . $limit
    );

    $result = ['processed' => 0, 'rejected' => 0, 'failed' => 0];

    foreach ($statement->fetchAll() as $receipt) {
        $receiptId = (int) $receipt['id'];

        try {
            $pdo->beginTransaction();

            $load = $pdo->prepare(
                'SELECT id, receipt_status, event_type, incident_id, location_id, event_payload
                   FROM external_checkin_api_receipts
                  WHERE id = ?
                    AND receipt_status IN ("received", "processed")
                  FOR UPDATE'
            );
            $load->execute([$receiptId]);
            $row = $load->fetch();

            if (!$row) {
                $pdo->commit();
                continue;
            }

            $payload = json_decode((string) $row['event_payload'], true);
            $data = is_array($payload['data'] ?? null) ? $payload['data'] : [];

            if ((string) $row['event_type'] !== 'checkin.recorded') {
                $pdo->prepare(
                    'UPDATE external_checkin_api_receipts
                        SET receipt_status = "processed",
                          processing_error = NULL,
                          processed_at = NOW()
                      WHERE id = ?'
                )->execute([$receiptId]);
                $pdo->commit();
                $result['processed']++;
                continue;
            }

            $expectedId = (int) ($data['expected_resource_id'] ?? 0);
            if ($expectedId < 1) {
                throw new InvalidArgumentException('A checked-in resource is required.');
            }

            $expected = $pdo->prepare(
                'SELECT er.id, er.incident_id, er.resource_unit_intake_id, er.provider_name,
                        rui.resource_category, rui.resource_kind, rui.resource_type,
                        rui.resource_description, rui.quantity_received,
                        rui.source_213rr_header_id, rui.source_request_number, rui.logistics_order_number,
                        rui.supplier_name, rui.supplier_contact, rui.initial_location,
                        rui.ics211_entry_id, rui.ics218_entry_id,
                        loc.location_name
                   FROM external_checkin_expected_resources er
                   INNER JOIN resource_unit_intake rui ON rui.id = er.resource_unit_intake_id
                   INNER JOIN external_checkin_locations loc ON loc.id = er.location_id
                  WHERE er.id = ?
                    AND er.incident_id = ?
                    AND er.location_id = ?
                    AND (
                        er.publication_status = "published"
                        OR (? = "processed" AND er.publication_status = "checked_in")
                    )
                  FOR UPDATE'
            );
            $expected->execute([
                $expectedId,
                (int) $row['incident_id'],
                (int) $row['location_id'],
                (string) $row['receipt_status'],
            ]);
            $resource = $expected->fetch();

            if (!$resource) {
                throw new InvalidArgumentException('The expected resource is not available for check-in.');
            }

            $reportedAt = trim((string) ($data['recorded_at'] ?? ''));
            if ($reportedAt === '') {
                $reportedAt = gmdate('Y-m-d H:i:s');
            } else {
                $reportedAt = (new DateTimeImmutable($reportedAt))
                    ->setTimezone(new DateTimeZone('UTC'))
                    ->format('Y-m-d H:i:s');
            }

            $recorder = is_array($data['recorder'] ?? null) ? $data['recorder'] : [];
            $recorderName = trim((string) ($recorder['name'] ?? ''));
            $crewLead = trim((string) ($data['crew_lead_name'] ?? $data['crew_lead'] ?? ''));
            $notes = trim((string) ($data['notes'] ?? ''));

            $pdo->prepare(
                'INSERT IGNORE INTO external_checkin_reports
                    (receipt_id, incident_id, location_id, expected_resource_id, resource_unit_intake_id,
                     event_type, reported_at, recorder_name, crew_lead_name, notes, report_payload, created_at)
                 VALUES (?, ?, ?, ?, ?, "checkin.recorded", ?, ?, ?, ?, ?, NOW())'
            )->execute([
                $receiptId,
                (int) $row['incident_id'],
                (int) $row['location_id'],
                $expectedId,
                (int) $resource['resource_unit_intake_id'],
                $reportedAt,
                $recorderName !== '' ? mb_substr($recorderName, 0, 190) : null,
                $crewLead !== '' ? mb_substr($crewLead, 0, 190) : null,
                $notes !== '' ? mb_substr($notes, 0, 4000) : null,
                json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            ]);

            $pdo->prepare(
                'UPDATE external_checkin_expected_resources
                    SET publication_status = "checked_in", updated_at = NOW()
                  WHERE id = ?'
            )->execute([$expectedId]);

            $receipt = external_checkin_record_resource_unit_receipt(
                $pdo,
                $resource,
                $data,
                $reportedAt,
                $recorderName,
                $crewLead,
                $notes
            );

            external_checkin_apply_checkin_to_ics_forms($pdo, $resource, $data, $reportedAt, $crewLead, $notes);
            external_checkin_sync_source_213rr_after_receipt($pdo, $resource, $receipt);

            $pdo->prepare(
                'UPDATE external_checkin_api_receipts
                    SET receipt_status = "processed",
                        processing_error = NULL,
                        processed_at = NOW()
                  WHERE id = ?'
            )->execute([$receiptId]);

            external_checkin_enqueue_expected_resource_snapshot($expectedId);
            $pdo->commit();
            $result['processed']++;
        } catch (InvalidArgumentException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $pdo->prepare(
                'UPDATE external_checkin_api_receipts
                    SET receipt_status = "rejected", processing_error = ?, processed_at = NOW()
                  WHERE id = ? AND receipt_status = "received"'
            )->execute([mb_substr($e->getMessage(), 0, 1000), $receiptId]);
            $result['rejected']++;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $result['failed']++;
            security_log(
                $e,
                'external_checkin_inbound_processing_failed receipt_id=' . $receiptId
            );
        }
    }

    return $result;
}

function external_checkin_queue_enqueue(
    int $incidentId,
    string $entityType,
    int $entityId,
    string $eventType,
    array $data
): int {
    if ($incidentId <= 0 || $entityId <= 0 || $entityType === '' || $eventType === '') {
        throw new InvalidArgumentException('A valid synchronization queue event is required.');
    }

    $idempotencyKey = hash('sha256', external_checkin_json_encode([
        'incident_id' => $incidentId,
        'entity_type' => $entityType,
        'entity_id' => $entityId,
        'event_type' => $eventType,
        'data' => $data,
    ]));

    $envelope = [
        'protocol_version' => EXTERNAL_CHECKIN_PROTOCOL_VERSION,
        'message_id' => $idempotencyKey,
        'event_type' => $eventType,
        'occurred_at' => gmdate('c'),
        'incident_id' => $incidentId,
        'entity_type' => $entityType,
        'entity_id' => $entityId,
        'data' => $data,
    ];

    $config = external_checkin_sync_config();
    $payload = external_checkin_json_encode($envelope);
    $stmt = db()->prepare(
        'INSERT INTO external_checkin_sync_queue
         (incident_id,entity_type,entity_id,event_type,event_payload,idempotency_key,delivery_status,attempt_count,max_attempts,next_attempt_at)
         VALUES (?,?,?,?,?,?,"pending",0,?,NOW())
         ON DUPLICATE KEY UPDATE
           event_payload=IF(delivery_status="delivered",event_payload,VALUES(event_payload)),
           max_attempts=IF(delivery_status="delivered",max_attempts,VALUES(max_attempts)),
           next_attempt_at=IF(delivery_status="delivered",next_attempt_at,NOW()),
           delivery_status=IF(delivery_status="delivered",delivery_status,"pending"),
           locked_at=IF(delivery_status="delivered",locked_at,NULL),
           locked_by=IF(delivery_status="delivered",locked_by,NULL),
           failed_at=IF(delivery_status="delivered",failed_at,NULL),
           last_http_status=IF(delivery_status="delivered",last_http_status,NULL),
           last_error=IF(delivery_status="delivered",last_error,NULL)'
    );
    $stmt->execute([
        $incidentId,
        $entityType,
        $entityId,
        $eventType,
        $payload,
        $idempotencyKey,
        $config['max_attempts'],
    ]);

    $lookup = db()->prepare('SELECT id FROM external_checkin_sync_queue WHERE idempotency_key=? LIMIT 1');
    $lookup->execute([$idempotencyKey]);
    return (int)$lookup->fetchColumn();
}

function external_checkin_enqueue_location_snapshot(int $locationId): int {
    $stmt = db()->prepare(
        'SELECT id,incident_id,location_name,location_type,street_address,location_token,access_code_hash,lifecycle_status
         FROM external_checkin_locations
         WHERE id=? LIMIT 1'
    );
    $stmt->execute([$locationId]);
    $location = $stmt->fetch();
    if (!$location) {
        throw new RuntimeException('External check-in location was not found.');
    }

    return external_checkin_queue_enqueue(
        (int)$location['incident_id'],
        'location',
        (int)$location['id'],
        'location.upsert',
        [
            'location_id' => (int)$location['id'],
            'name' => (string)$location['location_name'],
            'type' => (string)$location['location_type'],
            'street_address' => (string)($location['street_address'] ?? ''),
            'location_token' => (string)$location['location_token'],
            'access_code_hash' => (string)$location['access_code_hash'],
            'lifecycle_status' => (string)$location['lifecycle_status'],
        ]
    );
}

function external_checkin_enqueue_expected_resource_snapshot(int $expectedResourceId): int {
    $stmt = db()->prepare(
        'SELECT
            er.id, er.incident_id, er.location_id, er.resource_unit_intake_id,
            er.provider_name, er.provider_confirmed_at, er.publication_status,
            er.published_at, er.withdrawn_at,
            rui.source_request_number, rui.resource_category, rui.resource_kind,
            rui.resource_type, rui.resource_description, rui.quantity_received
         FROM external_checkin_expected_resources er
         INNER JOIN resource_unit_intake rui ON rui.id=er.resource_unit_intake_id
         WHERE er.id=? LIMIT 1'
    );
    $stmt->execute([$expectedResourceId]);
    $resource = $stmt->fetch();
    if (!$resource) {
        throw new RuntimeException('Expected external check-in resource was not found.');
    }

    return external_checkin_queue_enqueue(
        (int)$resource['incident_id'],
        'expected_resource',
        (int)$resource['id'],
        'expected_resource.upsert',
        [
            'expected_resource_id' => (int)$resource['id'],
            'location_id' => (int)$resource['location_id'],
            'resource_unit_intake_id' => (int)$resource['resource_unit_intake_id'],
            'reference_number' => (string)($resource['source_request_number'] ?? ''),
            'provider_name' => (string)$resource['provider_name'],
            'provider_confirmed_at' => $resource['provider_confirmed_at'] ?: null,
            'publication_status' => (string)$resource['publication_status'],
            'published_at' => $resource['published_at'] ?: null,
            'withdrawn_at' => $resource['withdrawn_at'] ?: null,
            'resource_category' => (string)($resource['resource_category'] ?? ''),
            'resource_kind' => (string)($resource['resource_kind'] ?? ''),
            'resource_type' => (string)($resource['resource_type'] ?? ''),
            'resource_description' => (string)($resource['resource_description'] ?? ''),
            'quantity' => $resource['quantity_received'] !== null ? (string)$resource['quantity_received'] : null,
        ]
    );
}


/**
 * Queue the least-privilege CIR/SAM Companion Check-In account snapshot.
 * The verifier is derived from the user's single CommandBridge PIN. Plaintext
 * PINs are never transmitted.
 */
function external_checkin_enqueue_companion_user_snapshot(int $incidentId, int $userId): int {
    $access = companion_checkin_user_access($incidentId, $userId);
    if (!$access) {
        return external_checkin_queue_enqueue(
            $incidentId,
            'companion_user',
            $userId,
            'companion_user.revoke',
            [
                'gateway_user_id' => $userId,
                'active' => false,
                'revoked' => true,
            ]
        );
    }

    $roleCodes = array_values(array_intersect(
        array_map('strtoupper', (array)($access['role_codes'] ?? [])),
        companion_checkin_role_codes()
    ));
    $locationIds = array_map('intval', (array)($access['location_ids'] ?? []));
    $eligible = !empty($access['is_active'])
        && !empty($access['access_id'])
        && empty($access['access_revoked_at'])
        && trim((string)($access['pin_encrypted'] ?? '')) !== ''
        && !empty($roleCodes)
        && !empty($locationIds);

    return external_checkin_queue_enqueue(
        $incidentId,
        'companion_user',
        $userId,
        $eligible ? 'companion_user.upsert' : 'companion_user.revoke',
        [
            'gateway_user_id' => $userId,
            'display_name' => (string)($access['real_name'] ?? ''),
            'roles' => $roleCodes,
            'authorized_location_ids' => $locationIds,
            'active' => $eligible,
            'revoked' => !$eligible,
            'pin_verifier' => $eligible ? (string)$access['pin_encrypted'] : null,
            'pin_last_reset_at' => $access['pin_last_reset_at'] ?: null,
        ]
    );
}

function external_checkin_enqueue_incident_companion_users(int $incidentId): int {
    if ($incidentId <= 0) {
        return 0;
    }

    $stmt = db()->prepare(
        'SELECT DISTINCT u.id
           FROM users u
           LEFT JOIN incident_positions ip
             ON ip.user_id = u.id
            AND ip.incident_id = ?
            AND ip.deleted_at IS NULL
           LEFT JOIN external_checkin_user_access a
             ON a.incident_id = ?
            AND a.user_id = u.id
          WHERE (ip.id IS NOT NULL OR a.id IS NOT NULL)
            AND u.deleted_at IS NULL'
    );
    $stmt->execute([$incidentId, $incidentId]);
    $count = 0;
    foreach ($stmt->fetchAll() as $row) {
        external_checkin_enqueue_companion_user_snapshot($incidentId, (int)$row['id']);
        $count++;
    }
    return $count;
}

function external_checkin_requeue_stale_processing_rows(): void {
    db()->exec(
        'UPDATE external_checkin_sync_queue
         SET delivery_status="pending",
             next_attempt_at=NOW(),
             locked_at=NULL,
             locked_by=NULL,
             last_error="Previous worker lock expired; event returned to the queue."
         WHERE delivery_status="processing"
           AND locked_at IS NOT NULL
           AND locked_at < DATE_SUB(NOW(), INTERVAL 15 MINUTE)'
    );
}

function external_checkin_claim_next_queue_event(string $workerId): ?array {
    $pdo = db();
    $pdo->beginTransaction();

    try {
        $stmt = $pdo->query(
            'SELECT *
             FROM external_checkin_sync_queue
             WHERE delivery_status IN ("pending","failed")
               AND next_attempt_at IS NOT NULL
               AND next_attempt_at <= NOW()
               AND attempt_count < max_attempts
             ORDER BY id ASC
             LIMIT 1
             FOR UPDATE'
        );
        $row = $stmt->fetch();
        if (!$row) {
            $pdo->commit();
            return null;
        }

        $claim = $pdo->prepare(
            'UPDATE external_checkin_sync_queue
             SET delivery_status="processing",
                 attempt_count=attempt_count+1,
                 locked_at=NOW(),
                 locked_by=?,
                 failed_at=NULL,
                 updated_at=NOW()
             WHERE id=?'
        );
        $claim->execute([$workerId, (int)$row['id']]);
        $row['attempt_count'] = (int)$row['attempt_count'] + 1;
        $pdo->commit();
        return $row;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function external_checkin_mark_queue_delivered(int $queueId, int $httpStatus): void {
    db()->prepare(
        'UPDATE external_checkin_sync_queue
         SET delivery_status="delivered",
             next_attempt_at=NULL,
             locked_at=NULL,
             locked_by=NULL,
             delivered_at=NOW(),
             failed_at=NULL,
             last_http_status=?,
             last_error=NULL,
             updated_at=NOW()
         WHERE id=?'
    )->execute([$httpStatus, $queueId]);
}

function external_checkin_mark_queue_failure(
    int $queueId,
    int $attemptCount,
    int $maxAttempts,
    ?int $httpStatus,
    string $error,
    bool $permanent = false
): void {
    $error = external_checkin_normalize_error($error);
    $finalFailure = $permanent || $attemptCount >= $maxAttempts;

    if ($finalFailure) {
        db()->prepare(
            'UPDATE external_checkin_sync_queue
             SET delivery_status="failed",
                 next_attempt_at=NULL,
                 locked_at=NULL,
                 locked_by=NULL,
                 failed_at=NOW(),
                 last_http_status=?,
                 last_error=?,
                 updated_at=NOW()
             WHERE id=?'
        )->execute([$httpStatus, $error, $queueId]);
        return;
    }

    $delaySeconds = min(1800, 30 * (2 ** min(6, max(0, $attemptCount - 1))));
    db()->prepare(
        'UPDATE external_checkin_sync_queue
         SET delivery_status="failed",
             next_attempt_at=DATE_ADD(NOW(), INTERVAL ' . (int)$delaySeconds . ' SECOND),
             locked_at=NULL,
             locked_by=NULL,
             last_http_status=?,
             last_error=?,
             updated_at=NOW()
         WHERE id=?'
    )->execute([$httpStatus, $error, $queueId]);
}

function external_checkin_send_queue_event(array $row): array {
    $config = external_checkin_sync_config();
    if (!$config['enabled'] || $config['gateway_url'] === '' || $config['hmac_key_id'] === '' || $config['hmac_secret'] === '') {
        return ['ok' => false, 'http_status' => null, 'error' => 'Synchronization is not configured.', 'permanent' => false];
    }

    $gateway = parse_url($config['gateway_url']);
    if (!is_array($gateway) || strtolower((string)($gateway['scheme'] ?? '')) !== 'https' || empty($gateway['host'])) {
        return ['ok' => false, 'http_status' => null, 'error' => 'Gateway URL must be a valid HTTPS URL.', 'permanent' => true];
    }

    if (!function_exists('curl_init')) {
        return ['ok' => false, 'http_status' => null, 'error' => 'The PHP cURL extension is required for synchronization.', 'permanent' => true];
    }

    $body = (string)$row['event_payload'];
    $timestamp = (string)time();
    $nonce = external_checkin_generate_nonce();
    $signature = external_checkin_request_signature($config['hmac_secret'], $timestamp, $nonce, $body);

    $curl = curl_init($config['gateway_url']);
    curl_setopt_array($curl, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => $config['connect_timeout_seconds'],
        CURLOPT_TIMEOUT => $config['request_timeout_seconds'],
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Accept: application/json',
            'X-CommandBridge-Key-Id: ' . $config['hmac_key_id'],
            'X-CommandBridge-Timestamp: ' . $timestamp,
            'X-CommandBridge-Nonce: ' . $nonce,
            'X-CommandBridge-Signature: ' . $signature,
        ],
    ]);

    $response = curl_exec($curl);
    $error = curl_error($curl);
    $httpStatus = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);

    if ($response === false) {
        return ['ok' => false, 'http_status' => $httpStatus ?: null, 'error' => 'Gateway request failed: ' . $error, 'permanent' => false];
    }

    if ($httpStatus >= 200 && $httpStatus < 300) {
        return ['ok' => true, 'http_status' => $httpStatus, 'error' => '', 'permanent' => false];
    }

    $permanent = $httpStatus >= 400 && $httpStatus < 500 && $httpStatus !== 408 && $httpStatus !== 429;
    return [
        'ok' => false,
        'http_status' => $httpStatus ?: null,
        'error' => 'Gateway returned HTTP ' . ($httpStatus ?: 0),
        'permanent' => $permanent,
    ];
}

function external_checkin_run_queue_worker(int $limit = 25): array {
    $limit = max(1, min(250, $limit));
    $result = ['processed' => 0, 'delivered' => 0, 'failed' => 0];

    external_checkin_requeue_stale_processing_rows();
    $workerId = gethostname() . ':' . getmypid();

    for ($i = 0; $i < $limit; $i++) {
        $row = external_checkin_claim_next_queue_event($workerId);
        if (!$row) {
            break;
        }

        $result['processed']++;
        $delivery = external_checkin_send_queue_event($row);
        if ($delivery['ok']) {
            external_checkin_mark_queue_delivered((int)$row['id'], (int)$delivery['http_status']);
            $result['delivered']++;
            continue;
        }

        external_checkin_mark_queue_failure(
            (int)$row['id'],
            (int)$row['attempt_count'],
            (int)$row['max_attempts'],
            $delivery['http_status'],
            (string)$delivery['error'],
            (bool)$delivery['permanent']
        );
        $result['failed']++;
    }

    return $result;
}


/**
 * The following helpers apply a gateway check-in to the existing Resources
 * Unit intake record and its linked ICS 211 or ICS 218 record.  No browser
 * session or impersonated user is used: the gateway report remains the
 * operational source in the receipt/report audit tables.
 */
function external_checkin_require_mutable_form_record(PDO $pdo, int $formRecordId, string $formName): void {
    $stmt = $pdo->prepare('SELECT status FROM form_records WHERE id=? LIMIT 1');
    $stmt->execute([$formRecordId]);
    $status = (string)($stmt->fetchColumn() ?: '');
    if ($status === 'submitted') {
        throw new InvalidArgumentException($formName . ' is submitted and cannot be changed by an external check-in.');
    }
}

function external_checkin_ics211_header(PDO $pdo, int $incidentId, string $location, string $reportedAt): int {
    $formRecordId = get_or_create_form_record($incidentId, 'ICS211', '211', null);
    external_checkin_require_mutable_form_record($pdo, $formRecordId, 'ICS 211');

    $stmt = $pdo->prepare('SELECT id FROM form_211_headers WHERE form_record_id=? LIMIT 1');
    $stmt->execute([$formRecordId]);
    $headerId = (int)$stmt->fetchColumn();
    if ($headerId > 0) {
        return $headerId;
    }

    $stmt = $pdo->prepare(
        'INSERT INTO form_211_headers
         (form_record_id,check_in_location,start_datetime,page_no,total_pages,prepared_by_position_title)
         VALUES (?,?,?,?,?,?)'
    );
    $stmt->execute([$formRecordId, mb_substr($location, 0, 255), $reportedAt, 1, 1, 'RESL']);
    return (int)$pdo->lastInsertId();
}

function external_checkin_ics218_header(PDO $pdo, int $incidentId, string $reportedAt): int {
    $formRecordId = get_or_create_form_record($incidentId, 'ICS218', '218', null);
    external_checkin_require_mutable_form_record($pdo, $formRecordId, 'ICS 218');

    $stmt = $pdo->prepare('SELECT id FROM form_218_headers WHERE form_record_id=? LIMIT 1');
    $stmt->execute([$formRecordId]);
    $headerId = (int)$stmt->fetchColumn();
    if ($headerId > 0) {
        return $headerId;
    }

    $stmt = $pdo->prepare(
        'INSERT INTO form_218_headers
         (form_record_id,prepared_by_name,prepared_by_position,prepared_at,created_at,updated_at)
         VALUES (?,NULL,?,?,NOW(),NOW())'
    );
    $stmt->execute([$formRecordId, 'RESL', $reportedAt]);
    return (int)$pdo->lastInsertId();
}


/**
 * Apply the receipt details that the Resources Unit receipt/check-in form would
 * normally save, using the authenticated public check-in report as the source.
 *
 * The caller already holds the expected-resource and intake rows in the current
 * transaction, so this is both atomic and safe to retry at the receipt level.
 *
 * @return array{quantity_received:?string,received_at:string,received_by_name:string,received_by_position:string,received_location:string,receipt_note:string}
 */
function external_checkin_record_resource_unit_receipt(
    PDO $pdo,
    array $resource,
    array $data,
    string $reportedAt,
    string $recorderName,
    string $crewLead,
    string $notes
): array {
    $incidentId = (int)($resource['incident_id'] ?? 0);
    $intakeId = (int)($resource['resource_unit_intake_id'] ?? 0);
    if ($incidentId <= 0 || $intakeId <= 0) {
        throw new InvalidArgumentException('The checked-in resource is not linked to Resources Unit intake.');
    }

    $reportedQuantity = $data['personnel_count'] ?? null;
    $quantityReceived = is_scalar($reportedQuantity) && is_numeric((string)$reportedQuantity)
        ? (string)$reportedQuantity
        : trim((string)($resource['quantity_received'] ?? ''));
    $receivedByName = trim($crewLead) !== ''
        ? trim($crewLead)
        : (trim($recorderName) !== '' ? trim($recorderName) : 'Public Check-In Gateway');
    $receivedByPosition = 'External Check-In Gateway';
    $receivedLocation = trim((string)($resource['location_name'] ?? $resource['initial_location'] ?? ''));
    $supplierName = trim((string)($resource['provider_name'] ?? $resource['supplier_name'] ?? ''));
    $supplierContact = implode(' | ', array_filter([
        trim((string)($data['mobile_phone'] ?? '')),
        trim((string)($data['radio_call_sign'] ?? '')),
        trim((string)($data['email'] ?? '')),
    ]));
    if ($supplierContact === '') {
        $supplierContact = trim((string)($resource['supplier_contact'] ?? ''));
    }

    $receiptNote = trim(implode("\n", array_filter([
        'External check-in received from Check-In Gateway.',
        $notes,
    ])));

    $pdo->prepare(
        'UPDATE resource_unit_intake
            SET intake_status = "checked_in",
                quantity_received = CASE WHEN ? <> "" THEN ? ELSE quantity_received END,
                received_at = ?,
                received_by_name = ?,
                received_by_position = ?,
                initial_location = CASE WHEN ? <> "" THEN ? ELSE initial_location END,
                supplier_name = CASE WHEN ? <> "" THEN ? ELSE supplier_name END,
                supplier_contact = CASE WHEN ? <> "" THEN ? ELSE supplier_contact END,
                checked_in_at = ?,
                checked_in_by_name = ?,
                notes = CONCAT(
                    COALESCE(notes, ""),
                    CASE WHEN COALESCE(notes, "") <> "" THEN "\n\n" ELSE "" END,
                    ?
                ),
                updated_at = NOW()
          WHERE id = ? AND incident_id = ?'
    )->execute([
        $quantityReceived,
        $quantityReceived,
        $reportedAt,
        mb_substr($receivedByName, 0, 255),
        $receivedByPosition,
        $receivedLocation,
        mb_substr($receivedLocation, 0, 255),
        $supplierName,
        mb_substr($supplierName, 0, 255),
        $supplierContact,
        mb_substr($supplierContact, 0, 255),
        $reportedAt,
        mb_substr($receivedByName, 0, 255),
        mb_substr($receiptNote, 0, 65000),
        $intakeId,
        $incidentId,
    ]);

    return [
        'quantity_received' => $quantityReceived !== '' ? $quantityReceived : null,
        'received_at' => $reportedAt,
        'received_by_name' => mb_substr($receivedByName, 0, 255),
        'received_by_position' => $receivedByPosition,
        'received_location' => mb_substr($receivedLocation, 0, 255),
        'receipt_note' => mb_substr($receiptNote, 0, 65000),
    ];
}

/**
 * Complete the linked ICS 213RR receipt/fulfillment workflow after a verified
 * public check-in, matching the result of the Resources Unit receipt form.
 *
 * @param array{quantity_received:?string,received_at:string,received_by_name:string,received_by_position:string,received_location:string,receipt_note:string} $receipt
 */
function external_checkin_sync_source_213rr_after_receipt(PDO $pdo, array $resource, array $receipt): void {
    $incidentId = (int)($resource['incident_id'] ?? 0);
    $headerId = (int)($resource['source_213rr_header_id'] ?? 0);
    if ($incidentId <= 0 || $headerId <= 0) {
        return;
    }

    $headerStmt = $pdo->prepare(
        'SELECT h.id, h.workflow_status
           FROM form_213rr_headers h
           INNER JOIN form_records fr ON fr.id = h.form_record_id
          WHERE h.id = ?
            AND fr.incident_id = ?
            AND fr.is_deleted = 0
          LIMIT 1
          FOR UPDATE'
    );
    $headerStmt->execute([$headerId, $incidentId]);
    $header = $headerStmt->fetch();
    if (!$header) {
        throw new InvalidArgumentException('The linked ICS 213RR request could not be found for this incident.');
    }

    $requestedStmt = $pdo->prepare(
        'SELECT COALESCE(SUM(quantity_requested), 0)
           FROM form_213rr_items
          WHERE header_id = ?
            AND deleted_at IS NULL'
    );
    $requestedStmt->execute([$headerId]);
    $requestedQuantity = (float)$requestedStmt->fetchColumn();

    $receivedQuantityRaw = trim((string)($receipt['quantity_received'] ?? ''));
    $receivedQuantity = is_numeric($receivedQuantityRaw) ? (float)$receivedQuantityRaw : 0.0;
    $fulfillmentStatus = 'fulfilled';
    if ($requestedQuantity > 0 && $receivedQuantity > 0 && $receivedQuantity < $requestedQuantity) {
        $fulfillmentStatus = 'partially_fulfilled';
    }

    $receiptNote = (string)($receipt['receipt_note'] ?? '');
    $update = $pdo->prepare(
        'UPDATE form_records fr
         INNER JOIN form_213rr_headers h ON h.form_record_id = fr.id
            SET h.workflow_status = ?,
                h.received_quantity = ?,
                h.received_location = ?,
                h.received_by_user_id = NULL,
                h.received_by_name = ?,
                h.received_by_position = ?,
                h.received_at = ?,
                h.fulfillment_notes = CASE
                    WHEN ? <> "" THEN CONCAT(
                        COALESCE(h.fulfillment_notes, ""),
                        CASE WHEN COALESCE(h.fulfillment_notes, "") <> "" THEN "\n\n" ELSE "" END,
                        ?
                    )
                    ELSE h.fulfillment_notes
                END,
                fr.status = "approved",
                fr.updated_at = NOW()
          WHERE h.id = ?
            AND fr.incident_id = ?
            AND h.workflow_status NOT IN ("closed", "cancelled", "unable_to_fill")'
    );
    $update->execute([
        $fulfillmentStatus,
        $receipt['quantity_received'],
        $receipt['received_location'],
        $receipt['received_by_name'],
        $receipt['received_by_position'],
        $receipt['received_at'],
        $receiptNote,
        $receiptNote,
        $headerId,
        $incidentId,
    ]);

    if ($update->rowCount() < 1) {
        return;
    }

    if (function_exists('ics213rr_log_status')) {
        ics213rr_log_status(
            $headerId,
            (string)($header['workflow_status'] ?? ''),
            $fulfillmentStatus,
            'Resources Unit receipt/check-in recorded automatically from the public Check-In Gateway.',
            ['display_name' => 'System (External Check-In Gateway)']
        );
    }

    $pdo->prepare(
        'UPDATE notifications
            SET is_read = 1,
                read_at = COALESCE(read_at, NOW())
          WHERE incident_id = ?
            AND is_read = 0
            AND related_table = "form_213rr_headers"
            AND related_id = ?
            AND notification_type IN ("resource_unit_intake", "ics213rr_receipt", "ics213rr_logistics")'
    )->execute([$incidentId, $headerId]);
}

function external_checkin_apply_checkin_to_ics_forms(
    PDO $pdo,
    array $resource,
    array $data,
    string $reportedAt,
    string $crewLead,
    string $notes
): void {
    $incidentId = (int)($resource['incident_id'] ?? 0);
    if ($incidentId <= 0) {
        throw new InvalidArgumentException('The expected resource has no valid incident.');
    }

    $category = (string)($resource['resource_category'] ?? 'unknown');
    $personnelCount = filter_var(
        $data['personnel_count'] ?? null,
        FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 0]]
    );
    $location = trim((string)($resource['location_name'] ?? $resource['initial_location'] ?? 'ICP')) ?: 'ICP';
    $contact = implode(' | ', array_filter([
        trim((string)($data['mobile_phone'] ?? '')),
        trim((string)($data['radio_call_sign'] ?? '')),
        trim((string)($data['email'] ?? '')),
    ]));
    $remarks = trim(implode("\n", array_filter([
        'External check-in received from Check-In Gateway.',
        $notes,
    ])));
    $provider = trim((string)($resource['provider_name'] ?? $resource['supplier_name'] ?? ''));
    $resourceName = trim((string)($data['crew_lead'] ?? $crewLead));
    if ($resourceName === '') {
        $resourceName = trim((string)($resource['resource_description'] ?? ''));
    }
    if ($resourceName === '') {
        $resourceName = $provider !== '' ? $provider : 'Checked-in resource';
    }

    $isVehicleOrEquipment = in_array($category, ['vehicle', 'equipment'], true);
    if ($isVehicleOrEquipment) {
        $headerId = external_checkin_ics218_header($pdo, $incidentId, $reportedAt);
        $entryId = (int)($resource['ics218_entry_id'] ?? 0);
        $description = trim((string)($data['resource_description'] ?? $resource['resource_description'] ?? ''));
        if ($description === '') {
            $description = trim((string)($resource['resource_kind'] ?? '') . ' ' . (string)($resource['resource_type'] ?? ''));
        }
        $values = [
            mb_substr($description ?: 'Checked-in equipment', 0, 255),
            mb_substr((string)($resource['resource_kind'] ?? ''), 0, 100),
            mb_substr((string)($resource['resource_type'] ?? ''), 0, 100),
            mb_substr($provider, 0, 255),
            mb_substr($crewLead, 0, 255),
            mb_substr($contact, 0, 255),
            mb_substr($location, 0, 255),
            mb_substr((string)($resource['logistics_order_number'] ?? $resource['source_request_number'] ?? ''), 0, 100),
            $remarks !== '' ? mb_substr($remarks, 0, 65000) : null,
        ];
        $ics218EntrySaved = false;
        if ($entryId > 0) {
            $stmt = $pdo->prepare(
                "UPDATE form_218_entries
                    SET equipment_description=?,kind=?,type=?,owner_agency=?,operator_name=?,contact_info=?,
                        location=?,order_request_number=?,status='received',remarks=?,updated_at=NOW()
                  WHERE id=? AND header_id=? AND deleted_at IS NULL"
            );
            $stmt->execute([...$values, $entryId, $headerId]);
            $ics218EntrySaved = $stmt->rowCount() > 0;
        }
        if (!$ics218EntrySaved) {
            $stmt = $pdo->prepare(
                "INSERT INTO form_218_entries
                 (header_id,equipment_description,kind,type,owner_agency,operator_name,contact_info,location,
                  order_request_number,status,remarks,created_at,updated_at)
                 VALUES (?,?,?,?,?,?,?,?,?,'received',?,NOW(),NOW())"
            );
            $stmt->execute([$headerId, ...$values]);
            $newEntryId = (int)$pdo->lastInsertId();
            $pdo->prepare('UPDATE resource_unit_intake SET ics218_entry_id=? WHERE id=? AND incident_id=?')
                ->execute([$newEntryId, (int)$resource['resource_unit_intake_id'], $incidentId]);
        }

        // A vehicle or equipment resource may arrive with personnel. Keep the
        // equipment inventory in ICS 218 and record the lead contact/crew count
        // separately on ICS 211 when personnel were reported at check-in.
        if ($personnelCount === false || $personnelCount <= 0) {
            return;
        }
    }

    $headerId = external_checkin_ics211_header($pdo, $incidentId, $location, $reportedAt);
    $entryId = (int)($resource['ics211_entry_id'] ?? 0);
    $ics211PersonName = $isVehicleOrEquipment ? $crewLead : $resourceName;
    $values = [
        mb_substr($category, 0, 100),
        mb_substr((string)($resource['resource_kind'] ?? ''), 0, 100),
        mb_substr((string)($resource['resource_type'] ?? ''), 0, 100),
        mb_substr((string)($resource['source_request_number'] ?? ''), 0, 100),
        mb_substr((string)($resource['logistics_order_number'] ?? ''), 0, 100),
        mb_substr((string)($resource['source_request_number'] ?? ''), 0, 100),
        mb_substr($ics211PersonName, 0, 255),
        mb_substr($provider, 0, 255),
        mb_substr($contact, 0, 255),
        mb_substr($crewLead, 0, 255),
        $personnelCount === false ? null : $personnelCount,
        $reportedAt,
        mb_substr($provider, 0, 255),
        null,
        mb_substr($location, 0, 255),
        null,
        1,
        $remarks !== '' ? mb_substr($remarks, 0, 65000) : null,
        $reportedAt,
    ];
    if ($entryId > 0) {
        $stmt = $pdo->prepare(
            'UPDATE form_211_entries
                SET category=?,kind=?,type_st_tf=?,resource_identifier=?,order_request_number=?,ldw_request_number=?,
                    person_name=?,home_agency=?,contact_information=?,leader_name=?,total_personnel=?,arrival_date=?,
                    departure_point=?,method_of_travel=?,incident_assignment=?,other_qualifications=?,
                    sent_to_resource_unit=?,remarks=?,checkin_time=?,updated_at=NOW()
              WHERE id=? AND header_id=? AND deleted_at IS NULL'
        );
        $stmt->execute([...$values, $entryId, $headerId]);
        if ($stmt->rowCount() > 0) {
            return;
        }
    }
    $stmt = $pdo->prepare(
        'INSERT INTO form_211_entries
         (header_id,user_id,category,kind,type_st_tf,resource_identifier,order_request_number,ldw_request_number,
          person_name,home_agency,contact_information,leader_name,total_personnel,arrival_date,departure_point,
          method_of_travel,incident_assignment,other_qualifications,sent_to_resource_unit,remarks,checkin_time,
          created_at,updated_at)
         VALUES (?,NULL,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW(),NOW())'
    );
    $stmt->execute([$headerId, ...$values]);
    $newEntryId = (int)$pdo->lastInsertId();
    $pdo->prepare('UPDATE resource_unit_intake SET ics211_entry_id=? WHERE id=? AND incident_id=?')
        ->execute([$newEntryId, (int)$resource['resource_unit_intake_id'], $incidentId]);
}

function external_checkin_control_request(string $url, array $body): array {
    $config = external_checkin_sync_config();
    if (!filter_var($url, FILTER_VALIDATE_URL) || strtolower((string)parse_url($url, PHP_URL_SCHEME)) !== 'https') {
        throw new RuntimeException('External check-in gateway control URL must use HTTPS.');
    }
    if (!function_exists('curl_init')) {
        throw new RuntimeException('The PHP cURL extension is required for external check-in polling.');
    }

    $json = external_checkin_json_encode($body);
    $timestamp = (string)time();
    $nonce = external_checkin_generate_nonce();
    $signature = external_checkin_request_signature($config['hmac_secret'], $timestamp, $nonce, $json);
    $curl = curl_init($url);
    curl_setopt_array($curl, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $json,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => $config['connect_timeout_seconds'],
        CURLOPT_TIMEOUT => $config['request_timeout_seconds'],
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Accept: application/json',
            'X-CommandBridge-Key-Id: ' . $config['hmac_key_id'],
            'X-CommandBridge-Timestamp: ' . $timestamp,
            'X-CommandBridge-Nonce: ' . $nonce,
            'X-CommandBridge-Signature: ' . $signature,
        ],
    ]);
    $response = curl_exec($curl);
    $error = curl_error($curl);
    $status = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);
    if ($response === false) {
        throw new RuntimeException('Gateway control request failed: ' . $error);
    }
    if ($status < 200 || $status >= 300) {
        throw new RuntimeException('Gateway control request returned HTTP ' . $status . '.');
    }
    try {
        $decoded = json_decode((string)$response, true, 32, JSON_THROW_ON_ERROR);
    } catch (JsonException $e) {
        throw new RuntimeException('Gateway control response was not valid JSON.');
    }
    if (!is_array($decoded) || empty($decoded['ok'])) {
        throw new RuntimeException('Gateway control response was not accepted.');
    }
    return $decoded;
}

function external_checkin_acknowledge_gateway_event(string $messageId, string $outcome, string $error = ''): void {
    $config = external_checkin_sync_config();
    external_checkin_control_request($config['gateway_ack_url'], [
        'protocol_version' => EXTERNAL_CHECKIN_PROTOCOL_VERSION,
        'request_id' => bin2hex(random_bytes(24)),
        'action' => 'events.ack',
        'message_id' => $messageId,
        'outcome' => $outcome,
        'error' => external_checkin_normalize_error($error),
    ]);
}

function external_checkin_poll_gateway(int $limit = 25): array {
    $limit = max(1, min($limit, 100));
    $config = external_checkin_sync_config();
    $result = ['received' => 0, 'processed' => 0, 'rejected' => 0, 'failed' => 0];

    if (!$config['enabled']) {
        return $result;
    }
    if ($config['gateway_pull_url'] === '' || $config['gateway_ack_url'] === '') {
        throw new RuntimeException('Gateway pull and acknowledgement URLs must be configured.');
    }

    $response = external_checkin_control_request($config['gateway_pull_url'], [
        'protocol_version' => EXTERNAL_CHECKIN_PROTOCOL_VERSION,
        'request_id' => bin2hex(random_bytes(24)),
        'action' => 'events.pull',
        'limit' => $limit,
    ]);
    $events = $response['events'] ?? [];
    if (!is_array($events)) {
        throw new RuntimeException('Gateway returned an invalid event list.');
    }

    foreach ($events as $event) {
        if (!is_array($event)) {
            $result['failed']++;
            continue;
        }
        $messageId = trim((string)($event['message_id'] ?? ''));
        if (!preg_match('/^[A-Za-z0-9._:-]{16,128}$/', $messageId)) {
            $result['failed']++;
            continue;
        }

        try {
            $raw = external_checkin_json_encode($event);
            $receipt = external_checkin_record_inbound_receipt([
                'envelope' => $event,
                'raw_body' => $raw,
                'nonce' => 'poll:' . $messageId,
                'remote_ip' => 'gateway-pull',
            ]);
            $result['received'] += $receipt['duplicate'] ? 0 : 1;

            external_checkin_process_pending_inbound_receipts($limit);

            $statusStmt = db()->prepare(
                'SELECT receipt_status, COALESCE(processing_error,"") AS processing_error
                   FROM external_checkin_api_receipts WHERE message_id=? LIMIT 1'
            );
            $statusStmt->execute([$messageId]);
            $stored = $statusStmt->fetch();
            $status = (string)($stored['receipt_status'] ?? '');
            if ($status === 'processed') {
                external_checkin_acknowledge_gateway_event($messageId, 'processed');
                $result['processed']++;
            } elseif ($status === 'rejected') {
                external_checkin_acknowledge_gateway_event($messageId, 'rejected', (string)($stored['processing_error'] ?? ''));
                $result['rejected']++;
            } else {
                external_checkin_acknowledge_gateway_event($messageId, 'retry', 'Internal processing did not complete.');
                $result['failed']++;
            }
        } catch (Throwable $e) {
            try {
                external_checkin_acknowledge_gateway_event($messageId, 'retry', $e->getMessage());
            } catch (Throwable $ackError) {
                security_log($ackError, 'external_checkin_gateway_ack');
            }
            security_log($e, 'external_checkin_gateway_poll');
            $result['failed']++;
        }
    }
    return $result;
}
