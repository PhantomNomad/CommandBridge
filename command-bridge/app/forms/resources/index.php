<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/security.php';
require_once __DIR__ . '/../../includes/external_checkin_sync.php';
require_csrf_for_post();

require_once dirname(__DIR__, 2) . '/includes/layout.php';
require_login();

$incident = active_incident();
$incidentId = (int)($incident['id'] ?? 0);
$user = current_user();
if ($incidentId <= 0) {
    render_header('Resources Unit');
    echo '<div class="card"><p>No active incident.</p></div>';
    render_footer();
    exit;
}

$action = (string)($_GET['action'] ?? 'list');
$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);

function resources_user_can_view(int $incidentId): bool {
    return current_user_can_resources_view($incidentId);
}

function resources_user_can_manage(int $incidentId): bool {
    return current_user_can_resources_manage($incidentId);
}

function resources_status_label(string $status): string {
    $labels = [
        'new' => 'New',
        'reviewed' => 'Awaiting Check-in',
        'converted' => 'Converted',
        'received' => 'Received',
        'checked_in' => 'Checked In',
        'no_tracking_required' => 'No tracking required',
        'returned_to_logistics' => 'Returned to Logistics',
        'cancelled' => 'Cancelled',
    ];
    return $labels[$status] ?? $status;
}

