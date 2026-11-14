<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/security.php';
require_csrf_for_post();

require_once dirname(__DIR__, 2) . '/includes/layout.php';
require_once dirname(__DIR__, 2) . '/includes/external_checkin_sync.php';
require_login();

$incident = active_incident();
$incidentId = (int)($incident['id'] ?? 0);

if ($incidentId > 0) {
    require_active_operational_period($incidentId);
}
$user = current_user();
if ($incidentId <= 0) { render_header('ICS 213RR'); echo '<div class="card"><p>No active incident.</p></div>'; render_footer(); exit; }

$action = (string)($_GET['action'] ?? 'list');
$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0); // header id
$currentOp = current_operational_period($incidentId);
$currentOpId = (int)($currentOp['id'] ?? 0);


function rr_kind_type_catalog(): array {
    return resource_kind_type_catalog();
}


function rr_nullable_decimal($value): ?float {
    if ($value === null) { return null; }
    $value = trim((string)$value);
    if ($value === '') { return null; }
    $value = str_replace([',', '$'], '', $value);
    return is_numeric($value) ? (float)$value : null;
}

function rr_get(int $id): ?array {
    $sql = "SELECT h.*, fr.incident_id, fr.operational_period_id, COALESCE(h.workflow_status, fr.status) AS workflow_status, fr.status AS form_record_status,
                   fr.prepared_by_user_id, fr.prepared_by_name, fr.prepared_by_position_title,
                   fr.prepared_at, fr.submitted_at, fr.approved_at, fr.current_approver_user_id,
                   fr.created_at AS record_created_at, fr.updated_at AS record_updated_at
            FROM form_213rr_headers h
            INNER JOIN form_records fr ON fr.id = h.form_record_id
            WHERE h.id=? AND fr.is_deleted=0";
    $stmt = db()->prepare($sql);
    $stmt->execute([$id]);
    $r=$stmt->fetch(); return $r ?: null;
}
function rr_items(int $id): array {
    $stmt = db()->prepare("SELECT * FROM form_213rr_items WHERE header_id=? AND deleted_at IS NULL ORDER BY line_number,id");
    $stmt->execute([$id]); return $stmt->fetchAll();
}
function rr_status(array $rr=null): string { return (string)($rr['workflow_status'] ?? 'draft'); }
function rr_form_record_status(string $workflowStatus): string {
    if (in_array($workflowStatus, ['draft','returned_for_revision'], true)) { return 'draft'; }
    if ($workflowStatus === 'section_chief_review') { return 'submitted'; }
    if (in_array($workflowStatus, ['cancelled','closed'], true)) { return 'void'; }
    if ($workflowStatus === 'unable_to_fill') { return 'rejected'; }
    return 'approved';
}
function rr_update_status(int $id, string $status, string $comment=''): void {
    $old = rr_get($id);
    $recordStatus = rr_form_record_status($status);
    $nowField = '';
    if ($status === 'section_chief_review') { $nowField = ', fr.submitted_at=NOW()'; }
    if ($status === 'logistics_review') { $nowField = ', fr.approved_at=NOW()'; }
    db()->prepare("UPDATE form_records fr INNER JOIN form_213rr_headers h ON h.form_record_id=fr.id
                   SET h.workflow_status=?, fr.status=?, fr.updated_at=NOW() $nowField
                   WHERE h.id=?")
        ->execute([$status,$recordStatus,$id]);
    ics213rr_log_status($id, (string)($old['workflow_status']??''), $status, $comment);
}


function rr_cancel_request(int $id, string $reason): void {
    $reason = trim($reason);
    if ($reason === '') {
        $reason = 'No cancellation reason provided.';
    }
    $user = current_user();
    $cancelledByUserId = (int)($user['id'] ?? 0) ?: null;
    $cancelledByName = trim((string)($user['display_name'] ?? $user['username'] ?? ''));
    $old = rr_get($id);
    $recordStatus = rr_form_record_status('cancelled');

    db()->prepare("UPDATE form_records fr INNER JOIN form_213rr_headers h ON h.form_record_id=fr.id
                   SET h.workflow_status='cancelled',
                       h.cancelled_at=NOW(),
                       h.cancelled_by_user_id=?,
                       h.cancelled_by_name=?,
                       h.cancel_reason=?,
                       fr.status=?,
                       fr.updated_at=NOW()
                   WHERE h.id=?")
        ->execute([$cancelledByUserId, $cancelledByName, $reason, $recordStatus, $id]);

    // Close any active notifications for this request so cancelled items do not remain as pending work.
    try {
        $formRecordId = (int)($old['form_record_id'] ?? 0);
        db()->prepare("UPDATE notifications
                       SET is_read=1, read_at=COALESCE(read_at,NOW())
                       WHERE is_read=0
                         AND (
                              (related_table='form_213rr_headers' AND related_id=?)
                              OR form_record_id=?
                         )")
            ->execute([$id, $formRecordId]);
    } catch (Throwable $e) {
        error_log('ICS213RR cancel notification cleanup failed: ' . $e->getMessage());
    }

    ics213rr_log_status($id, (string)($old['workflow_status'] ?? ''), 'cancelled', 'Cancelled: ' . $reason);
}

function rr_close_section_approval_notifications(int $id): void {
    // Once a 213RR is approved and routed forward, close any outstanding approval notifications
    // for the prior approver role (PSC/IC/generic). This prevents stale approval prompts from
    // remaining on the approving role's dashboard after the request has moved to LSC.
    try {
        $rr = rr_get($id);
        $formRecordId = (int)($rr['form_record_id'] ?? 0);
        $sql = "UPDATE notifications
                SET is_read=1, read_at=NOW()
                WHERE incident_id=?
                  AND is_read=0
                  AND notification_type='ics213rr_section_approval'
                  AND (
                        (related_table='form_213rr_headers' AND related_id=?)
                        OR form_record_id=?
                      )";
        db()->prepare($sql)->execute([(int)($rr['incident_id'] ?? 0), $id, $formRecordId]);
    } catch (Throwable $e) {
        error_log('ICS213RR close approval notifications failed: ' . $e->getMessage());
    }
}

function rr_next_request_number_fixed(int $incidentId): string {
    $stmt = db()->prepare("SELECT COUNT(*) AS c FROM form_213rr_headers h INNER JOIN form_records fr ON fr.id=h.form_record_id WHERE fr.incident_id=? AND fr.form_type='ICS213RR'");
    $stmt->execute([$incidentId]);
    $n = ((int)($stmt->fetch()['c'] ?? 0)) + 1;
    return 'RR-' . date('Y') . '-' . str_pad((string)$n, 4, '0', STR_PAD_LEFT);
}
function rr_can_basic(): bool { return (bool)current_user(); }

function rr_current_user_role_codes_for_incident(int $incidentId): array {
    $u = current_user();
    if (!$u) { return []; }
    $codes = [];
    if (!empty($u['role_code'])) { $codes[] = strtoupper((string)$u['role_code']); }
    try {
        $codes = array_merge($codes, get_user_role_codes($incidentId, (int)($u['id'] ?? 0)));
    } catch (Throwable $e) {}
    return array_values(array_unique(array_filter(array_map('strtoupper', $codes))));
}

function rr_submit_routing_for_current_user(int $incidentId): array {
    $codes = rr_current_user_role_codes_for_incident($incidentId);
    if (array_intersect($codes, ['IC','PSC','LSC','FSC'])) {
        return ['status' => 'logistics_review', 'role_code' => 'LSC', 'type' => 'ics213rr_logistics', 'subject' => 'ICS 213RR ready for Logistics', 'message' => 'is from a Section Chief/IC and is ready for Logistics review.', 'flash' => 'Submitted directly to Logistics.'];
    }
    if (array_intersect($codes, ['LOFR','SOFR','IOFR'])) {
        return ['status' => 'section_chief_review', 'role_code' => 'IC', 'type' => 'ics213rr_ic_approval', 'subject' => 'ICS 213RR requires IC approval', 'message' => 'is from Command Staff and is awaiting Incident Commander approval.', 'flash' => 'Submitted to the Incident Commander for approval.'];
    }
    return ['status' => 'section_chief_review', 'role_code' => null, 'type' => 'ics213rr_section_approval', 'subject' => 'ICS 213RR requires approval', 'message' => 'is awaiting Section Chief approval.', 'flash' => 'Submitted for Section Chief approval.'];
}


function rr_can_edit_request_record(?array $rr): bool {
    if (!$rr) { return true; }
    $status = rr_status($rr);
    // After submission/approval/routing, preserve the original request as an audit record.
    // Material changes should be handled by cancelling/returning the request and creating a new 213RR.
    return in_array($status, ['draft','returned_for_revision'], true);
}

function rr_resource_category_guess(array $rr, array $item): string {
    $text = strtolower(trim(
        (string)($item['item_kind'] ?? '') . ' ' .
        (string)($item['unit_of_issue'] ?? '') . ' ' .
        (string)($item['item_description'] ?? '') . ' ' .
        (string)($rr['delivery_location'] ?? '')
    ));
    if ($text === '') { return 'unknown'; }
    if (preg_match('/\b(person|personnel|operator|specialist|staff|team member)\b/', $text)) { return 'personnel'; }
    if (preg_match('/\b(crew|team|task force|strike team)\b/', $text)) { return 'crew'; }
    if (preg_match('/\b(truck|vehicle|pickup|bus|van|grader|loader|dozer|tractor|trailer|tanker|water tender)\b/', $text)) { return 'vehicle'; }
    if (preg_match('/\b(pump|generator|tower|light tower|equipment|saw|skid steer|excavator|compressor|trailer)\b/', $text)) { return 'equipment'; }
    if (preg_match('/\b(radio|repeater|starlink|satellite|communications|comms|cache|antenna)\b/', $text)) { return 'communications'; }
    if (preg_match('/\b(facility|hall|office|shop|shelter|reception centre|reception center)\b/', $text)) { return 'facility'; }
    if (preg_match('/\b(water|meal|food|sandbag|sandbags|gloves|ppe|fuel card|paper|pen|toner|supplies|batteries)\b/', $text)) { return 'unknown'; }
    return 'unknown';
}

function rr_resource_intake_get_by_header(int $headerId): ?array {
    try {
        $stmt = db()->prepare("SELECT * FROM resource_unit_intake WHERE source_213rr_header_id=? LIMIT 1");
        $stmt->execute([$headerId]);
        $row = $stmt->fetch();
        return $row ?: null;
    } catch (Throwable $e) {
        return null;
    }
}

function rr_resource_intake_upsert(int $headerId, string $category, string $notes): ?int {
    $rr = rr_get($headerId);
    if (!$rr) { return null; }
    $item = rr_items($headerId)[0] ?? [];
    $user = current_user();

    $allowed = ['personnel','crew','vehicle','equipment','communications','facility','other','unknown'];
    if (!in_array($category, $allowed, true)) { $category = 'unknown'; }

    $quantity = null;
    if (isset($rr['received_quantity']) && $rr['received_quantity'] !== null && $rr['received_quantity'] !== '') {
        $quantity = (float)$rr['received_quantity'];
    } elseif (isset($item['quantity_requested']) && $item['quantity_requested'] !== null && $item['quantity_requested'] !== '') {
        $quantity = (float)$item['quantity_requested'];
    }

    $combinedNotes = trim($notes);
    $fulfillmentNotes = trim((string)($rr['fulfillment_notes'] ?? ''));
    if ($fulfillmentNotes !== '') {
        $combinedNotes = trim($combinedNotes . ($combinedNotes !== '' ? "\n\n" : '') . 'Receipt notes: ' . $fulfillmentNotes);
    }

    $stmt = db()->prepare("
        INSERT INTO resource_unit_intake
          (incident_id, source_form_record_id, source_213rr_header_id, source_request_number,
           intake_status, resource_category, quantity_received, resource_kind, resource_type, resource_description,
           initial_location, received_at, received_by_name, received_by_position, supplier_name, supplier_contact,
           logistics_order_number, notes, created_by_user_id, created_by_name, created_at, updated_at)
        VALUES
          (?, ?, ?, ?, 'new', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
        ON DUPLICATE KEY UPDATE
          id=LAST_INSERT_ID(id),
          intake_status=CASE WHEN intake_status='converted' THEN intake_status ELSE 'new' END,
          resource_category=VALUES(resource_category),
          quantity_received=VALUES(quantity_received),
          resource_kind=VALUES(resource_kind),
          resource_type=VALUES(resource_type),
          resource_description=VALUES(resource_description),
          initial_location=VALUES(initial_location),
          received_at=VALUES(received_at),
          received_by_name=VALUES(received_by_name),
          received_by_position=VALUES(received_by_position),
          supplier_name=VALUES(supplier_name),
          supplier_contact=VALUES(supplier_contact),
          logistics_order_number=VALUES(logistics_order_number),
          notes=VALUES(notes),
          updated_at=NOW()
    ");
    $stmt->execute([
        (int)($rr['incident_id'] ?? 0),
        (int)($rr['form_record_id'] ?? 0) ?: null,
        $headerId,
        (string)($rr['request_number'] ?? ''),
        $category,
        $quantity,
        (string)($item['item_kind'] ?? ''),
        (string)($item['unit_of_issue'] ?? ''),
        (string)($item['item_description'] ?? ''),
        (string)($rr['received_location'] ?? $rr['delivery_location'] ?? ''),
        !empty($rr['received_at']) ? (string)$rr['received_at'] : null,
        (string)($rr['received_by_name'] ?? ''),
        (string)($rr['received_by_position'] ?? ''),
        (string)($rr['supplier_name'] ?? ''),
        (string)($rr['supplier_contact'] ?? ''),
        (string)($rr['logistics_order_number'] ?? ''),
        $combinedNotes,
        (int)($user['id'] ?? 0) ?: null,
        (string)($user['display_name'] ?? '')
    ]);
    return (int)db()->lastInsertId();
}


function rr_next_logistics_order_number(int $incidentId): string {
    $prefix = 'LOG-' . date('mdy') . '-';
    $stmt = db()->prepare("
        SELECT h.logistics_order_number
        FROM form_213rr_headers h
        INNER JOIN form_records fr ON fr.id = h.form_record_id
        WHERE fr.incident_id = ?
          AND h.logistics_order_number LIKE ?
        ORDER BY h.logistics_order_number DESC
        LIMIT 1
    ");
    $stmt->execute([$incidentId, $prefix . '%']);
    $last = (string)($stmt->fetchColumn() ?: '');
    $next = 1;
    if (preg_match('/^' . preg_quote($prefix, '/') . '(\d+)$/', $last, $matches)) {
        $next = ((int)$matches[1]) + 1;
    }
    return $prefix . str_pad((string)$next, 4, '0', STR_PAD_LEFT);
}


function rr_kind_type_summary(?array $rr, ?array $item = null): string {
    // Be defensive: notification/logistics handlers may call this after a status update
    // or from a stale link. If the header cannot be reloaded, still return a safe label
    // instead of throwing a TypeError and causing a 500.
    if ($item === null && is_array($rr)) {
        $items = rr_items((int)($rr['id'] ?? 0));
        $item = $items[0] ?? [];
    }
    if ($item === null) {
        $item = [];
    }
    $kind = trim((string)($item['item_kind'] ?? ''));
    $type = trim((string)($item['unit_of_issue'] ?? ''));
    $summary = trim($kind . ($kind !== '' && $type !== '' ? ' / ' : '') . $type);
    if ($summary === '') {
        $summary = trim((string)($item['item_description'] ?? ''));
    }
    return $summary !== '' ? $summary : 'Resource request';
}

function rr_get_or_next_logistics_order_number(array $rr, int $incidentId): string {
    $existing = trim((string)($rr['logistics_order_number'] ?? ''));
    return $existing !== '' ? $existing : rr_next_logistics_order_number($incidentId);
}

function rr_create_resource_intake_from_approval(int $headerId): ?int {
    $rr = rr_get($headerId);
    if (!$rr) { return null; }

    $trackingRequired = !empty($rr['resource_tracking_required']);
    $category = (string)($rr['resource_category'] ?? 'unknown');
    if (!$trackingRequired || $category === 'no_tracking_required') {
        return null;
    }

    $allowed = ['personnel','crew','vehicle','equipment','communications','facility','other','unknown'];
    if (!in_array($category, $allowed, true)) { $category = 'unknown'; }

    $item = rr_items($headerId)[0] ?? [];
    $user = current_user();
    $location = trim((string)($rr['expected_checkin_location'] ?? ''));
    if ($location === '') { $location = trim((string)($rr['delivery_location'] ?? '')); }

    $notes = trim((string)($rr['resource_tracking_notes'] ?? ''));
    $logNotes = trim((string)($rr['logistics_notes'] ?? ''));
    if ($logNotes !== '') {
        $notes = trim($notes . ($notes !== '' ? "\n\n" : '') . 'Logistics notes: ' . $logNotes);
    }

    $stmt = db()->prepare("
        INSERT INTO resource_unit_intake
          (incident_id, source_form_record_id, source_213rr_header_id, source_request_number,
           intake_status, resource_category, quantity_received, resource_kind, resource_type, resource_description,
           initial_location, received_at, received_by_name, received_by_position, supplier_name, supplier_contact,
           logistics_order_number, notes, created_by_user_id, created_by_name, created_at, updated_at)
        VALUES
          (?, ?, ?, ?, 'new', ?, NULL, ?, ?, ?, ?, NULL, NULL, NULL, ?, ?, ?, ?, ?, ?, NOW(), NOW())
        ON DUPLICATE KEY UPDATE
          id=LAST_INSERT_ID(id),
          intake_status=CASE WHEN intake_status IN ('converted','checked_in','received') THEN intake_status ELSE 'new' END,
          resource_category=VALUES(resource_category),
          quantity_received=NULL,
          resource_kind=VALUES(resource_kind),
          resource_type=VALUES(resource_type),
          resource_description=VALUES(resource_description),
          initial_location=VALUES(initial_location),
          supplier_name=VALUES(supplier_name),
          supplier_contact=VALUES(supplier_contact),
          logistics_order_number=VALUES(logistics_order_number),
          notes=VALUES(notes),
          updated_at=NOW()
    ");
    $stmt->execute([
        (int)($rr['incident_id'] ?? 0),
        (int)($rr['form_record_id'] ?? 0) ?: null,
        $headerId,
        (string)($rr['request_number'] ?? ''),
        $category,
        (string)($item['item_kind'] ?? ''),
        (string)($item['unit_of_issue'] ?? ''),
        (string)($item['item_description'] ?? ''),
        $location,
        (string)($rr['supplier_name'] ?? ''),
        (string)($rr['supplier_contact'] ?? ''),
        (string)($rr['logistics_order_number'] ?? ''),
        $notes,
        (int)($user['id'] ?? 0) ?: null,
        (string)($user['display_name'] ?? '')
    ]);
    return (int)db()->lastInsertId();
}


function rr_sync_expected_resource_from_logistics(int $headerId, int $resourceIntakeId, bool $enqueueSnapshot = true): void {
    $rr = rr_get($headerId);
    if (!$rr || $resourceIntakeId <= 0) {
        return;
    }

    $incidentId = (int)($rr['incident_id'] ?? 0);
    $locationId = (int)($rr['expected_checkin_location_id'] ?? 0);
    if ($incidentId <= 0 || $locationId <= 0) {
        throw new RuntimeException('An active receiving location is required before the expected resource can be created.');
    }

    $locationStmt = db()->prepare('SELECT id FROM external_checkin_locations
        WHERE id=? AND incident_id=? AND lifecycle_status="active"');
    $locationStmt->execute([$locationId, $incidentId]);
    if (!$locationStmt->fetchColumn()) {
        throw new RuntimeException('The selected receiving location is no longer active.');
    }

    $providerName = trim((string)($rr['supplier_name'] ?? ''));
    if ($providerName === '') {
        $providerName = 'Pending provider confirmation';
    }

    $user = current_user();
    $userId = (int)($user['id'] ?? 0) ?: null;

    db()->prepare('INSERT INTO external_checkin_expected_resources
        (incident_id,location_id,resource_unit_intake_id,provider_name,publication_status,created_by,updated_by)
        VALUES (?,?,?,?,"draft",?,?)
        ON DUPLICATE KEY UPDATE
            location_id=VALUES(location_id),
            provider_name=VALUES(provider_name),
            publication_status=CASE
                WHEN publication_status IN ("checked_in","demobilized") THEN publication_status
                ELSE "draft"
            END,
            updated_by=VALUES(updated_by),
            updated_at=NOW()')
        ->execute([$incidentId, $locationId, $resourceIntakeId, $providerName, $userId, $userId]);

    $expectedResourceStmt = db()->prepare('SELECT id FROM external_checkin_expected_resources
        WHERE resource_unit_intake_id=? LIMIT 1');
    $expectedResourceStmt->execute([$resourceIntakeId]);
    $expectedResourceId = (int)$expectedResourceStmt->fetchColumn();
    if ($expectedResourceId <= 0) {
        throw new RuntimeException('The expected resource could not be queued for synchronization.');
    }

    db()->prepare('INSERT INTO external_checkin_events (incident_id,event_type,event_payload,created_by)
        VALUES (?,?,?,?)')
        ->execute([
            $incidentId,
            'expected_resource_created_from_213rr',
            json_encode([
                'header_id' => $headerId,
                'resource_unit_intake_id' => $resourceIntakeId,
                'location_id' => $locationId,
                'expected_resource_id' => $expectedResourceId
            ], JSON_UNESCAPED_SLASHES),
            $userId
        ]);

    if ($enqueueSnapshot) {
        external_checkin_enqueue_expected_resource_snapshot($expectedResourceId);
    }
}


function rr_publish_expected_resource_after_logistics_approval(int $headerId, int $resourceIntakeId): bool {
    $rr = rr_get($headerId);
    if (!$rr || $resourceIntakeId <= 0) {
        return false;
    }

    $incidentId = (int)($rr['incident_id'] ?? 0);
    if ($incidentId <= 0) {
        return false;
    }

    $pdo = db();
    $user = current_user();
    $userId = (int)($user['id'] ?? 0) ?: null;
    $releaseName = 'System release after Logistics approval';

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare(
            'SELECT id, publication_status
             FROM external_checkin_expected_resources
             WHERE incident_id=? AND resource_unit_intake_id=?
             LIMIT 1
             FOR UPDATE'
        );
        $stmt->execute([$incidentId, $resourceIntakeId]);
        $expectedResource = $stmt->fetch() ?: [];
        $expectedResourceId = (int)($expectedResource['id'] ?? 0);
        $publicationStatus = (string)($expectedResource['publication_status'] ?? '');

        if ($expectedResourceId <= 0) {
            throw new RuntimeException('The expected resource could not be released for check-in.');
        }

        $pdo->prepare(
            'UPDATE resource_unit_intake
             SET intake_status=CASE
                    WHEN intake_status IN ("converted","checked_in","received") THEN intake_status
                    ELSE "reviewed"
                 END,
                 reviewed_by_user_id=CASE
                    WHEN intake_status IN ("converted","checked_in","received") THEN reviewed_by_user_id
                    ELSE NULL
                 END,
                 reviewed_by_name=CASE
                    WHEN intake_status IN ("converted","checked_in","received") THEN reviewed_by_name
                    ELSE ?
                 END,
                 reviewed_at=CASE
                    WHEN intake_status IN ("converted","checked_in","received") THEN reviewed_at
                    ELSE NOW()
                 END,
                 updated_at=NOW()
             WHERE id=? AND incident_id=?'
        )->execute([$releaseName, $resourceIntakeId, $incidentId]);

        if (in_array($publicationStatus, ['checked_in', 'demobilized'], true)) {
            $pdo->commit();
            return false;
        }

        $publishedNow = $publicationStatus !== 'published';
        if ($publishedNow) {
            $pdo->prepare(
                'UPDATE external_checkin_expected_resources
                 SET publication_status="published",
                     published_at=NOW(),
                     withdrawn_at=NULL,
                     withdrawn_by=NULL,
                     updated_by=?,
                     updated_at=NOW()
                 WHERE id=? AND incident_id=?'
            )->execute([$userId, $expectedResourceId, $incidentId]);

            $eventPayload = json_encode([
                'header_id' => $headerId,
                'expected_resource_id' => $expectedResourceId,
                'resource_unit_intake_id' => $resourceIntakeId,
                'publication_status' => 'published',
                'reason' => 'Automatically released after Logistics approval',
            ], JSON_UNESCAPED_SLASHES);

            $pdo->prepare(
                'INSERT INTO external_checkin_events (incident_id,event_type,event_payload,created_by)
                 VALUES (?,?,?,?)'
            )->execute([
                $incidentId,
                'expected_resource_published_on_logistics_approval',
                $eventPayload === false ? null : $eventPayload,
                $userId,
            ]);

            external_checkin_enqueue_expected_resource_snapshot($expectedResourceId);
        }

        $pdo->commit();
        return $publishedNow;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}


function rr_resource_intake_mark_no_tracking(int $headerId, string $notes = ''): void {
    try {
        $u = current_user();
        db()->prepare("
            UPDATE resource_unit_intake
            SET intake_status='no_tracking_required',
                notes=CASE WHEN ? <> '' THEN CONCAT(COALESCE(notes,''), CASE WHEN COALESCE(notes,'') <> '' THEN '\n\n' ELSE '' END, ?) ELSE notes END,
                reviewed_by_user_id=?, reviewed_by_name=?, reviewed_at=NOW(), updated_at=NOW()
            WHERE source_213rr_header_id=? AND intake_status <> 'converted'
        ")->execute([
            $notes,
            'Marked no tracking required from 213RR receipt: ' . $notes,
            (int)($u['id'] ?? 0) ?: null,
            (string)($u['display_name'] ?? ''),
            $headerId
        ]);
    } catch (Throwable $e) {}
}



try {
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postAction = (string)($_POST['post_action'] ?? '');
    if ($postAction === 'create' || $postAction === 'save') {
        if (!rr_can_basic()) { throw new RuntimeException('Not authorized.'); }
        $status = ($postAction === 'create') ? 'draft' : (string)($_POST['status'] ?? 'draft');
        if ($id <= 0) {
            $requestNo = rr_next_request_number_fixed($incidentId);
            $stmt=db()->prepare("INSERT INTO form_records
              (incident_id,operational_period_id,form_type,form_number,status,prepared_by_user_id,prepared_by_name,prepared_by_position_title,prepared_at,created_at,updated_at)
              VALUES (?,?,?,?,?,?,?,?,NOW(),NOW(),NOW())");
            $stmt->execute([$incidentId,$currentOpId ?: null,'ICS213RR','213RR',$status,(int)($user['id']??0),(string)($_POST['requested_by_name']??$user['display_name']??''),(string)($_POST['requested_by_position']??$user['role_code']??'')]);
            $formRecordId=(int)db()->lastInsertId();
            $stmt=db()->prepare("INSERT INTO form_213rr_headers
              (form_record_id,request_number,order_datetime,delivery_location,needed_by_datetime,requested_for_section,priority_level,workflow_status,
               resource_tracking_required,resource_category,expected_checkin_location,resource_tracking_notes)
              VALUES (?,?,?,?,?,?,?,?,0,'unknown',NULL,NULL)");
            $stmt->execute([$formRecordId,$requestNo,date('Y-m-d H:i:s'),(string)($_POST['delivery_location']??''),($_POST['arrival_requested_at']??null)?:null,(string)($_POST['requested_by_position']??$user['role_code']??''),(string)($_POST['priority']??'routine'),$status]);
            $id=(int)db()->lastInsertId();
            ics213rr_log_status($id,null,$status,'Created');
        } else {
            $rr=rr_get($id); if (!$rr) { throw new RuntimeException('Request not found.'); }
            if (!rr_can_edit_request_record($rr)) { throw new RuntimeException('This 213RR has already been submitted/routed and can no longer be edited. Cancel or return it and create a new 213RR if the request needs material changes.'); }
            db()->prepare("UPDATE form_records fr INNER JOIN form_213rr_headers h ON h.form_record_id=fr.id
                           SET fr.prepared_by_name=?, fr.prepared_by_position_title=?, fr.updated_at=NOW()
                           WHERE h.id=?")
              ->execute([(string)($_POST['requested_by_name']??''),(string)($_POST['requested_by_position']??''),$id]);
            db()->prepare("UPDATE form_213rr_headers
                           SET delivery_location=?, needed_by_datetime=?, requested_for_section=?, priority_level=?,
                               resource_tracking_required=?, resource_category=?, expected_checkin_location=?, resource_tracking_notes=?
                           WHERE id=?")
              ->execute([
                  (string)($_POST['delivery_location']??''),
                  ($_POST['arrival_requested_at']??null)?:null,
                  (string)($_POST['requested_by_position']??''),
                  (string)($_POST['priority']??'routine'),
                  ((string)($_POST['resource_tracking_required'] ?? '') === '1' && (string)($_POST['resource_category'] ?? 'unknown') !== 'no_tracking_required') ? 1 : 0,
                  in_array((string)($_POST['resource_category'] ?? 'unknown'), ['personnel','crew','vehicle','equipment','communications','facility','other','no_tracking_required','unknown'], true) ? (string)($_POST['resource_category'] ?? 'unknown') : 'unknown',
                  (string)($_POST['expected_checkin_location']??''),
                  (string)($_POST['resource_tracking_notes']??''),
                  $id
              ]);
        }
        db()->prepare("UPDATE form_213rr_items SET deleted_at=NOW() WHERE header_id=?")->execute([$id]);
        $stmt=db()->prepare("INSERT INTO form_213rr_items
          (header_id,line_number,item_description,item_kind,quantity_requested,unit_of_issue,estimated_cost,requested_by_name,requested_by_position,justification,remarks,created_at,updated_at)
          VALUES (?,?,?,?,?,?,?,?,?,?,?,NOW(),NOW())");
        $stmt->execute([$id,1,(string)($_POST['item_description']??''),(string)($_POST['kind']??''),(float)($_POST['quantity']??0),(string)($_POST['resource_type']??''), rr_nullable_decimal($_POST['estimated_cost'] ?? null), (string)($_POST['requested_by_name']??''),(string)($_POST['requested_by_position']??''),(string)($_POST['suitable_substitutes']??''),(string)($_POST['suggested_sources']??'')]);
        flash('success','ICS 213RR saved.');
        header('Location: '.app_url('/forms/ics213rr_v2/index.php?action=view&id='.$id)); exit;
    }
    if ($postAction === 'submit') {
        $routing = rr_submit_routing_for_current_user($incidentId);
        rr_update_status($id, (string)$routing['status'], (string)$routing['flash']);
        $rr=rr_get($id); $link=app_url('/forms/ics213rr_v2/index.php?action=view&id='.$id);
        ics213rr_create_notification($incidentId, null, $routing['role_code'], (string)$routing['type'], (string)$routing['subject'], (string)$rr['request_number'].' - '.rr_kind_type_summary($rr).' '.(string)$routing['message'], $link, $id);
        flash('success', (string)$routing['flash']); header('Location: '.$link); exit;
    }
    if ($postAction === 'section_approve') {
        if (!current_user_can_ics213rr_action('section_approve',$incidentId)) { throw new RuntimeException('Not authorized.'); }
        db()->prepare("INSERT INTO form_signatures (form_record_id,signer_user_id,signer_name,signer_position_title,signature_type,signature_method,signed_at,created_at)
                       SELECT form_record_id,?,?,?,'approved_by','system_attestation',NOW(),NOW() FROM form_213rr_headers WHERE id=?")
            ->execute([(int)($user['id']??0),(string)($user['display_name']??''),(string)($user['role_code']??''),$id]);
        rr_update_status($id,'logistics_review','Section Chief approved');
        rr_close_section_approval_notifications($id);
        $rr=rr_get($id); $requestNo = (string)($rr['request_number'] ?? 'ICS 213RR'); ics213rr_create_notification($incidentId,null,'LSC','ics213rr_logistics','ICS 213RR ready for Logistics',$requestNo.' - '.rr_kind_type_summary($rr).' requires Logistics review.',app_url('/forms/ics213rr_v2/index.php?action=view&id='.$id),$id);
        flash('success','Approved and routed to Logistics.'); header('Location: '.app_url('/forms/ics213rr_v2/index.php?action=view&id='.$id)); exit;
    }
    if ($postAction === 'logistics_save') {
        if (!current_user_can_ics213rr_action('logistics',$incidentId)) { throw new RuntimeException('Not authorized.'); }
        $logisticsOrderNumber = trim((string)($_POST['logistics_order_number'] ?? ''));
        if ($logisticsOrderNumber === '') {
            $logisticsOrderNumber = rr_next_logistics_order_number($incidentId);
        }

        $expectedCheckinLocationId = (int)($_POST['expected_checkin_location_id'] ?? 0);
        $locationStmt = db()->prepare('SELECT id, location_name, street_address
            FROM external_checkin_locations
            WHERE id=? AND incident_id=? AND lifecycle_status="active"');
        $locationStmt->execute([$expectedCheckinLocationId, $incidentId]);
        $expectedCheckinLocation = $locationStmt->fetch();

        if (!$expectedCheckinLocation) {
            throw new RuntimeException('Select an active expected check-in / receiving location before saving Logistics.');
        }

        $expectedCheckinLocationText = trim((string)$expectedCheckinLocation['location_name']);
        $expectedCheckinLocationAddress = trim((string)($expectedCheckinLocation['street_address'] ?? ''));
        if ($expectedCheckinLocationAddress !== '') {
            $expectedCheckinLocationText .= ' — ' . $expectedCheckinLocationAddress;
        }

        db()->prepare("UPDATE form_213rr_headers
                       SET logistics_order_number=?, supplier_contact=?, supplier_name=?, logistics_notes=?,
                           resource_tracking_required=?, resource_category=?, expected_checkin_location_id=?, expected_checkin_location=?, resource_tracking_notes=?,
                           order_placed_by=?, logistics_signed_by_user_id=?, logistics_signed_by_name=?,
                           logistics_signed_by_position=?, logistics_signed_at=NOW()
                       WHERE id=?")
           ->execute([
               $logisticsOrderNumber,
               (string)($_POST['supplier_contact'] ?? ''),
               (string)($_POST['supplier_name'] ?? ''),
               (string)($_POST['logistics_notes'] ?? ''),
               ((string)($_POST['resl_routing'] ?? 'no_tracking_required') !== 'no_tracking_required') ? 1 : 0,
               in_array((string)($_POST['resource_category'] ?? 'unknown'), ['personnel','crew','vehicle','equipment','communications','facility','other','unknown','no_tracking_required'], true) ? (string)($_POST['resource_category'] ?? 'unknown') : 'unknown',
               $expectedCheckinLocationId,
               $expectedCheckinLocationText,
               trim((string)($_POST['resource_tracking_notes'] ?? '')),
               (string)($_POST['order_placed_by'] ?? ''),
               (int)($user['id'] ?? 0) ?: null,
               (string)($user['display_name'] ?? ''),
               (string)($user['role_code'] ?? ''),
               $id
           ]);
        rr_update_status($id,'awaiting_receipt','Logistics processed; operational/resource tracking may proceed in parallel with Finance/Admin review. '.(string)($_POST['logistics_notes']??''));
        $rr = rr_get($id); // Reload after status update so notification summaries have a valid header.

        $intakeId = rr_create_resource_intake_from_approval($id);
        $releasedForCheckin = false;
        if ($intakeId) {
            rr_sync_expected_resource_from_logistics($id, $intakeId, false);
            $releasedForCheckin = rr_publish_expected_resource_after_logistics_approval($id, $intakeId);
            $link = app_url('/forms/resources/index.php?action=view&id=' . $intakeId);
            ics213rr_create_notification($incidentId,null,'RESL','resource_unit_intake','Resource awaiting check-in',rr_kind_type_summary($rr).' was released to check-in after Logistics approval and is awaiting arrival/check-in. No RESL action is required unless an exception is identified.',$link,$id);
            ics213rr_create_notification($incidentId,null,'PSC','resource_unit_intake','Resource awaiting check-in',rr_kind_type_summary($rr).' was released to check-in after Logistics approval and is awaiting arrival/check-in.',$link,$id);
        }

        ics213rr_create_notification($incidentId,null,'FSC','ics213rr_finance','ICS 213RR requires Finance review',rr_kind_type_summary($rr).' requires Finance review.',app_url('/forms/finance/index.php?status=Pending%20Review'),$id);
        flash('success', $intakeId ? ($releasedForCheckin ? 'Logistics section saved, routed to Finance, and released for check-in.' : 'Logistics section saved, routed to Finance, and sent to Resources Unit intake.') : 'Logistics section saved and routed to Finance.');
        header('Location: '.app_url('/forms/ics213rr_v2/index.php?action=view&id='.$id)); exit;
    }

    if ($postAction === 'fulfillment_save') {
        $fulfillmentStatus = (string)($_POST['fulfillment_status'] ?? 'fulfilled');
        if (!in_array($fulfillmentStatus, ['fulfilled','partially_fulfilled','awaiting_receipt'], true)) { $fulfillmentStatus = 'fulfilled'; }
        $receivedAt = (string)($_POST['received_at'] ?? '');
        $receivedAtSql = $receivedAt !== '' ? date('Y-m-d H:i:s', strtotime($receivedAt)) : date('Y-m-d H:i:s');
        db()->prepare("UPDATE form_213rr_headers
                       SET received_quantity=?, received_location=?, received_by_user_id=?, received_by_name=?, received_by_position=?,
                           received_at=?, fulfillment_notes=?
                       WHERE id=?")
           ->execute([
               ($_POST['received_quantity'] ?? null) !== '' ? (float)$_POST['received_quantity'] : null,
               (string)($_POST['received_location'] ?? ''),
               (int)($user['id'] ?? 0) ?: null,
               (string)($_POST['received_by_name'] ?? ($user['display_name'] ?? '')),
               (string)($_POST['received_by_position'] ?? ($user['role_code'] ?? '')),
               $receivedAtSql,
               (string)($_POST['fulfillment_notes'] ?? ''),
               $id
           ]);
        rr_update_status($id, $fulfillmentStatus, 'Receipt/Fulfillment recorded: '.(string)($_POST['fulfillment_notes'] ?? ''));

        $resourceCategory = (string)($_POST['resource_category'] ?? 'unknown');
        $reslNotes = trim((string)($_POST['resl_notes'] ?? ''));
        $trackableCategories = ['personnel','crew','vehicle','equipment','communications','facility','other'];
        $sendToResources = ((string)($_POST['send_to_resources_unit'] ?? '') === '1')
            || in_array($resourceCategory, $trackableCategories, true)
            || ($reslNotes !== '' && $resourceCategory !== 'no_tracking_required');
        if ($resourceCategory === 'no_tracking_required') { $sendToResources = false; }
        if ($sendToResources) {
            $intakeId = rr_resource_intake_upsert($id, $resourceCategory, $reslNotes);
            $link = app_url('/forms/resources/index.php' . ($intakeId ? '?action=view&id=' . $intakeId : ''));
            $rrAfter = rr_get($id);
            ics213rr_create_notification($incidentId, null, 'RESL', 'resource_unit_intake', 'Resources Unit intake item created', (string)($rrAfter['request_number'] ?? 'ICS 213RR') . ' - ' . rr_kind_type_summary($rrAfter) . ' has been sent to Resources Unit intake.', $link, $id);
            ics213rr_create_notification($incidentId, null, 'PSC', 'resource_unit_intake', 'Resources Unit intake item created', (string)($rrAfter['request_number'] ?? 'ICS 213RR') . ' - ' . rr_kind_type_summary($rrAfter) . ' has been sent to Resources Unit intake.', $link, $id);
            flash('success','Receipt/fulfillment details saved and sent to Resources Unit intake.');
        } elseif ($resourceCategory === 'no_tracking_required') {
            rr_resource_intake_mark_no_tracking($id, $reslNotes);
            flash('success','Receipt/fulfillment details saved. No Resources Unit tracking was requested.');
        } else {
            flash('success','Receipt/fulfillment details saved.');
        }
        header('Location: '.app_url('/forms/ics213rr_v2/index.php?action=view&id='.$id)); exit;
    }

    if (in_array($postAction,['fulfilled','partially_fulfilled','awaiting_receipt','closed','cancelled','returned_for_revision','unable_to_fill'],true)) {
        if ($postAction === 'cancelled') {
            rr_cancel_request($id, (string)($_POST['cancel_reason'] ?? $_POST['comment'] ?? ''));
            flash('success','Request cancelled. It is now listed under Closed requests.');
            header('Location: '.app_url('/forms/ics213rr_v2/index.php?action=view&id='.$id)); exit;
        }
        rr_update_status($id,$postAction,(string)($_POST['comment']??''));

        $resourceCategory = (string)($_POST['resource_category'] ?? 'unknown');
        $reslNotes = trim((string)($_POST['resl_notes'] ?? ''));
        $trackableCategories = ['personnel','crew','vehicle','equipment','communications','facility','other'];
        $sendToResources = ((string)($_POST['send_to_resources_unit'] ?? '') === '1')
            || in_array($resourceCategory, $trackableCategories, true)
            || ($reslNotes !== '' && $resourceCategory !== 'no_tracking_required');
        if ($resourceCategory === 'no_tracking_required') { $sendToResources = false; }

        if ($sendToResources && in_array($postAction,['fulfilled','partially_fulfilled','awaiting_receipt'],true)) {
            $intakeId = rr_resource_intake_upsert($id, $resourceCategory, $reslNotes);
            if ($intakeId) {
                $link = app_url('/forms/resources/index.php?action=view&id=' . $intakeId);
                $rrAfter = rr_get($id);
                ics213rr_create_notification($incidentId, null, 'RESL', 'resource_unit_intake', 'Resources Unit intake item created', (string)($rrAfter['request_number'] ?? 'ICS 213RR') . ' - ' . rr_kind_type_summary($rrAfter) . ' has been sent to Resources Unit intake.', $link, $id);
                ics213rr_create_notification($incidentId, null, 'PSC', 'resource_unit_intake', 'Resources Unit intake item created', (string)($rrAfter['request_number'] ?? 'ICS 213RR') . ' - ' . rr_kind_type_summary($rrAfter) . ' has been sent to Resources Unit intake.', $link, $id);
                flash('success','Status updated and sent to Resources Unit intake.');
            } else {
                flash('warning','Status updated, but no Resources Unit intake item was created.');
            }
        } else {
            flash('success','Status updated.');
        }
        header('Location: '.app_url('/forms/ics213rr_v2/index.php?action=view&id='.$id)); exit;
    }
}
} catch (Throwable $e) { flash('error',$e->getMessage()); }

// This page may be rendered inside the application's existing layout shell.
$rrV2RenderLayout = !headers_sent();
if ($rrV2RenderLayout) {
    render_header('ICS 213RR Resource Requests');
}

function rr_v2_status_label(string $status): string {
    $labels = [
        'draft' => 'Draft',
        'returned_for_revision' => 'Returned for revision',
        'section_chief_review' => 'Awaiting approval',
        'logistics_review' => 'Awaiting Logistics',
        'awaiting_receipt' => 'Awaiting arrival / receipt',
        'finance_review' => 'Finance review',
        'finance_signed' => 'Finance reviewed',
        'partially_fulfilled' => 'Partially fulfilled',
        'fulfilled' => 'Fulfilled',
        'unable_to_fill' => 'Unable to fill',
        'closed' => 'Closed',
        'cancelled' => 'Cancelled',
    ];
    return $labels[$status] ?? ucwords(str_replace('_', ' ', $status));
}

function rr_v2_step_number(string $status): int {
    if (in_array($status, ['draft', 'returned_for_revision'], true)) { return 1; }
    if ($status === 'section_chief_review') { return 2; }
    if (in_array($status, ['logistics_review', 'unable_to_fill'], true)) { return 3; }
    if (in_array($status, ['awaiting_receipt', 'finance_review', 'finance_signed', 'partially_fulfilled'], true)) { return 4; }
    return 5;
}

?>
<style>
.rr-v2-shell { max-width: 1280px; margin: 0 auto; }
.rr-v2-hero { display:flex; gap:1rem; justify-content:space-between; align-items:flex-start; border-left:5px solid #006b5c; }
.rr-v2-eyebrow { margin:0 0 .25rem; color:#4b5563; font-weight:700; font-size:.85rem; letter-spacing:.02em; text-transform:uppercase; }
.rr-v2-title { margin:.1rem 0; }
.rr-v2-subtitle { margin:.4rem 0 0; color:#4b5563; }
.rr-v2-status { display:inline-block; padding:.4rem .65rem; font-weight:700; background:#e7f4f1; color:#0a4d43; border:1px solid #9bcfc4; white-space:nowrap; }
.rr-v2-steps { display:grid; grid-template-columns:repeat(5, minmax(0,1fr)); gap:.45rem; margin:1rem 0; }
.rr-v2-step { border:1px solid #d1d5db; padding:.65rem; color:#4b5563; font-size:.9rem; background:#f9fafb; }
.rr-v2-step.current { background:#e7f4f1; border-color:#006b5c; color:#073b34; font-weight:700; }
.rr-v2-step.complete { background:#f2f7f6; color:#266158; }
.rr-v2-next { border:2px solid #006b5c; background:#f5fbfa; padding:1.15rem; margin:1rem 0; }
.rr-v2-next h2 { margin:0 0 .35rem; font-size:1.25rem; }
.rr-v2-next p { margin:.35rem 0 .9rem; }
.rr-v2-next .btn { margin-right:.4rem; }
.rr-v2-grid { display:grid; grid-template-columns:repeat(2, minmax(0,1fr)); gap:1rem; }
.rr-v2-grid .card { margin:0; }
.rr-v2-key-value { margin:0; display:grid; grid-template-columns:150px 1fr; gap:.5rem .75rem; }
.rr-v2-key-value dt { font-weight:700; color:#374151; }
.rr-v2-key-value dd { margin:0; }
.rr-v2-details summary { cursor:pointer; font-weight:700; color:#1f2937; }
.rr-v2-details[open] summary { margin-bottom:1rem; }
.rr-v2-table-actions { white-space:nowrap; }
.rr-v2-register-table td { vertical-align:top; }
.rr-v2-muted { color:#4b5563; }
.rr-v2-inline-form { display:inline; }
.rr-v2-legacy { font-size:.9rem; white-space:nowrap; }
.rr-v2-flow-note { margin:.5rem 0 0; color:#374151; }
@media (max-width: 800px) {
  .rr-v2-hero, .rr-v2-grid { display:block; }
  .rr-v2-hero > * + *, .rr-v2-grid > * + * { margin-top:1rem; }
  .rr-v2-steps { grid-template-columns:1fr; }
  .rr-v2-key-value { grid-template-columns:1fr; gap:.2rem; }
}
</style>
<?php

try {
if ($action === 'new' || ($action === 'edit' && $id>0)) {
    $rr = $id>0 ? rr_get($id) : null;
    if ($rr && !rr_can_edit_request_record($rr)) {
        flash('warning', 'This request is locked after submission. Cancel or return it, then create a new request if a material change is required.');
        header('Location: ' . app_url('/forms/ics213rr_v2/index.php?action=view&id=' . $id));
        exit;
    }
    $item = $rr ? (rr_items($id)[0] ?? []) : [];
    $status = rr_status($rr);
    ?>
    <div class="rr-v2-shell">
      <div class="card rr-v2-hero">
        <div><p class="rr-v2-eyebrow">ICS 213RR</p><h2 class="rr-v2-title"><?= $rr ? 'Update draft request' : 'New resource request' ?></h2><p class="rr-v2-subtitle">Describe the operational need. Logistics and Resources Unit routing are completed later.</p></div>
        <div><?php if ($id > 0): ?><span class="no-print"><a class="btn" target="_blank" rel="noopener" href="<?= e(app_url('/forms/ics213rr_v2/pdf.php?id=' . $id . '&preview=1')) ?>">Preview PDF</a> <a class="btn" target="_blank" rel="noopener" href="<?= e(app_url('/forms/ics213rr_v2/pdf.php?id=' . $id)) ?>">Download PDF</a></span><?php endif; ?></div>
      </div>

    <div class="card"><h3><?= $rr?'Request details':'Request details' ?></h3>
    <form method="post" class="form-grid">
    <?= csrf_field() ?>
      <input type="hidden" name="post_action" value="<?= $rr?'save':'create' ?>"><input type="hidden" name="id" value="<?= e((string)$id) ?>"><input type="hidden" name="status" value="<?= e($status) ?>">
      <label>Priority<select name="priority"><option value="urgent" <?= (($rr['priority_level']??'')==='urgent')?'selected':'' ?>>Urgent</option><option value="routine" <?= (($rr['priority_level']??'routine')==='routine')?'selected':'' ?>>Routine</option><option value="low" <?= (($rr['priority_level']??'')==='low')?'selected':'' ?>>Low</option></select></label>
      <label>Requested by name<input name="requested_by_name" value="<?= e((string)($rr['prepared_by_name']??$user['display_name']??'')) ?>"></label>
      <label>Requested by position<input name="requested_by_position" value="<?= e((string)($rr['prepared_by_position_title']??$user['role_code']??'')) ?>"></label>
      <label>Quantity<input name="quantity" type="number" step="0.01" value="<?= e((string)($item['quantity_requested']??'')) ?>"></label>
<?php $kindTypeCatalog = rr_kind_type_catalog(); $selectedKind = (string)($item['item_kind'] ?? ''); $selectedType = (string)($item['unit_of_issue'] ?? ''); ?>
      <label>Kind
        <select name="kind" id="rr_kind_select">
          <option value="">-- Select kind --</option>
          <?php foreach (($kindTypeCatalog['categories'] ?? []) as $cat): ?>
            <optgroup label="<?= e((string)($cat['category_name'] ?? 'Other')) ?>">
              <?php foreach (($cat['kinds'] ?? []) as $kind): $kindName = (string)($kind['kind_name'] ?? ''); ?>
                <option value="<?= e($kindName) ?>" <?= $selectedKind === $kindName ? 'selected' : '' ?>><?= e($kindName) ?></option>
              <?php endforeach; ?>
            </optgroup>
          <?php endforeach; ?>
          <?php if ($selectedKind !== ''):
            $knownKind = false;
            foreach (($kindTypeCatalog['categories'] ?? []) as $cat) { foreach (($cat['kinds'] ?? []) as $kind) { if ((string)($kind['kind_name'] ?? '') === $selectedKind) { $knownKind = true; break 2; } } }
            if (!$knownKind): ?>
              <option value="<?= e($selectedKind) ?>" selected><?= e($selectedKind) ?></option>
            <?php endif; endif; ?>
        </select>
      </label>
      <label>Type
        <select name="resource_type" id="rr_type_select" data-selected="<?= e($selectedType) ?>">
          <option value="">-- Select type --</option>
          <?php if ($selectedType !== ''): ?><option value="<?= e($selectedType) ?>" selected><?= e($selectedType) ?></option><?php endif; ?>
        </select>
      </label>

      <label>Estimated cost<input name="estimated_cost" type="number" step="0.01" min="0" value="<?= e((string)($item['estimated_cost']??'')) ?>"></label>
      <div class="govai-dt-wrap" data-govai-datetime>
        <label>Needed by<input class="govai-dt-display" name="arrival_requested_at" type="text" inputmode="numeric" pattern="[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}" placeholder="YYYY-MM-DD HH:mm" value="<?= e(!empty($rr['needed_by_datetime']) ? date('Y-m-d H:i', strtotime($rr['needed_by_datetime'])) : '') ?>"></label>
        <button type="button" class="btn btn-secondary govai-dt-button">Pick</button>
      </div>
      <label class="span-2">Detailed item description<textarea name="item_description" required><?= e((string)($item['item_description']??'')) ?></textarea></label>
      <div class="span-2 info-panel">
        <h3>Requested Delivery / Reporting</h3>
        <label>Requested delivery/reporting location<textarea name="delivery_location"><?= e((string)($rr['delivery_location']??'')) ?></textarea></label>
        <p class="small">Resources Unit routing is decided later by Logistics/LSC or RESL. Requesters only need to describe what is needed and where it should be delivered or report.</p>
      </div>
      <label class="span-2">Suitable substitutes<textarea name="suitable_substitutes"><?= e((string)($item['justification']??'')) ?></textarea></label>
      <label class="span-2">Suggested sources / remarks<textarea name="suggested_sources"><?= e((string)($item['remarks']??'')) ?></textarea></label>
      <div class="span-2 form-actions"><button class="btn primary">Save</button><a class="btn" href="<?= e(app_url('/forms/ics213rr_v2/index.php')) ?>">Cancel</a></div>
    </form></div>
    <script>
    (function () {
      const catalog = <?= json_encode(rr_kind_type_catalog(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
      const kindSelect = document.getElementById('rr_kind_select');
      const typeSelect = document.getElementById('rr_type_select');
      if (!kindSelect || !typeSelect || !catalog || !Array.isArray(catalog.categories)) return;

      const selectedType = typeSelect.getAttribute('data-selected') || '';
      function findKind(kindName) {
        for (const cat of catalog.categories || []) {
          for (const kind of cat.kinds || []) {
            if ((kind.kind_name || '') === kindName) return kind;
          }
        }
        return null;
      }

      function typeOptionValue(kind, type) {
        const label = type.label || '';
        const spec = type.minimum_spec ? ' - ' + type.minimum_spec : '';
        const unit = kind.primary_unit ? ' [' + kind.primary_unit + ']' : '';
        return label + spec + unit;
      }

      function populateTypes() {
        const kind = findKind(kindSelect.value);
        const current = typeSelect.value || selectedType;
        typeSelect.innerHTML = '';
        const placeholder = document.createElement('option');
        placeholder.value = '';
        placeholder.textContent = kind ? '-- Select type --' : '-- Select kind first --';
        typeSelect.appendChild(placeholder);

        if (!kind) {
          if (current) {
            const opt = document.createElement('option');
            opt.value = current;
            opt.textContent = current;
            opt.selected = true;
            typeSelect.appendChild(opt);
          }
          return;
        }

        for (const type of kind.types || []) {
          const opt = document.createElement('option');
          opt.value = typeOptionValue(kind, type);
          opt.textContent = type.label || opt.value;
          if (type.minimum_spec) opt.title = type.minimum_spec;
          if (opt.value === current || opt.textContent === current) opt.selected = true;
          typeSelect.appendChild(opt);
        }
      }

      kindSelect.addEventListener('change', function () {
        typeSelect.setAttribute('data-selected', '');
        populateTypes();
      });
      populateTypes();
    })();
    </script>
    </div>
    <?php
 } elseif ($action === 'fulfill' && $id>0) {
    $rr=rr_get($id); if (!$rr) { echo '<div class="card"><p>Request not found.</p></div>'; }
    else { $items=rr_items($id); $item=$items[0] ?? []; $existingIntake = rr_resource_intake_get_by_header($id); $suggestedCategory = $existingIntake['resource_category'] ?? rr_resource_category_guess($rr, $item); ?>
    <div class="rr-v2-shell"><div class="card"><p class="rr-v2-eyebrow">ICS 213RR</p><h2>Record receipt / fulfillment — <?= e((string)$rr['request_number']) ?></h2>
    <p class="small">Use this page to record full or partial receipt at the delivery location. If this was opened by mistake, use Cancel to return without changing the request.</p>
    <form method="post" class="form-grid">
    <?= csrf_field() ?>
      <input type="hidden" name="post_action" value="fulfillment_save"><input type="hidden" name="id" value="<?= e((string)$id) ?>">
      <label>Fulfillment status<select name="fulfillment_status"><option value="fulfilled">Fully fulfilled</option><option value="partially_fulfilled">Partially fulfilled</option><option value="awaiting_receipt">Still awaiting receipt / undo fulfillment</option></select></label>
      <label>Quantity received<input name="received_quantity" type="number" step="0.01" value="<?= e((string)($rr['received_quantity'] ?? $item['quantity_requested'] ?? '')) ?>"></label>
      <div class="govai-dt-wrap" data-govai-datetime>
        <label>Received date/time<input class="govai-dt-display" name="received_at" type="text" inputmode="numeric" pattern="[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}" placeholder="YYYY-MM-DD HH:mm" value="<?= e(!empty($rr['received_at']) ? date('Y-m-d H:i', strtotime($rr['received_at'])) : date('Y-m-d H:i')) ?>"></label>
        <button type="button" class="btn btn-secondary govai-dt-button">Pick</button>
      </div>
      <label>Received by name<input name="received_by_name" value="<?= e((string)($rr['received_by_name'] ?? $user['display_name'] ?? '')) ?>"></label>
      <label>Received by position<input name="received_by_position" value="<?= e((string)($rr['received_by_position'] ?? $user['role_code'] ?? '')) ?>"></label>
      <label>Received location<input name="received_location" value="<?= e((string)($rr['received_location'] ?? $rr['delivery_location'] ?? '')) ?>"></label>
      <label class="span-2">Receipt / fulfillment notes<textarea name="fulfillment_notes" placeholder="Example: 10 ordered, 6 received at ICP. Remaining 4 still pending."><?= e((string)($rr['fulfillment_notes'] ?? '')) ?></textarea></label>

      <div class="span-2 info-panel">
        <h3>Resources Unit Tracking</h3>
        <p class="small">Trackable resources are now routed to the Resources Unit from the request and Logistics approval workflow. Use this receipt page for consumables, supplies, services, or other items that do not require Resources Unit tracking.</p>
      </div>

      <div class="span-2 form-actions rr-actions fulfillment-actions"><button class="btn primary">Save Receipt / Fulfillment</button><a class="btn" href="<?= e(app_url('/forms/ics213rr_v2/index.php?action=view&id='.$id)) ?>">Cancel</a></div>
    </form></div></div>
    <?php }


} elseif ($action === 'view' && $id>0) {
    $rr = rr_get($id);
    if (!$rr) {
        echo '<div class="rr-v2-shell"><div class="card"><p>Request not found.</p></div></div>';
    } else {
        $items = rr_items($id);
        $status = rr_status($rr);
        $resIntake = rr_resource_intake_get_by_header($id);
        $trackingRequired = !empty($rr['resource_tracking_required']) && (string)($rr['resource_category'] ?? '') !== 'no_tracking_required';
        $canRecordReceipt = in_array($status, ['finance_review','finance_signed','partially_fulfilled','awaiting_receipt'], true) && !$trackingRequired;
        $step = rr_v2_step_number($status);
        $intakeStatus = (string)($resIntake['intake_status'] ?? '');
        ?>
        <div class="rr-v2-shell">
          <div class="card rr-v2-hero">
            <div>
              <p class="rr-v2-eyebrow">ICS 213RR Resource Request</p>
              <h1 class="rr-v2-title"><?= e((string)$rr['request_number']) ?></h1>
              <p class="rr-v2-subtitle"><?= e(rr_kind_type_summary($rr, $items[0] ?? [])) ?> · Requested by <?= e((string)($rr['prepared_by_name'] ?? '')) ?></p>
            </div>
            <div>
              <span class="rr-v2-status"><?= e(rr_v2_status_label($status)) ?></span><br>
              <span class="no-print"><a class="btn" target="_blank" rel="noopener" href="<?= e(app_url('/forms/ics213rr_v2/pdf.php?id=' . $id . '&preview=1')) ?>">Preview PDF</a> <a class="btn" target="_blank" rel="noopener" href="<?= e(app_url('/forms/ics213rr_v2/pdf.php?id=' . $id)) ?>">Download PDF</a></span>
            </div>
          </div>

          <div class="rr-v2-steps" aria-label="Resource request workflow">
            <?php
              $steps = [1 => 'Request', 2 => 'Approval', 3 => 'Logistics', 4 => 'Check-in / receipt', 5 => 'Complete'];
              foreach ($steps as $number => $label):
                $stepClass = $number === $step ? ' current' : ($number < $step ? ' complete' : '');
            ?>
              <div class="rr-v2-step<?= $stepClass ?>"><?= $number ?>. <?= e($label) ?></div>
            <?php endforeach; ?>
          </div>

          <section class="rr-v2-next" aria-labelledby="next-action-heading">
            <h2 id="next-action-heading">Next action</h2>

            <?php if (in_array($status, ['draft','returned_for_revision'], true)): ?>
              <p><strong>Requester:</strong> Submit this request when the details are complete. It will be sent to the appropriate approving authority or directly to Logistics, based on your incident role.</p>
              <form method="post" class="rr-v2-inline-form">
                <?= csrf_field() ?><input type="hidden" name="id" value="<?= e((string)$id) ?>">
                <button name="post_action" value="submit" class="btn primary">Submit request</button>
              </form>
              <a class="btn" href="<?= e(app_url('/forms/ics213rr_v2/index.php?action=edit&id=' . $id)) ?>">Edit request</a>

            <?php elseif ($status === 'section_chief_review'): ?>
              <?php if (current_user_can_ics213rr_action('section_approve', $incidentId)): ?>
                <p><strong>Approving authority:</strong> Confirm the operational need, then send this request to Logistics.</p>
                <form method="post" class="rr-v2-inline-form">
                  <?= csrf_field() ?><input type="hidden" name="id" value="<?= e((string)$id) ?>">
                  <button name="post_action" value="section_approve" class="btn primary">Approve &amp; send to Logistics</button>
                </form>
              <?php else: ?>
                <p><strong>Awaiting approving authority:</strong> This request must be approved before Logistics can place or arrange the resource.</p>
              <?php endif; ?>

            <?php elseif (in_array($status, ['logistics_review','unable_to_fill'], true)): ?>
              <?php if (current_user_can_ics213rr_action('logistics', $incidentId)): ?>
                <?php if ($trackingRequired && $resIntake && $intakeStatus === 'returned_to_logistics'): ?>
                  <p><strong>Returned by Resources Unit:</strong> The expected check-in record was withdrawn. Review the return reason, update the request as needed, and complete Logistics approval again.</p>
                  <a class="btn" href="<?= e(app_url('/forms/resources/index.php?action=view&id=' . (int)$resIntake['id'])) ?>">View Resources Unit return</a>
                <?php else: ?>
                  <p><strong>Logistics:</strong> Confirm the supplier, receiving location, and Resources Unit routing. This creates the expected check-in record when tracking is required.</p>
                <?php endif; ?>
                <a class="btn primary" href="#logistics-panel">Complete Logistics</a>
              <?php else: ?>
                <?php if ($trackingRequired && $resIntake && $intakeStatus === 'returned_to_logistics'): ?>
                  <p><strong>Returned by Resources Unit:</strong> The expected check-in record was withdrawn and the request is waiting for Logistics approval again.</p>
                <?php else: ?>
                  <p><strong>Awaiting Logistics:</strong> Logistics must prepare the order and choose the expected receiving/check-in location.</p>
                <?php endif; ?>
              <?php endif; ?>

            <?php elseif (in_array($status, ['awaiting_receipt','finance_review','finance_signed','partially_fulfilled'], true)): ?>
              <?php if ($trackingRequired && $resIntake): ?>
                <p><strong><?= $intakeStatus === 'checked_in' ? 'Resources Unit:' : 'Resources Unit / check-in:' ?></strong>
                  <?php if ($intakeStatus === 'checked_in'): ?>
                    The resource has checked in. Open the Resources Unit intake record to complete the ICS tracking record.
                  <?php elseif ($intakeStatus === 'converted'): ?>
                    The resource is documented in the ICS tracking record.
                  <?php else: ?>
                    The expected resource was released after Logistics approval and is awaiting check-in at the selected location. No Resources Unit review or publication action is required unless an exception is identified.
                  <?php endif; ?>
                </p>
                <a class="btn primary" href="<?= e(app_url('/forms/resources/index.php?action=view&id=' . (int)$resIntake['id'])) ?>">Open Resources Unit intake</a>
              <?php elseif ($trackingRequired): ?>
                <p><strong>Resources Unit:</strong> Logistics routing is recorded, but the Resources Unit intake record is not yet available.</p>
              <?php elseif ($canRecordReceipt): ?>
                <p><strong>Receiving staff:</strong> Record the delivered quantity and receipt details to complete this request.</p>
                <a class="btn primary" href="<?= e(app_url('/forms/ics213rr_v2/index.php?action=fulfill&id=' . $id)) ?>">Record receipt / fulfillment</a>
              <?php else: ?>
                <p><strong>Awaiting arrival:</strong> This request is waiting for the resource to arrive or for receipt details to be recorded.</p>
              <?php endif; ?>

            <?php elseif ($status === 'fulfilled'): ?>
              <?php if (current_user_can_ics213rr_action('close', $incidentId)): ?>
                <p><strong>Records / command:</strong> The resource request is fulfilled. Close and file it when no further action is required.</p>
                <form method="post" class="rr-v2-inline-form">
                  <?= csrf_field() ?><input type="hidden" name="id" value="<?= e((string)$id) ?>">
                  <button name="post_action" value="closed" class="btn primary">Close &amp; file request</button>
                </form>
              <?php else: ?>
                <p><strong>Fulfilled:</strong> The request is ready for an authorized user to close and file.</p>
              <?php endif; ?>

            <?php elseif ($status === 'closed'): ?>
              <p><strong>Complete:</strong> This request has been closed and retained as a historical record.</p>
            <?php elseif ($status === 'cancelled'): ?>
              <p><strong>Cancelled:</strong> This request is a read-only historical record. Create a new request if the resource is still required.</p>
            <?php else: ?>
              <p><strong>Current status:</strong> <?= e(rr_v2_status_label($status)) ?>.</p>
            <?php endif; ?>
          </section>

          <div class="rr-v2-grid">
            <section class="card">
              <h2>Request summary</h2>
              <dl class="rr-v2-key-value">
                <dt>Priority</dt><dd><?= e((string)($rr['priority_level'] ?? '')) ?></dd>
                <dt>Requested by</dt><dd><?= e((string)($rr['prepared_by_name'] ?? '')) ?><?= !empty($rr['prepared_by_position_title']) ? ', ' . e((string)$rr['prepared_by_position_title']) : '' ?></dd>
                <dt>Needed by</dt><dd><?= e((string)($rr['needed_by_datetime'] ?? 'Not specified')) ?></dd>
                <dt>Delivery / reporting</dt><dd><?= e((string)($rr['delivery_location'] ?? 'Not specified')) ?></dd>
              </dl>
            </section>

            <section class="card">
              <h2>Operational status</h2>
              <dl class="rr-v2-key-value">
                <dt>Finance</dt><dd><?= !empty($rr['finance_signed_at']) ? 'Reviewed ' . e((string)$rr['finance_signed_at']) : 'Pending / not recorded' ?></dd>
                <dt>Receipt</dt><dd><?= !empty($rr['received_at']) ? e((string)$rr['received_at']) . ' by ' . e((string)($rr['received_by_name'] ?? '')) : 'Pending' ?></dd>
                <dt>Tracking</dt><dd><?= $trackingRequired ? 'Resources Unit tracking required' : 'No Resources Unit tracking required' ?></dd>
                <?php if ($resIntake): ?><dt>Intake status</dt><dd><?= e((string)$resIntake['intake_status']) ?></dd><?php endif; ?>
              </dl>
            </section>
          </div>

          <section class="card">
            <h2>Requested resource</h2>
            <table>
              <thead><tr><th>Quantity</th><th>Kind</th><th>Type / unit</th><th>Estimated cost</th><th>Description</th></tr></thead>
              <tbody>
              <?php foreach ($items as $item): ?>
                <tr>
                  <td><?= e((string)($item['quantity_requested'] ?? '')) ?></td>
                  <td><?= e((string)($item['item_kind'] ?? '')) ?></td>
                  <td><?= e((string)($item['unit_of_issue'] ?? '')) ?></td>
                  <td><?= isset($item['estimated_cost']) && $item['estimated_cost'] !== null ? e(number_format((float)$item['estimated_cost'], 2)) : '—' ?></td>
                  <td><?= e((string)($item['item_description'] ?? '')) ?></td>
                </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
            <?php if (!empty($items[0]['justification']) || !empty($items[0]['remarks'])): ?>
              <p class="rr-v2-flow-note"><?php if (!empty($items[0]['justification'])): ?><strong>Suitable substitutes:</strong> <?= nl2br(e((string)$items[0]['justification'])) ?><br><?php endif; ?><?php if (!empty($items[0]['remarks'])): ?><strong>Suggested sources / remarks:</strong> <?= nl2br(e((string)$items[0]['remarks'])) ?><?php endif; ?></p>
            <?php endif; ?>
          </section>

          <?php if ($trackingRequired || !empty($rr['expected_checkin_location']) || $resIntake): ?>
            <section class="card">
              <h2>Resources Unit and check-in</h2>
              <dl class="rr-v2-key-value">
                <dt>Expected location</dt><dd><?= e((string)($rr['expected_checkin_location'] ?? 'Not selected')) ?></dd>
                <dt>Category</dt><dd><?= e((string)($rr['resource_category'] ?? 'To be determined')) ?></dd>
                <dt>Check-in notes</dt><dd><?= nl2br(e((string)($rr['resource_tracking_notes'] ?? 'None'))) ?></dd>
              </dl>
              <?php if ($resIntake): ?><p><a class="btn" href="<?= e(app_url('/forms/resources/index.php?action=view&id=' . (int)$resIntake['id'])) ?>">Open Resources Unit intake</a></p><?php endif; ?>
            </section>
          <?php endif; ?>

          <?php if (!empty($rr['logistics_order_number']) || !empty($rr['supplier_name']) || !empty($rr['logistics_notes'])): ?>
            <section class="card">
              <h2>Logistics order</h2>
              <dl class="rr-v2-key-value">
                <dt>Order number</dt><dd><?= e((string)($rr['logistics_order_number'] ?? 'Not recorded')) ?></dd>
                <dt>Placed by</dt><dd><?= e((string)($rr['order_placed_by'] ?? 'Not recorded')) ?></dd>
                <dt>Supplier / contact</dt><dd><?= e(trim((string)($rr['supplier_name'] ?? '') . ((string)($rr['supplier_contact'] ?? '') !== '' ? ' — ' . (string)$rr['supplier_contact'] : '')) ?: 'Not recorded') ?></dd>
                <dt>Approval</dt><dd><?= !empty($rr['logistics_signed_at']) ? e((string)($rr['logistics_signed_by_name'] ?? '')) . ' at ' . e((string)$rr['logistics_signed_at']) : 'Pending' ?></dd>
              </dl>
              <?php if (!empty($rr['logistics_notes'])): ?><p class="rr-v2-flow-note"><strong>Notes:</strong> <?= nl2br(e((string)$rr['logistics_notes'])) ?></p><?php endif; ?>
            </section>
          <?php endif; ?>

          <?php if (!empty($rr['finance_comments'])): ?>
            <section class="card"><h2>Finance comments</h2><p><?= nl2br(e((string)$rr['finance_comments'])) ?></p></section>
          <?php endif; ?>

          <?php if ($status === 'cancelled'): ?>
            <section class="card warning"><h2>Cancellation</h2><p><strong>Cancelled by:</strong> <?= e((string)($rr['cancelled_by_name'] ?? '')) ?><?= !empty($rr['cancelled_at']) ? ' on ' . e((string)$rr['cancelled_at']) : '' ?></p><p><strong>Reason:</strong> <?= nl2br(e((string)($rr['cancel_reason'] ?? 'No cancellation reason recorded.'))) ?></p></section>
          <?php endif; ?>

          <?php
            $activeCheckinLocationsStmt = db()->prepare('SELECT id, location_name, location_type, street_address
              FROM external_checkin_locations
              WHERE incident_id=? AND lifecycle_status="active"
              ORDER BY location_name');
            $activeCheckinLocationsStmt->execute([$incidentId]);
            $activeCheckinLocations = $activeCheckinLocationsStmt->fetchAll();
          ?>
          <?php if (current_user_can_ics213rr_action('logistics', $incidentId) && in_array($status, ['logistics_review','unable_to_fill'], true)): ?>
            <section class="card" id="logistics-panel">
              <h2>Complete Logistics</h2>
              <p class="rr-v2-muted">Set the order details and select the active location where the resource is expected to check in or be received.</p>
              <form method="post" class="form-grid">
                <?= csrf_field() ?><input type="hidden" name="id" value="<?= e((string)$id) ?>">
                <label>Logistics order number<input name="logistics_order_number" value="<?= e(rr_get_or_next_logistics_order_number($rr, $incidentId)) ?>"></label>
                <label>Order placed by<select name="order_placed_by">
                  <option value=""></option>
                  <option value="SPUL" <?= (($rr['order_placed_by'] ?? '') === 'SPUL') ? 'selected' : '' ?>>SPUL - Supply Unit Leader</option>
                  <option value="PROC" <?= (($rr['order_placed_by'] ?? '') === 'PROC') ? 'selected' : '' ?>>PROC - Procurement Unit Leader</option>
                  <option value="FDUL" <?= (($rr['order_placed_by'] ?? '') === 'FDUL') ? 'selected' : '' ?>>FDUL - Food Unit Leader</option>
                  <option value="LSC" <?= (($rr['order_placed_by'] ?? '') === 'LSC') ? 'selected' : '' ?>>LSC - Logistics Section Chief</option>
                  <option value="GSUL" <?= (($rr['order_placed_by'] ?? '') === 'GSUL') ? 'selected' : '' ?>>GSUL - Ground Support Unit Leader</option>
                  <option value="FACL" <?= (($rr['order_placed_by'] ?? '') === 'FACL') ? 'selected' : '' ?>>FACL - Facilities Unit Leader</option>
                  <option value="COML" <?= (($rr['order_placed_by'] ?? '') === 'COML') ? 'selected' : '' ?>>COML - Communications Unit Leader</option>
                </select></label>
                <label class="span-2">Supplier / point of contact<input name="supplier_name" value="<?= e((string)($rr['supplier_name'] ?? '')) ?>"></label>
                <label class="span-2">Supplier phone, email, or other contact<input name="supplier_contact" value="<?= e((string)($rr['supplier_contact'] ?? '')) ?>"></label>
                <label class="span-2">Logistics notes<textarea name="logistics_notes"><?= e((string)($rr['logistics_notes'] ?? '')) ?></textarea></label>

                <div class="span-2 info-panel">
                  <h3>Resources Unit routing</h3>
                  <label>Tracking decision<select name="resl_routing">
                    <?php $routing = (!empty($rr['resource_tracking_required']) ? (((string)($rr['resource_category'] ?? '') === 'unknown') ? 'resl_determine' : 'track') : 'resl_determine'); ?>
                    <option value="no_tracking_required" <?= $routing === 'no_tracking_required' ? 'selected' : '' ?>>No tracking required</option>
                    <option value="track" <?= $routing === 'track' ? 'selected' : '' ?>>Track through Resources Unit</option>
                    <option value="resl_determine" <?= $routing === 'resl_determine' ? 'selected' : '' ?>>RESL to determine</option>
                  </select></label>
                  <label>Resource category<select name="resource_category">
                    <?php $cat = (string)($rr['resource_category'] ?? 'unknown'); foreach (['unknown'=>'Unknown / RESL to determine','personnel'=>'Personnel','crew'=>'Crew / team','vehicle'=>'Vehicle','equipment'=>'Equipment','communications'=>'Communications resource','facility'=>'Facility','other'=>'Other trackable resource','no_tracking_required'=>'No tracking required'] as $cv => $cl): ?>
                    <option value="<?= e($cv) ?>" <?= $cat === $cv ? 'selected' : '' ?>><?= e($cl) ?></option><?php endforeach; ?>
                  </select></label>
                  <label>Expected check-in / receiving location<select required name="expected_checkin_location_id"<?= !$activeCheckinLocations ? ' disabled' : '' ?>>
                    <option value="">Select active location</option>
                    <?php foreach ($activeCheckinLocations as $location): $locationLabel = trim((string)$location['location_name'] . ((string)($location['street_address'] ?? '') !== '' ? ' — ' . (string)$location['street_address'] : '')); ?>
                    <option value="<?= (int)$location['id'] ?>" <?= (int)($rr['expected_checkin_location_id'] ?? 0) === (int)$location['id'] ? 'selected' : '' ?>><?= e($locationLabel) ?></option>
                    <?php endforeach; ?>
                  </select></label>
                  <?php if (!$activeCheckinLocations): ?><p class="small span-2">No active locations are available. Create and activate a location in Settings before completing Logistics.</p><?php endif; ?>
                  <label class="span-2">Notes for RESL / check-in<textarea name="resource_tracking_notes"><?= e((string)($rr['resource_tracking_notes'] ?? '')) ?></textarea></label>
                  <p class="small span-2">Finance is notified in parallel and does not delay receiving or check-in.</p>
                </div>
                <div class="span-2 form-actions"><button class="btn primary" name="post_action" value="logistics_save"<?= !$activeCheckinLocations ? ' disabled' : '' ?>>Save Logistics &amp; create next steps</button></div>
              </form>
            </section>
          <?php endif; ?>

          <?php if (current_user_can_ics213rr_action('finance', $incidentId) && empty($rr['finance_signed_at']) && in_array($status, ['finance_review','awaiting_receipt','partially_fulfilled','fulfilled'], true)): ?>
            <section class="card">
              <h2>Finance / Administration</h2>
              <p class="rr-v2-muted">Finance review is completed from the Finance / Administration Queue and does not interrupt the operational resource workflow.</p>
              <a class="btn" href="<?= e(app_url('/forms/finance/index.php?status=Pending%20Review')) ?>">Open Finance / Administration Queue</a>
            </section>
          <?php endif; ?>

          <?php if (!in_array($status, ['closed','cancelled'], true) || rr_can_edit_request_record($rr)): ?>
            <details class="card rr-v2-details">
              <summary>More actions</summary>
              <form method="post" class="form-grid">
                <?= csrf_field() ?><input type="hidden" name="id" value="<?= e((string)$id) ?>">
                <?php if (in_array($status, ['fulfilled','partially_fulfilled'], true)): ?>
                  <div class="span-2"><button name="post_action" value="awaiting_receipt" class="btn">Set back to awaiting receipt</button></div>
                <?php endif; ?>
                <?php if (!in_array($status, ['fulfilled','closed','cancelled'], true)): ?>
                  <label class="span-2"><strong>Cancellation reason</strong><textarea name="cancel_reason" rows="3" placeholder="Explain why this resource request is no longer required."></textarea></label>
                  <div class="span-2"><button name="post_action" value="cancelled" class="btn danger" onclick="return confirm('Cancel this resource request?');">Cancel request</button></div>
                <?php endif; ?>
              </form>
            </details>
          <?php endif; ?>

          <section class="card">
            <h2>Activity timeline</h2>
            <div class="timeline">
              <?php $historyStmt = db()->prepare("SELECT * FROM form_213rr_status_history WHERE header_id=? ORDER BY created_at"); $historyStmt->execute([$id]); foreach ($historyStmt->fetchAll() as $history): ?>
              <div class="timeline-item"><strong><?= e(rr_v2_status_label((string)$history['to_status'])) ?></strong> <?= e((string)$history['created_at']) ?><br><span class="small"><?= e(trim((string)($history['changed_by_name'] ?? '') . ' ' . (string)($history['comment'] ?? ''))) ?></span></div>
              <?php endforeach; ?>
            </div>
          </section>
        </div>
        <?php
    }
} else {
    $listSql = "SELECT h.id,h.request_number,h.priority_level,COALESCE(h.workflow_status,fr.status) AS status,fr.prepared_by_name,op.period_number,
          h.needed_by_datetime,h.delivery_location,fr.updated_at,fr.created_at,h.cancelled_at,h.cancelled_by_name,h.cancel_reason,
          MIN(i.item_kind) AS item_kind,
          MIN(i.unit_of_issue) AS unit_of_issue,
          MIN(i.item_description) AS item_description
        FROM form_213rr_headers h
        INNER JOIN form_records fr ON fr.id=h.form_record_id
        LEFT JOIN operational_periods op ON op.id=fr.operational_period_id
        LEFT JOIN form_213rr_items i ON i.header_id=h.id AND i.deleted_at IS NULL
        WHERE fr.incident_id=? AND fr.form_type='ICS213RR' AND fr.is_deleted=0 AND COALESCE(h.workflow_status,fr.status) {STATUS_FILTER}
        GROUP BY h.id,h.request_number,h.priority_level,h.workflow_status,fr.status,fr.prepared_by_name,op.period_number,h.needed_by_datetime,h.delivery_location,fr.updated_at,fr.created_at,h.cancelled_at,h.cancelled_by_name,h.cancel_reason
        ORDER BY FIELD(h.priority_level,'urgent','routine','low'), COALESCE(fr.updated_at,fr.created_at) DESC, h.id DESC";

    $openStmt = db()->prepare(str_replace('{STATUS_FILTER}', "NOT IN ('closed','cancelled')", $listSql));
    $openStmt->execute([$incidentId]);
    $openRows = $openStmt->fetchAll();

    $closedStmt = db()->prepare(str_replace('{STATUS_FILTER}', "IN ('closed','cancelled')", $listSql));
    $closedStmt->execute([$incidentId]);
    $closedRows = $closedStmt->fetchAll();
    ?>
    <div class="rr-v2-shell">
      <div class="card rr-v2-hero">
        <div><p class="rr-v2-eyebrow">ICS 213RR</p><h1 class="rr-v2-title">Resource request register</h1><p class="rr-v2-subtitle">Open requests are ordered by priority and most recently updated.</p></div>
        <div><a class="btn primary" href="<?= e(app_url('/forms/ics213rr_v2/index.php?action=new')) ?>">New resource request</a></div>
      </div>

      <section class="card">
        <h2>Open requests</h2>
        <?php if (!$openRows): ?>
          <p>No open ICS 213RR resource requests exist for this incident.</p>
        <?php else: ?>
          <table class="rr-v2-register-table">
            <thead><tr><th>Request</th><th>Priority</th><th>Current step</th><th>Requested resource</th><th>Needed by</th><th>Action</th></tr></thead>
            <tbody>
              <?php foreach ($openRows as $row): $rowStatus = (string)$row['status']; ?>
              <tr>
                <td><strong><?= e((string)$row['request_number']) ?></strong><br><span class="small"><?= e((string)$row['prepared_by_name']) ?> · OP <?= e((string)($row['period_number'] ?? '')) ?></span></td>
                <td class="priority-<?= e((string)$row['priority_level']) ?>"><?= e((string)$row['priority_level']) ?></td>
                <td><span class="status-pill"><?= e(rr_v2_status_label($rowStatus)) ?></span></td>
                <td><?= e(trim((string)($row['item_kind'] ?? '') . (((string)($row['item_kind'] ?? '') !== '' && (string)($row['unit_of_issue'] ?? '') !== '') ? ' / ' : '') . (string)($row['unit_of_issue'] ?? '')) ?: (string)($row['item_description'] ?? '')) ?></td>
                <td><?= e((string)($row['needed_by_datetime'] ?? '')) ?></td>
                <td class="rr-v2-table-actions"><a class="btn small" href="<?= e(app_url('/forms/ics213rr_v2/index.php?action=view&id=' . (int)$row['id'])) ?>">Open request</a></td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        <?php endif; ?>
      </section>

      <details class="card rr-v2-details">
        <summary>Closed and cancelled requests (<?= count($closedRows) ?>)</summary>
        <?php if (!$closedRows): ?>
          <p>No closed or cancelled requests exist for this incident.</p>
        <?php else: ?>
          <table class="rr-v2-register-table">
            <thead><tr><th>Request</th><th>Status</th><th>Requested by</th><th>Updated</th><th>Action</th></tr></thead>
            <tbody>
              <?php foreach ($closedRows as $row): ?>
              <tr>
                <td><?= e((string)$row['request_number']) ?></td>
                <td><span class="status-pill"><?= e(rr_v2_status_label((string)$row['status'])) ?></span></td>
                <td><?= e((string)$row['prepared_by_name']) ?></td>
                <td><?= e((string)($row['updated_at'] ?? $row['created_at'] ?? '')) ?></td>
                <td><a class="btn small" href="<?= e(app_url('/forms/ics213rr_v2/index.php?action=view&id=' . (int)$row['id'])) ?>">View</a></td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        <?php endif; ?>
      </details>
    </div>
    <?php
}
} catch (Throwable $e) {
    echo '<div class="rr-v2-shell"><div class="card error"><h2>Unable to load the resource request page.</h2><p>' . e($e->getMessage()) . '</p></div></div>';
}
if ($rrV2RenderLayout) {
    render_footer();
}