function resources_get_intake(int $id, int $incidentId): ?array {
    $stmt = db()->prepare("
        SELECT rui.*
        FROM resource_unit_intake rui
        WHERE rui.id=? AND rui.incident_id=?
        LIMIT 1
    ");
    $stmt->execute([$id, $incidentId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function resources_update_status(int $id, int $incidentId, string $status, string $notes): void {
    $allowed = ['new','reviewed','received','checked_in','no_tracking_required','returned_to_logistics','cancelled'];
    if (!in_array($status, $allowed, true)) {
        throw new RuntimeException('Invalid Resources Unit intake status.');
    }
    $u = current_user();
    db()->prepare("
        UPDATE resource_unit_intake
        SET intake_status=?,
            notes=CASE WHEN ? <> '' THEN CONCAT(COALESCE(notes,''), CASE WHEN COALESCE(notes,'') <> '' THEN '\n\n' ELSE '' END, ?) ELSE notes END,
            reviewed_by_user_id=?,
            reviewed_by_name=?,
            reviewed_at=NOW(),
            updated_at=NOW()
        WHERE id=? AND incident_id=?
    ")->execute([
        $status,
        $notes,
        'RESL review note: ' . $notes,
        (int)($u['id'] ?? 0) ?: null,
        (string)($u['display_name'] ?? ''),
        $id,
        $incidentId
    ]);
}


function resources_return_intake_to_logistics(array $intake, int $incidentId, string $notes): void {
    $resourceUnitIntakeId = (int)($intake['id'] ?? 0);
    $headerId = (int)($intake['source_213rr_header_id'] ?? 0);
    if ($resourceUnitIntakeId <= 0 || $headerId <= 0) {
        throw new RuntimeException('This intake item is not linked to a source ICS 213RR request.');
    }

    $pdo = db();
    $user = current_user();
    $userId = (int)($user['id'] ?? 0) ?: null;
    $userName = trim((string)($user['display_name'] ?? $user['username'] ?? ''));
    $returnReason = $notes !== '' ? $notes : 'Returned by Resources Unit for Logistics clarification.';

    $pdo->beginTransaction();
    try {
        $headerStmt = $pdo->prepare(
            'SELECT h.id, h.form_record_id, h.workflow_status, fr.status AS form_record_status
             FROM form_213rr_headers h
             INNER JOIN form_records fr ON fr.id=h.form_record_id
             WHERE h.id=? AND fr.incident_id=? AND fr.is_deleted=0
             LIMIT 1
             FOR UPDATE'
        );
        $headerStmt->execute([$headerId, $incidentId]);
        $header = $headerStmt->fetch();
        if (!$header) {
            throw new RuntimeException('The linked ICS 213RR request could not be found for this incident.');
        }

        $expectedStmt = $pdo->prepare(
            'SELECT id, publication_status
             FROM external_checkin_expected_resources
             WHERE incident_id=? AND resource_unit_intake_id=?
             ORDER BY id DESC
             LIMIT 1
             FOR UPDATE'
        );
        $expectedStmt->execute([$incidentId, $resourceUnitIntakeId]);
        $expected = $expectedStmt->fetch();

        if ($expected && in_array((string)$expected['publication_status'], ['checked_in', 'demobilized'], true)) {
            throw new RuntimeException('This item cannot be returned to Logistics after it has checked in or been demobilized.');
        }

        $pdo->prepare(
            "UPDATE resource_unit_intake
             SET intake_status='returned_to_logistics',
                 notes=CONCAT(
                     COALESCE(notes,''),
                     CASE WHEN COALESCE(notes,'') <> '' THEN '\n\n' ELSE '' END,
                     ?
                 ),
                 reviewed_by_user_id=?,
                 reviewed_by_name=?,
                 reviewed_at=NOW(),
                 updated_at=NOW()
             WHERE id=? AND incident_id=?"
        )->execute([
            'Returned to Logistics: ' . $returnReason,
            $userId,
            $userName,
            $resourceUnitIntakeId,
            $incidentId,
        ]);

        if ($expected) {
            $expectedResourceId = (int)$expected['id'];
            $pdo->prepare(
                'UPDATE external_checkin_expected_resources
                 SET publication_status="withdrawn",
                     withdrawn_at=NOW(),
                     withdrawn_by=?,
                     updated_by=?,
                     updated_at=NOW()
                 WHERE id=? AND incident_id=?'
            )->execute([$userId, $userId, $expectedResourceId, $incidentId]);

            $eventPayload = json_encode([
                'expected_resource_id' => $expectedResourceId,
                'resource_unit_intake_id' => $resourceUnitIntakeId,
                'publication_status' => 'withdrawn',
                'reason' => 'Returned to Logistics: ' . $returnReason,
            ], JSON_UNESCAPED_SLASHES);

            $pdo->prepare(
                'INSERT INTO external_checkin_events (incident_id,event_type,event_payload,created_by)
                 VALUES (?,?,?,?)'
            )->execute([
                $incidentId,
                'expected_resource_withdrawn_for_logistics_return',
                $eventPayload === false ? null : $eventPayload,
                $userId,
            ]);

            external_checkin_enqueue_expected_resource_snapshot($expectedResourceId);
        }

        $previousWorkflowStatus = (string)($header['workflow_status'] ?? '');
        $pdo->prepare(
            'UPDATE form_records fr
             INNER JOIN form_213rr_headers h ON h.form_record_id=fr.id
             SET h.workflow_status="logistics_review",
                 fr.status="approved",
                 fr.updated_at=NOW()
             WHERE h.id=?'
        )->execute([$headerId]);

        // Finance work is no longer current while Logistics re-approves the returned request.
        $pdo->prepare(
            "UPDATE notifications
             SET is_read=1, read_at=COALESCE(read_at,NOW())
             WHERE incident_id=?
               AND is_read=0
               AND notification_type='ics213rr_finance'
               AND (
                    (related_table='form_213rr_headers' AND related_id=?)
                    OR form_record_id=?
               )"
        )->execute([$incidentId, $headerId, (int)$header['form_record_id']]);

        if (function_exists('ics213rr_log_status')) {
            ics213rr_log_status(
                $headerId,
                $previousWorkflowStatus,
                'logistics_review',
                'Returned by Resources Unit for Logistics approval: ' . $returnReason
            );
        }

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}


function resources_publish_expected_checkin_after_review(int $resourceUnitIntakeId, int $incidentId): bool {
    $pdo = db();
    $user = current_user();
    $userId = (int)($user['id'] ?? 0);

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare(
            'SELECT id
             FROM external_checkin_expected_resources
             WHERE incident_id=?
               AND resource_unit_intake_id=?
               AND publication_status="draft"
             LIMIT 1
             FOR UPDATE'
        );
        $stmt->execute([$incidentId, $resourceUnitIntakeId]);
        $expectedResourceId = (int)($stmt->fetch()['id'] ?? 0);

        if ($expectedResourceId <= 0) {
            $pdo->commit();
            return false;
        }

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
            'expected_resource_id' => $expectedResourceId,
            'resource_unit_intake_id' => $resourceUnitIntakeId,
            'publication_status' => 'published',
            'reason' => 'Resources Unit intake marked reviewed',
        ], JSON_UNESCAPED_SLASHES);

        $pdo->prepare(
            'INSERT INTO external_checkin_events (incident_id,event_type,event_payload,created_by)
             VALUES (?,?,?,?)'
        )->execute([
            $incidentId,
            'expected_resource_published_on_review',
            $eventPayload === false ? null : $eventPayload,
            $userId ?: null,
        ]);

        external_checkin_enqueue_expected_resource_snapshot($expectedResourceId);
        $pdo->commit();
        return true;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}


function resources_record_receipt(int $id, int $incidentId, array $data): void {
    $u = current_user();
    $qty = trim((string)($data['quantity_received'] ?? ''));
    $receivedAt = trim((string)($data['received_at'] ?? ''));
    $receivedBy = trim((string)($data['received_by_name'] ?? ''));
    $receivedPos = trim((string)($data['received_by_position'] ?? ''));
    $location = trim((string)($data['initial_location'] ?? ''));
    $supplier = trim((string)($data['supplier_name'] ?? ''));
    $contact = trim((string)($data['supplier_contact'] ?? ''));
    $notes = trim((string)($data['receipt_notes'] ?? ''));

    db()->prepare("
        UPDATE resource_unit_intake
        SET intake_status='received',
            quantity_received = NULLIF(?, ''),
            received_at = NULLIF(?, ''),
            received_by_name = NULLIF(?, ''),
            received_by_position = NULLIF(?, ''),
            initial_location = NULLIF(?, ''),
            supplier_name = NULLIF(?, ''),
            supplier_contact = NULLIF(?, ''),
            notes=CASE WHEN ? <> '' THEN CONCAT(COALESCE(notes,''), CASE WHEN COALESCE(notes,'') <> '' THEN '\n\n' ELSE '' END, ?) ELSE notes END,
            reviewed_by_user_id=?,
            reviewed_by_name=?,
            reviewed_at=NOW(),
            updated_at=NOW()
        WHERE id=? AND incident_id=?
    ")->execute([
        $qty,
        $receivedAt,
        $receivedBy,
        $receivedPos,
        $location,
        $supplier,
        $contact,
        $notes,
        'RESL receipt note: ' . $notes,
        (int)($u['id'] ?? 0) ?: null,
        (string)($u['display_name'] ?? ''),
        $id,
        $incidentId
    ]);
}


function resources_sync_source_213rr_after_receipt(int $id, int $incidentId): void {
    $pdo = db();
    $row = resources_get_intake($id, $incidentId);
    if (!$row) { return; }
    $rrId = (int)($row['source_213rr_header_id'] ?? 0);
    if ($rrId <= 0) { return; }

    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(quantity_requested),0) AS qty_requested
        FROM form_213rr_items
        WHERE header_id=? AND deleted_at IS NULL
    ");
    $stmt->execute([$rrId]);
    $requestedQty = (float)($stmt->fetch()['qty_requested'] ?? 0);

    $receivedQtyRaw = trim((string)($row['quantity_received'] ?? ''));
    $receivedQty = is_numeric($receivedQtyRaw) ? (float)$receivedQtyRaw : 0.0;

    $newStatus = 'fulfilled';
    if ($requestedQty > 0 && $receivedQty > 0 && $receivedQty < $requestedQty) {
        $newStatus = 'partially_fulfilled';
    }

    $notes = trim((string)($row['notes'] ?? ''));
    $receivedAt = !empty($row['received_at']) ? (string)$row['received_at'] : date('Y-m-d H:i:s');
    $receivedBy = trim((string)($row['received_by_name'] ?? ''));

    $pdo->prepare("
        UPDATE form_records fr
        INNER JOIN form_213rr_headers h ON h.form_record_id=fr.id
        SET h.workflow_status=?,
            h.received_quantity=?,
            h.received_location=?,
            h.received_at=?,
            h.received_by_name=?,
            h.received_by_position=?,
            h.fulfillment_notes=CASE
                WHEN ? <> '' THEN CONCAT(COALESCE(h.fulfillment_notes,''), CASE WHEN COALESCE(h.fulfillment_notes,'') <> '' THEN '\n\n' ELSE '' END, ?)
                ELSE h.fulfillment_notes
            END,
            fr.status='approved',
            fr.updated_at=NOW()
        WHERE h.id=? AND h.workflow_status NOT IN ('closed','cancelled','unable_to_fill')
    ")->execute([
        $newStatus,
        (string)($row['quantity_received'] ?? ''),
        (string)($row['initial_location'] ?? ''),
        $receivedAt,
        $receivedBy,
        (string)($row['received_by_position'] ?? ''),
        $notes,
        'Resources Unit receipt/check-in: ' . $notes,
        $rrId
    ]);

    if (function_exists('ics213rr_log_status')) {
        ics213rr_log_status($rrId, 'awaiting_receipt', $newStatus, 'Resources Unit recorded receipt/check-in for the resource.');
    }

    $pdo->prepare("
        UPDATE notifications
        SET is_read=1, read_at=COALESCE(read_at,NOW())
        WHERE incident_id=?
          AND is_read=0
          AND related_table='form_213rr_headers'
          AND related_id=?
          AND notification_type IN ('resource_unit_intake','ics213rr_receipt','ics213rr_logistics')
    ")->execute([$incidentId, $rrId]);
}


function resources_get_or_create_ics211_header(int $incidentId): int {
    $pdo = db();
    $formRecordId = get_or_create_form_record($incidentId, 'ICS211', '211', (int)(current_user()['id'] ?? 0));

    $stmt = $pdo->prepare("SELECT id FROM form_211_headers WHERE form_record_id = ? LIMIT 1");
    $stmt->execute([$formRecordId]);
    $headerId = (int)($stmt->fetch()['id'] ?? 0);

    if ($headerId <= 0) {
        $resolvedPreparer = resolve_form_preparer($incidentId, 'ICS211');
        apply_form_preparer_defaults($formRecordId, $resolvedPreparer);
        $stmt = $pdo->prepare("
            INSERT INTO form_211_headers (form_record_id, check_in_location, prepared_by_position_title)
            VALUES (?, ?, ?)
        ");
        $stmt->execute([$formRecordId, 'Incident Check-In', $resolvedPreparer['position_title'] ?? 'DOCL']);
        $headerId = (int)$pdo->lastInsertId();
    }

    return $headerId;
}

function resources_create_ics211_entry_from_intake(int $id, int $incidentId): int {
    $pdo = db();
    $row = resources_get_intake($id, $incidentId);
    if (!$row) {
        throw new RuntimeException('Intake item not found.');
    }
    if (!in_array((string)$row['intake_status'], ['received','checked_in'], true)) {
        throw new RuntimeException('The resource must be received before it can be added to ICS 211.');
    }

    $headerId = resources_get_or_create_ics211_header($incidentId);
    $checkInTime = (string)($row['received_at'] ?? '');
    if ($checkInTime === '') {
        $checkInTime = date('Y-m-d H:i:s');
    }

    $resourceName = trim((string)($row['resource_description'] ?? ''));
    if ($resourceName === '') {
        $resourceName = trim(implode(' ', array_filter([
            (string)($row['resource_kind'] ?? ''),
            (string)($row['resource_type'] ?? '')
        ])));
    }
    if ($resourceName === '') {
        $resourceName = (string)($row['source_request_number'] ?? 'Resource');
    }

    $assignmentParts = [];
    if (!empty($row['initial_location'])) {
        $assignmentParts[] = 'Check-in/location: ' . (string)$row['initial_location'];
    }
    if (!empty($row['logistics_order_number'])) {
        $assignmentParts[] = 'Logistics order: ' . (string)$row['logistics_order_number'];
    }
    if (!empty($row['source_request_number'])) {
        $assignmentParts[] = 'Source 213RR: ' . (string)$row['source_request_number'];
    }
    $assignment = implode(' | ', $assignmentParts);

    $contactParts = [];
    if (!empty($row['supplier_contact'])) { $contactParts[] = (string)$row['supplier_contact']; }
    if (!empty($row['received_by_position'])) { $contactParts[] = 'Received by position: ' . (string)$row['received_by_position']; }
    $contact = trim(implode(' | ', $contactParts));

    $qtyRaw = trim((string)($row['quantity_received'] ?? ''));
    $totalPersonnel = ctype_digit($qtyRaw) ? (int)$qtyRaw : null;

    $remarksParts = [];
    if ($qtyRaw !== '' && $totalPersonnel === null) { $remarksParts[] = 'Quantity received: ' . $qtyRaw; }
    if (!empty($row['notes'])) { $remarksParts[] = (string)$row['notes']; }
    $remarks = trim(implode("\n", $remarksParts));

    $entryData = [
        'header_id' => $headerId,
        'user_id' => current_user()['id'] ?? null,
        'category' => trim((string)($row['resource_category'] ?? '')) ?: null,
        'kind' => trim((string)($row['resource_kind'] ?? '')) ?: null,
        'type_st_tf' => trim((string)($row['resource_type'] ?? '')) ?: null,
        'resource_identifier' => trim((string)($row['source_request_number'] ?? '')) ?: null,
        'order_request_number' => trim((string)($row['logistics_order_number'] ?? '')) ?: null,
        'ldw_request_number' => trim((string)($row['source_request_number'] ?? '')) ?: null,
        'person_name' => $resourceName,
        'home_agency' => trim((string)($row['supplier_name'] ?? '')) ?: null,
        'contact_information' => $contact ?: null,
        'leader_name' => trim((string)($row['received_by_name'] ?? '')) ?: null,
        'total_personnel' => $totalPersonnel,
        'arrival_date' => $checkInTime,
        'departure_point' => trim((string)($row['supplier_name'] ?? '')) ?: null,
        'method_of_travel' => null,
        'incident_assignment' => $assignment ?: null,
        'other_qualifications' => null,
        'sent_to_resource_unit' => 1,
        'remarks' => $remarks ?: null,
        'checkin_time' => $checkInTime,
    ];

    $existingEntryId = (int)($row['ics211_entry_id'] ?? 0);
    if ($existingEntryId > 0) {
        $stmt = $pdo->prepare("
            UPDATE form_211_entries
            SET category=?, kind=?, type_st_tf=?, resource_identifier=?, order_request_number=?, ldw_request_number=?,
                person_name=?, home_agency=?, contact_information=?, leader_name=?, total_personnel=?, arrival_date=?,
                departure_point=?, method_of_travel=?, incident_assignment=?, other_qualifications=?, sent_to_resource_unit=?,
                remarks=?, checkin_time=?, updated_at=NOW()
            WHERE id=? AND header_id=? AND deleted_at IS NULL
        ");
        $stmt->execute([
            $entryData['category'], $entryData['kind'], $entryData['type_st_tf'], $entryData['resource_identifier'],
            $entryData['order_request_number'], $entryData['ldw_request_number'], $entryData['person_name'],
            $entryData['home_agency'], $entryData['contact_information'], $entryData['leader_name'],
            $entryData['total_personnel'], $entryData['arrival_date'], $entryData['departure_point'],
            $entryData['method_of_travel'], $entryData['incident_assignment'], $entryData['other_qualifications'],
            $entryData['sent_to_resource_unit'], $entryData['remarks'], $entryData['checkin_time'],
            $existingEntryId, $headerId
        ]);
        if ($stmt->rowCount() > 0) {
            return $existingEntryId;
        }
    }

    $stmt = $pdo->prepare("
        INSERT INTO form_211_entries
        (header_id, user_id, category, kind, type_st_tf, resource_identifier, order_request_number, ldw_request_number,
         person_name, home_agency, contact_information, leader_name, total_personnel, arrival_date, departure_point,
         method_of_travel, incident_assignment, other_qualifications, sent_to_resource_unit, remarks, checkin_time,
         created_at, updated_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
    ");
    $stmt->execute([
        $entryData['header_id'], $entryData['user_id'], $entryData['category'], $entryData['kind'], $entryData['type_st_tf'],
        $entryData['resource_identifier'], $entryData['order_request_number'], $entryData['ldw_request_number'],
        $entryData['person_name'], $entryData['home_agency'], $entryData['contact_information'], $entryData['leader_name'],
        $entryData['total_personnel'], $entryData['arrival_date'], $entryData['departure_point'], $entryData['method_of_travel'],
        $entryData['incident_assignment'], $entryData['other_qualifications'], $entryData['sent_to_resource_unit'],
        $entryData['remarks'], $entryData['checkin_time']
    ]);
    $entryId = (int)$pdo->lastInsertId();

    $u = current_user();
    $pdo->prepare("
        UPDATE resource_unit_intake
        SET intake_status='checked_in',
            ics211_entry_id=?,
            checked_in_at=NOW(),
            checked_in_by_user_id=?,
            checked_in_by_name=?,
            notes=CONCAT(COALESCE(notes,''), CASE WHEN COALESCE(notes,'') <> '' THEN '\n\n' ELSE '' END, ?),
            updated_at=NOW()
        WHERE id=? AND incident_id=?
    ")->execute([
        $entryId,
        (int)($u['id'] ?? 0) ?: null,
        (string)($u['display_name'] ?? ''),
        'ICS 211 entry created/updated from Resources Unit intake.',
        $id,
        $incidentId
    ]);

    return $entryId;
}

function resources_is_equipment_category(string $category): bool {
    return in_array($category, ['vehicle','equipment'], true);
}

function resources_get_or_create_ics218_header(int $incidentId): int {
    $pdo = db();
    $formRecordId = get_or_create_form_record($incidentId, 'ICS218', '218', (int)(current_user()['id'] ?? 0));
    $stmt = $pdo->prepare("SELECT id FROM form_218_headers WHERE form_record_id=? LIMIT 1");
    $stmt->execute([$formRecordId]);
    $headerId = (int)($stmt->fetch()['id'] ?? 0);
    if ($headerId <= 0) {
        $stmt = $pdo->prepare("INSERT INTO form_218_headers (form_record_id, prepared_by_name, prepared_by_position, prepared_at, created_at, updated_at) VALUES (?,?,?,?,NOW(),NOW())");
        $u=current_user();
        $stmt->execute([$formRecordId,(string)($u['display_name']??''),(string)($u['role_code']??''),date('Y-m-d H:i:s')]);
        $headerId=(int)$pdo->lastInsertId();
    }
    return $headerId;
}

function resources_create_ics218_entry_from_intake(int $id, int $incidentId): int {
    $pdo=db();
    $row=resources_get_intake($id,$incidentId);
    if (!$row) { throw new RuntimeException('Intake item not found.'); }
    $headerId=resources_get_or_create_ics218_header($incidentId);
    $existing=(int)($row['ics218_entry_id'] ?? 0);
    $desc=trim((string)($row['resource_description'] ?? '')) ?: trim((string)($row['resource_kind'] ?? '').' '.(string)($row['resource_type'] ?? ''));
    $remarks=trim("Source 213RR: ".(string)($row['source_request_number'] ?? '')."\nLogistics order: ".(string)($row['logistics_order_number'] ?? '')."\n".(string)($row['notes'] ?? ''));
    if ($existing>0) {
        $stmt=$pdo->prepare("UPDATE form_218_entries SET equipment_description=?, kind=?, type=?, owner_agency=?, operator_name=?, contact_info=?, location=?, order_request_number=?, status='received', remarks=?, updated_at=NOW() WHERE id=? AND header_id=? AND deleted_at IS NULL");
        $stmt->execute([$desc,(string)($row['resource_kind']??''),(string)($row['resource_type']??''),(string)($row['supplier_name']??''),(string)($row['received_by_name']??''),(string)($row['supplier_contact']??''),(string)($row['initial_location']??''),(string)($row['logistics_order_number']??''),$remarks,$existing,$headerId]);
        return $existing;
    }
    $stmt=$pdo->prepare("INSERT INTO form_218_entries (header_id,equipment_description,kind,type,owner_agency,operator_name,contact_info,location,order_request_number,status,remarks,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,'received',?,NOW(),NOW())");
    $stmt->execute([$headerId,$desc,(string)($row['resource_kind']??''),(string)($row['resource_type']??''),(string)($row['supplier_name']??''),(string)($row['received_by_name']??''),(string)($row['supplier_contact']??''),(string)($row['initial_location']??''),(string)($row['logistics_order_number']??''),$remarks]);
    $entryId=(int)$pdo->lastInsertId();
    $pdo->prepare("UPDATE resource_unit_intake SET ics218_entry_id=?, intake_status='checked_in', checked_in_at=NOW(), checked_in_by_user_id=?, checked_in_by_name=?, updated_at=NOW() WHERE id=? AND incident_id=?")
        ->execute([$entryId,(int)(current_user()['id']??0)?:null,(string)(current_user()['display_name']??''),$id,$incidentId]);
    return $entryId;
}

function resources_ensure_demob_tracker(int $id, int $incidentId): void {
    $pdo=db();
    $row=resources_get_intake($id,$incidentId);
    if (!$row) { return; }
    $label=trim((string)($row['resource_description'] ?? '')) ?: trim((string)($row['resource_kind'] ?? '').' '.(string)($row['resource_type'] ?? ''));
    $stmt=$pdo->prepare("INSERT INTO resource_demob_tracker (incident_id,resource_unit_intake_id,source_213rr_header_id,resource_label,demob_status,created_at,updated_at) VALUES (?,?,?,?, 'not_ready_for_demob', NOW(), NOW()) ON DUPLICATE KEY UPDATE resource_label=VALUES(resource_label), updated_at=NOW(), id=LAST_INSERT_ID(id)");
    $stmt->execute([$incidentId,$id,(int)($row['source_213rr_header_id'] ?? 0) ?: null,$label]);
    $demobId=(int)$pdo->lastInsertId();
    $pdo->prepare("UPDATE resource_unit_intake SET demob_tracker_id=? WHERE id=? AND incident_id=?")->execute([$demobId,$id,$incidentId]);
}

function resources_auto_route_received(int $id, int $incidentId): string {
    $row=resources_get_intake($id,$incidentId);
    if (!$row) { return ''; }
    if (resources_is_equipment_category((string)($row['resource_category'] ?? ''))) {
        resources_create_ics218_entry_from_intake($id,$incidentId);
        resources_ensure_demob_tracker($id,$incidentId);
        resources_sync_source_213rr_after_receipt($id,$incidentId);
        return 'Resource receipt recorded and added to ICS 218 and the demobilization tracker.';
    }
    resources_create_ics211_entry_from_intake($id,$incidentId);
    resources_ensure_demob_tracker($id,$incidentId);
    resources_sync_source_213rr_after_receipt($id,$incidentId);
    return 'Resource receipt recorded and added to ICS 211 and the demobilization tracker.';
}


if (!resources_user_can_view($incidentId)) {
    render_header('Resources Unit');
    echo '<div class="card"><p>You do not have access to the Resources Unit intake queue.</p></div>';
    render_footer();
    exit;
}

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!resources_user_can_manage($incidentId)) {
            throw new RuntimeException('Not authorized to manage Resources Unit intake.');
        }
        $postAction = (string)($_POST['post_action'] ?? '');
        if ($postAction === 'create_ics218') {
            $entryId = resources_create_ics218_entry_from_intake($id, $incidentId);
            resources_sync_source_213rr_after_receipt($id, $incidentId);
            flash('success', 'Resource added to ICS 218 equipment inventory and source 213RR marked fulfilled.');
            header('Location: ' . app_url('/forms/ics218/index.php'));
            exit;
        }

        if ($postAction === 'create_ics211') {
            $entryId = resources_create_ics211_entry_from_intake($id, $incidentId);
            resources_sync_source_213rr_after_receipt($id, $incidentId);
            flash('success', 'Resource added to ICS 211 check-in list and source 213RR marked fulfilled.');
            header('Location: ' . app_url('/forms/ics211/index.php'));
            exit;
        }

        if ($postAction === 'record_receipt') {
            $intakeBefore = resources_get_intake($id, $incidentId);
            if (!$intakeBefore) { throw new RuntimeException('Intake item not found.'); }
            resources_record_receipt($id, $incidentId, $_POST);
            $msg = resources_auto_route_received($id, $incidentId);
            flash('success', $msg ?: 'Resource receipt recorded in Resources Unit intake.');
            header('Location: ' . app_url('/forms/resources/index.php'));
            exit;
        }

        $newStatus = '';
        if ($postAction === 'mark_reviewed') { $newStatus = 'reviewed'; }
        if ($postAction === 'mark_no_tracking') { $newStatus = 'no_tracking_required'; }
        if ($postAction === 'return_to_logistics') { $newStatus = 'returned_to_logistics'; }
        if ($postAction === 'cancel') { $newStatus = 'cancelled'; }
        if ($postAction === 'reopen') { $newStatus = 'new'; }
        if ($newStatus === '') { throw new RuntimeException('Invalid action.'); }

        $intakeBefore = resources_get_intake($id, $incidentId);
        if (!$intakeBefore) { throw new RuntimeException('Intake item not found.'); }

        $reviewNotes = trim((string)($_POST['review_notes'] ?? ''));
        $publishedForExternalCheckin = false;
        $returnedToLogistics = false;

        if ($newStatus === 'returned_to_logistics') {
            resources_return_intake_to_logistics($intakeBefore, $incidentId, $reviewNotes);
            $returnedToLogistics = true;

            $sourceHeaderId = (int)($intakeBefore['source_213rr_header_id'] ?? 0);
            ics213rr_create_notification(
                $incidentId,
                null,
                'LSC',
                'resource_intake_returned',
                'Resources Unit intake returned to Logistics',
                (string)($intakeBefore['source_request_number'] ?? 'A resource intake item')
                    . ' was returned by Resources Unit and requires Logistics approval again.'
                    . ($reviewNotes !== '' ? ' Reason: ' . $reviewNotes : ''),
                app_url('/forms/ics213rr_v2/index.php?action=view&id=' . $sourceHeaderId),
                $sourceHeaderId ?: null
            );
        } else {
            resources_update_status($id, $incidentId, $newStatus, $reviewNotes);
            if ($newStatus === 'reviewed') {
                $publishedForExternalCheckin = resources_publish_expected_checkin_after_review($id, $incidentId);
            }
        }

        flash(
            'success',
            $returnedToLogistics
                ? 'Resource returned to Logistics for approval again. The expected check-in record was withdrawn.'
                : ($publishedForExternalCheckin
                    ? 'Resources Unit intake reviewed and published for external check-in.'
                    : 'Resources Unit intake status updated.')
        );
        header('Location: ' . app_url('/forms/resources/index.php?action=view&id=' . $id));
        exit;
    }
} catch (Throwable $e) {
    flash('error', $e->getMessage());
}

render_header('Resources Unit');

try {
    if ($action === 'view' && $id > 0) {
        $row = resources_get_intake($id, $incidentId);
        if (!$row) {
            echo '<div class="card"><p>Resources Unit intake item not found.</p></div>';
        } else {
            ?>
            <div class="card">
              <h2>Resources Unit Intake - <?= e((string)($row['source_request_number'] ?? '')) ?> <span class="status-pill"><?= e(resources_status_label((string)$row['intake_status'])) ?></span></h2>
              <p><a class="btn" href="<?= e(app_url('/forms/resources/index.php')) ?>">Back to Intake Queue</a>
              <?php if (!empty($row['source_213rr_header_id'])): ?>
                <a class="btn" href="<?= e(app_url('/forms/ics213rr_v2/index.php?action=view&id=' . (int)$row['source_213rr_header_id'])) ?>">Open Source 213RR</a>
              <?php endif; ?>
              </p>

              <div class="info-panel">
                <h3>Resource Summary</h3>
                <p><strong>Category:</strong> <?= e((string)$row['resource_category']) ?> &nbsp; <strong>Quantity:</strong> <?= e((string)($row['quantity_received'] ?? '')) ?></p>
                <p><strong>Kind:</strong> <?= e((string)($row['resource_kind'] ?? '')) ?> &nbsp; <strong>Type/Unit:</strong> <?= e((string)($row['resource_type'] ?? '')) ?></p>
                <p><strong>Description:</strong><br><?= nl2br(e((string)($row['resource_description'] ?? ''))) ?></p>
              </div>

              <div class="info-panel">
                <h3>Arrival / Initial Assignment</h3>
                <p><strong>Initial location:</strong> <?= e((string)($row['initial_location'] ?? '')) ?></p>
                <p><strong>Received at:</strong> <?= e((string)($row['received_at'] ?? '')) ?> &nbsp; <strong>Received by:</strong> <?= e((string)($row['received_by_name'] ?? '')) ?> <?= !empty($row['received_by_position']) ? '(' . e((string)$row['received_by_position']) . ')' : '' ?></p>
                <p><strong>Supplier/agency:</strong> <?= e((string)($row['supplier_name'] ?? '')) ?> &nbsp; <strong>Contact:</strong> <?= e((string)($row['supplier_contact'] ?? '')) ?></p>
                <p><strong>Logistics order #:</strong> <?= e((string)($row['logistics_order_number'] ?? '')) ?></p>
                <?php if (!empty($row['ics211_entry_id'])): ?>
                  <p><strong>ICS 211 entry:</strong> #<?= e((string)$row['ics211_entry_id']) ?> &nbsp; <strong>Checked in:</strong> <?= e((string)($row['checked_in_at'] ?? '')) ?> by <?= e((string)($row['checked_in_by_name'] ?? '')) ?></p>
                <?php endif; ?>
                <?php if (!empty($row['ics218_entry_id'])): ?>
                  <p><strong>ICS 218 entry:</strong> #<?= e((string)$row['ics218_entry_id']) ?></p>
                <?php endif; ?>
              </div>

              <?php if (!empty($row['notes'])): ?>
              <div class="info-panel">
                <h3>Notes</h3>
                <p><?= nl2br(e((string)$row['notes'])) ?></p>
              </div>
              <?php endif; ?>

              <div class="info-panel">
                <h3>Check-in Release / Review Metadata</h3>
                <p><strong>Created:</strong> <?= e((string)($row['created_at'] ?? '')) ?> by <?= e((string)($row['created_by_name'] ?? '')) ?></p>
                <p><strong>Released / reviewed:</strong> <?= e((string)($row['reviewed_at'] ?? '')) ?> by <?= e((string)($row['reviewed_by_name'] ?? '')) ?></p>
              </div>

              <?php if (resources_user_can_manage($incidentId)): ?>
              <div class="info-panel">
                <h3>Record Resources Unit Receipt / Check-In Preparation</h3>
                <form method="post" class="form-grid">
    <?= csrf_field() ?>
                  <input type="hidden" name="id" value="<?= e((string)$id) ?>">
                  <label>Quantity received<input type="number" step="0.01" name="quantity_received" value="<?= e((string)($row['quantity_received'] ?? '')) ?>"></label>
                  <div class="govai-dt-wrap" data-govai-datetime>
                    <label>Received at<input class="govai-dt-display" type="text" inputmode="numeric" pattern="[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}" placeholder="YYYY-MM-DD HH:mm" name="received_at" value="<?= e(!empty($row['received_at']) ? date('Y-m-d H:i', strtotime((string)$row['received_at'])) : '') ?>"></label>
                    <button type="button" class="btn btn-secondary govai-dt-button">Pick</button>
                  </div>
                  <label>Received by name<input name="received_by_name" value="<?= e((string)($row['received_by_name'] ?? '')) ?>"></label>
                  <label>Received by position<input name="received_by_position" value="<?= e((string)($row['received_by_position'] ?? '')) ?>"></label>
                  <label>Receiving / check-in location<input name="initial_location" value="<?= e((string)($row['initial_location'] ?? '')) ?>"></label>
                  <label>Supplier / agency / owner<input name="supplier_name" value="<?= e((string)($row['supplier_name'] ?? '')) ?>"></label>
                  <label>Contact<input name="supplier_contact" value="<?= e((string)($row['supplier_contact'] ?? '')) ?>"></label>
                  <label class="span-2">Receipt notes<textarea name="receipt_notes" placeholder="Record arrival condition, staging/check-in details, or information needed for ICS 211."></textarea></label>
                  <div class="span-2 form-actions">
                    <button class="btn primary" name="post_action" value="record_receipt">Record Receipt in Resources Unit</button>
                  </div>
                </form>
              </div>

              <?php if (in_array((string)$row['intake_status'], ['received','checked_in'], true)): ?>
              <div class="info-panel">
                <h3>Linked ICS Tracking Form</h3>
                <?php if (in_array((string)($row['resource_category'] ?? ''), ['vehicle','equipment'], true)): ?>
                  <?php if (!empty($row['ics218_entry_id'])): ?>
                    <p>This equipment resource has been added to ICS 218.</p><p><a class="btn primary" href="<?= e(app_url('/forms/ics218/index.php')) ?>">Open ICS 218</a></p>
                  <?php else: ?>
                    <form method="post">
    <?= csrf_field() ?><input type="hidden" name="id" value="<?= e((string)$id) ?>"><button class="btn primary" name="post_action" value="create_ics218">Add to ICS 218 Equipment Inventory</button></form>
                  <?php endif; ?>
                <?php else: ?>
                  <?php if (!empty($row['ics211_entry_id'])): ?>
                    <p>This resource has been added to ICS 211.</p><p><a class="btn primary" href="<?= e(app_url('/forms/ics211/index.php')) ?>">Open ICS 211</a></p>
                  <?php else: ?>
                    <form method="post">
    <?= csrf_field() ?><input type="hidden" name="id" value="<?= e((string)$id) ?>"><button class="btn primary" name="post_action" value="create_ics211">Add to ICS 211 Check-In List</button></form>
                  <?php endif; ?>
                <?php endif; ?>
              </div>
              <?php endif; ?>

              <form method="post" class="form-grid">
    <?= csrf_field() ?>
                <input type="hidden" name="id" value="<?= e((string)$id) ?>">
                <label class="span-2">Exception / review notes<textarea name="review_notes" placeholder="Document an exception, clarification required, or why no tracking is needed."></textarea></label>
                <div class="span-2 form-actions rr-actions">
                  <?php $st=(string)$row['intake_status']; ?>
                  <?php if (in_array($st,['new','reviewed'],true)): ?>
                    <?php if ($st==='new'): ?><button class="btn primary" name="post_action" value="mark_reviewed">Release for Check-in</button><?php endif; ?>
                    <button class="btn" name="post_action" value="mark_no_tracking">Mark No Tracking Required</button>
                    <button class="btn" name="post_action" value="return_to_logistics">Return to Logistics</button>
                    <button class="btn" name="post_action" value="cancel">Cancel Intake</button>
                  <?php elseif (in_array($st,['no_tracking_required','returned_to_logistics','cancelled'],true)): ?>
                    <button class="btn" name="post_action" value="reopen">Reopen</button>
                  <?php else: ?>
                    <span class="small">After receipt/check-in, use the linked ICS 211/218 and demobilization process rather than cancelling or marking no tracking required.</span>
                  <?php endif; ?>
                </div>
                <p class="span-2 small">LSC-approved requests are released to check-in automatically. Use these actions only to address an exception. Receipt automatically routes non-equipment to ICS 211 and vehicle/equipment resources to ICS 218.</p>
              </form>
              <?php endif; ?>
            </div>
            <?php
        }
    } else {
        $stmt = db()->prepare("
            SELECT *
            FROM resource_unit_intake
            WHERE incident_id=?
              AND intake_status IN ('pending_arrival','new','reviewed','returned_to_logistics')
            ORDER BY FIELD(intake_status,'pending_arrival','new','returned_to_logistics','reviewed'), COALESCE(received_at, created_at) DESC, id DESC
        ");
        $stmt->execute([$incidentId]);
        $openRows = $stmt->fetchAll();

        $activeStmt = db()->prepare("
            SELECT *
            FROM resource_unit_intake
            WHERE incident_id=?
              AND intake_status IN ('received','checked_in')
            ORDER BY COALESCE(received_at, updated_at, created_at) DESC, id DESC
            LIMIT 100
        ");
        $activeStmt->execute([$incidentId]);
        $activeRows = $activeStmt->fetchAll();

        $closedStmt = db()->prepare("
            SELECT *
            FROM resource_unit_intake
            WHERE incident_id=?
              AND intake_status IN ('converted','no_tracking_required','cancelled')
            ORDER BY COALESCE(updated_at, reviewed_at, created_at) DESC, id DESC
            LIMIT 50
        ");
        $closedStmt->execute([$incidentId]);
        $closedRows = $closedStmt->fetchAll();
        ?>
        <div class="card">
          <div class="card-header"><h2>Resources Unit Intake Queue</h2></div>
          <p class="small">This queue receives LSC-approved 213RR items that are released automatically for check-in. Items move to Received / Active Resources after Resources Unit records receipt; RESL action is required only for an exception.</p>
          <?php if (!$openRows): ?>
            <p>No open Resources Unit intake items exist for this incident.</p>
          <?php else: ?>
            <table>
              <thead><tr><th>Request #</th><th>Status</th><th>Category</th><th>Qty</th><th>Kind / Type</th><th>Initial location</th><th>Received at</th><th>Received by</th><th>Action</th></tr></thead>
              <tbody>
              <?php foreach ($openRows as $r): ?>
                <tr>
                  <td><?= e((string)$r['source_request_number']) ?></td>
                  <td><span class="status-pill"><?= e(resources_status_label((string)$r['intake_status'])) ?></span></td>
                  <td><?= e((string)$r['resource_category']) ?></td>
                  <td><?= e((string)($r['quantity_received'] ?? '')) ?></td>
                  <td><?= e(trim((string)($r['resource_kind'] ?? '') . (((string)($r['resource_kind'] ?? '') !== '' && (string)($r['resource_type'] ?? '') !== '') ? ' / ' : '') . (string)($r['resource_type'] ?? ''))) ?></td>
                  <td><?= e((string)($r['initial_location'] ?? '')) ?></td>
                  <td><?= e((string)($r['received_at'] ?? '')) ?></td>
                  <td><?= e((string)($r['received_by_name'] ?? '')) ?></td>
                  <td><a class="btn small" href="<?= e(app_url('/forms/resources/index.php?action=view&id=' . (int)$r['id'])) ?>">Open</a></td>
                </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          <?php endif; ?>
        </div>

        <div class="card">
          <div class="card-header"><h2>Received / Active Resources</h2></div>
          <p class="small">Trackable resources that have been received by Resources Unit remain visible here until they are converted to the resource status board / ICS 211 workflow in a later step.</p>
          <?php if (!$activeRows): ?>
            <p>No received or active Resources Unit items exist for this incident.</p>
          <?php else: ?>
            <table>
              <thead><tr><th>Request #</th><th>Status</th><th>Category</th><th>Qty</th><th>Kind / Type</th><th>Location</th><th>Received at</th><th>Received by</th><th>Action</th></tr></thead>
              <tbody>
              <?php foreach ($activeRows as $r): ?>
                <tr>
                  <td><?= e((string)$r['source_request_number']) ?></td>
                  <td><span class="status-pill"><?= e(resources_status_label((string)$r['intake_status'])) ?></span></td>
                  <td><?= e((string)$r['resource_category']) ?></td>
                  <td><?= e((string)($r['quantity_received'] ?? '')) ?></td>
                  <td><?= e(trim((string)($r['resource_kind'] ?? '') . (((string)($r['resource_kind'] ?? '') !== '' && (string)($r['resource_type'] ?? '') !== '') ? ' / ' : '') . (string)($r['resource_type'] ?? ''))) ?></td>
                  <td><?= e((string)($r['initial_location'] ?? '')) ?></td>
                  <td><?= e((string)($r['received_at'] ?? '')) ?></td>
                  <td><?= e((string)($r['received_by_name'] ?? '')) ?></td>
                  <td><a class="btn small" href="<?= e(app_url('/forms/resources/index.php?action=view&id=' . (int)$r['id'])) ?>">Open</a></td>
                </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          <?php endif; ?>
        </div>

        <div class="card">
          <div class="card-header"><h2>Closed / Resolved Intake Items</h2></div>
          <?php if (!$closedRows): ?>
            <p>No closed or resolved Resources Unit intake items exist for this incident.</p>
          <?php else: ?>
            <table>
              <thead><tr><th>Request #</th><th>Status</th><th>Category</th><th>Qty</th><th>Kind / Type</th><th>Updated</th><th>Action</th></tr></thead>
              <tbody>
              <?php foreach ($closedRows as $r): ?>
                <tr>
                  <td><?= e((string)$r['source_request_number']) ?></td>
                  <td><span class="status-pill"><?= e(resources_status_label((string)$r['intake_status'])) ?></span></td>
                  <td><?= e((string)$r['resource_category']) ?></td>
                  <td><?= e((string)($r['quantity_received'] ?? '')) ?></td>
                  <td><?= e(trim((string)($r['resource_kind'] ?? '') . (((string)($r['resource_kind'] ?? '') !== '' && (string)($r['resource_type'] ?? '') !== '') ? ' / ' : '') . (string)($r['resource_type'] ?? ''))) ?></td>
                  <td><?= e((string)($r['updated_at'] ?? $r['reviewed_at'] ?? $r['created_at'] ?? '')) ?></td>
                  <td><a class="btn small" href="<?= e(app_url('/forms/resources/index.php?action=view&id=' . (int)$r['id'])) ?>">View</a></td>
                </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          <?php endif; ?>
        </div>
        <?php
    }
} catch (Throwable $e) {
    echo '<div class="card error"><h2>The Resources Unit page loaded, but the intake queue hit a database or runtime error.</h2><p>' . e($e->getMessage()) . '</p><p class="small">Confirm that patch_resource_unit_intake.sql has been applied.</p></div>';
}

render_footer();