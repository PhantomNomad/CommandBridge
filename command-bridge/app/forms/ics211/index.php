<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/security.php';
require_csrf_for_post();

require_once __DIR__ . '/../../includes/form_action_buttons.php'; ?>
<?php

require_once dirname(dirname(__DIR__)) . '/includes/layout.php';
require_login();

$pdo = db();
$incident = active_incident();
$incidentId = (int)($incident['id'] ?? 0);

if ($incidentId > 0) {
    require_active_operational_period($incidentId);
}
$error = null;

$permissions = $incidentId > 0 ? get_current_user_form_permissions('ICS211', $incidentId) : get_form_permission_defaults('ICS211');
$canView = !empty($permissions['can_view']);
$canEdit = !empty($permissions['can_edit']);
$formRecord = $incidentId > 0 ? get_form_record($incidentId, 'ICS211') : null;
$formRecordId = (int)($formRecord['id'] ?? 0);
$formStatus = (string)($formRecord['status'] ?? 'draft');
$preparer = $incidentId > 0 ? resolve_form_preparer($incidentId, 'ICS211') : ['name' => 'TBD', 'position_title' => 'DOCL'];
$signature = $formRecordId > 0 ? get_form_signature($formRecordId) : null;

if ($incidentId > 0 && !$canView) {
    http_response_code(403);
    render_header('ICS 211 - Check-In List');
    echo '<div class="card"><p>Forbidden</p></div>';
    render_footer();
    exit;
}

// Signed or approved: locked until someone revises it (includes/form_lock.php).
require_once dirname(dirname(__DIR__)) . '/includes/form_lock.php';
cb_form_block_locked_post('ICS211', (int)($incidentId ?? 0), (int)($opId ?? 0), (int)($formRecordId ?? 0));

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $incidentId > 0) {
    if (!$canEdit) {
        $error = 'You do not have permission to edit ICS 211.';
    } else {
        try {
            $formRecordId = get_or_create_form_record($incidentId, 'ICS211', '211', (int)(current_user()['id'] ?? 0));

            $stmt = $pdo->prepare("SELECT id FROM form_211_headers WHERE form_record_id = ? LIMIT 1");
            $stmt->execute([$formRecordId]);
            $headerId = (int)($stmt->fetch()['id'] ?? 0);

            $resolvedPreparer = resolve_form_preparer($incidentId, 'ICS211');
            apply_form_preparer_defaults($formRecordId, $resolvedPreparer);

            $checkInLocation = trim($_POST['check_in_location'] ?? 'ICP') ?: 'ICP';
            $startDateTime = trim($_POST['start_datetime'] ?? '') ?: null;
            $preparedPosition = $resolvedPreparer['position_title'] ?? 'DOCL';
            $pageNo = max(1, (int)($_POST['page_no'] ?? 1));
            $totalPages = max(1, (int)($_POST['total_pages'] ?? 1));

            if ($headerId === 0) {
                $stmt = $pdo->prepare("
                    INSERT INTO form_211_headers
                    (form_record_id, check_in_location, start_datetime, page_no, total_pages, prepared_by_position_title)
                    VALUES (?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([$formRecordId, $checkInLocation, $startDateTime, $pageNo, $totalPages, $preparedPosition]);
                $headerId = (int)$pdo->lastInsertId();
            } else {
                $stmt = $pdo->prepare("
                    UPDATE form_211_headers
                    SET check_in_location = ?, start_datetime = ?, page_no = ?, total_pages = ?, prepared_by_position_title = ?
                    WHERE id = ?
                ");
                $stmt->execute([$checkInLocation, $startDateTime, $pageNo, $totalPages, $preparedPosition, $headerId]);
            }

            $action = (string)($_POST['form_action'] ?? 'save');

            if ($action === 'submit') {
                if ($formStatus === 'submitted') {
                    throw new RuntimeException('ICS 211 has already been submitted.');
                }
                submit_form_record($formRecordId, 'ICS211', $incidentId, (int)(current_user()['id'] ?? 0));
                flash('success', 'ICS 211 submitted and digitally signed.');
            } else {
                if ($formStatus === 'submitted') {
                    throw new RuntimeException('ICS 211 is already submitted and can no longer be edited.');
                }

                $checkInDateTime = trim($_POST['check_in_datetime'] ?? '') ?: date('Y-m-d H:i:s');
                $contact = trim(implode(' ', array_filter([
                    trim($_POST['email'] ?? ''),
                    trim($_POST['phone'] ?? '')
                ])));

                $stmt = $pdo->prepare("
                    INSERT INTO form_211_entries
                    (header_id, user_id, category, kind, type_st_tf, resource_identifier, order_request_number, ldw_request_number,
                     person_name, home_agency, contact_information, leader_name, total_personnel, arrival_date, departure_point,
                     method_of_travel, incident_assignment, other_qualifications, sent_to_resource_unit, remarks, checkin_time, created_at, updated_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
                ");
                $stmt->execute([
                    $headerId,
                    current_user()['id'] ?? null,
                    trim($_POST['category'] ?? '') ?: null,
                    trim($_POST['kind'] ?? '') ?: null,
                    trim($_POST['type_st_tf'] ?? '') ?: null,
                    trim($_POST['resource_identifier'] ?? '') ?: null,
                    trim($_POST['order_request_number'] ?? '') ?: null,
                    trim($_POST['ldw_request_number'] ?? '') ?: null,
                    trim($_POST['person_name'] ?? ''),
                    trim($_POST['agency'] ?? '') ?: null,
                    $contact ?: null,
                    trim($_POST['leader_name'] ?? '') ?: null,
                    ($_POST['total_personnel'] ?? '') !== '' ? (int)$_POST['total_personnel'] : null,
                    $checkInDateTime,
                    trim($_POST['departure_point'] ?? '') ?: null,
                    trim($_POST['method_of_travel'] ?? '') ?: null,
                    trim($_POST['assignment_details'] ?? '') ?: null,
                    trim($_POST['other_qualifications'] ?? '') ?: null,
                    !empty($_POST['sent_to_resource_unit']) ? 1 : 0,
                    trim($_POST['remarks'] ?? '') ?: null,
                    $checkInDateTime
                ]);

                flash('success', 'ICS 211 check-in entry saved.');
            }

            header('Location: ' . app_url('/forms/ics211/index.php'));
            exit;
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }
    }
}

$permissions = $incidentId > 0 ? get_current_user_form_permissions('ICS211', $incidentId) : get_form_permission_defaults('ICS211');
$canView = !empty($permissions['can_view']);
$canEdit = !empty($permissions['can_edit']);
$formRecord = $incidentId > 0 ? get_form_record($incidentId, 'ICS211') : null;
$formRecordId = (int)($formRecord['id'] ?? 0);
$formStatus = (string)($formRecord['status'] ?? 'draft');
$preparer = $incidentId > 0 ? resolve_form_preparer($incidentId, 'ICS211') : ['name' => 'TBD', 'position_title' => 'DOCL'];
$signature = $formRecordId > 0 ? get_form_signature($formRecordId) : null;
$canSubmit = $incidentId > 0 && $formStatus !== 'submitted' && !empty($permissions['can_submit_final']);

$header = [
    'check_in_location' => 'ICP',
    'start_datetime' => current_datetime_local_value(),
    'page_no' => 1,
    'total_pages' => 1,
];
$kindTypeCatalog = resource_kind_type_catalog();
$rows = [];
if ($incidentId > 0) {
    $stmt = $pdo->prepare("
        SELECT h.check_in_location, h.start_datetime, h.page_no, h.total_pages
        FROM form_211_headers h
        INNER JOIN form_records fr ON fr.id = h.form_record_id
        WHERE fr.incident_id = ? AND fr.form_type = 'ICS211' AND fr.is_deleted = 0
        LIMIT 1
    ");
    $stmt->execute([$incidentId]);
    $existingHeader = $stmt->fetch();
    if ($existingHeader) {
        $header = array_merge($header, $existingHeader);
        if (!empty($header['start_datetime'])) {
            $header['start_datetime'] = str_replace(' ', 'T', substr((string)$header['start_datetime'], 0, 16));
        }
    }

    $stmt = $pdo->prepare("
        SELECT
          e.category, e.kind, e.type_st_tf, e.resource_identifier, e.order_request_number, e.ldw_request_number,
          e.person_name, e.home_agency, e.contact_information, e.leader_name, e.total_personnel,
          e.arrival_date, e.departure_point, e.method_of_travel, e.incident_assignment,
          e.other_qualifications, e.sent_to_resource_unit, e.remarks
        FROM form_211_entries e
        INNER JOIN form_211_headers h ON h.id = e.header_id
        INNER JOIN form_records fr ON fr.id = h.form_record_id
        WHERE e.deleted_at IS NULL
          AND fr.incident_id = ?
          AND fr.form_type = 'ICS211'
          AND fr.is_deleted = 0
        ORDER BY e.id DESC
    ");
    $stmt->execute([$incidentId]);
    $rows = $stmt->fetchAll();
}

render_header('ICS 211 - Check-In List');
render_ics_form_status('ICS211', $formRecord, $preparer, $signature, ['error'=>$error, 'show_print'=>false, 'show_pdf'=>false, 'note'=>'This form remains editable while it is in draft.']);
?>
<div class="card ics211-card">
  <div class="ics211-title-row">
    <div>
      <h2>Check-In List <span class="muted">ICS 211-CAN</span></h2>
      <p class="small">Use this page for personnel, equipment, and resource check-in. Completed entries support Resources Unit, Demobilization, and Finance/Admin records.</p>
    </div>
  </div>
  <?php if ($error): ?><div class="flash error"><?= e($error) ?></div><?php endif; ?>
  <?php if ($signature): ?>
    <p><strong>Digitally Signed By:</strong> <?= e($signature['signer_name']) ?> (<?= e($signature['signer_position_title']) ?>) on <?= e($signature['signed_at']) ?></p>
  <?php endif; ?>
</div>

<div class="card ics211-card">
  <h2><?= $formStatus === 'submitted' ? 'ICS 211 Header' : 'Add Check-In Entry' ?></h2>
  <?php if (!$canEdit): ?>
    <p>You have view-only access to this form.</p>
  <?php elseif ($formStatus === 'submitted'): ?>
    <p>This form has been submitted and is now locked.</p>
  <?php else: ?>
  <form method="post" class="ics211-form">
    <?= csrf_field() ?>
    <input type="hidden" name="form_action" value="save">

    <div class="ics211-section-title">Incident / Check-In Location</div>
    <div class="grid">
      <div><label>Incident Name</label><input value="<?= e((string)($incident['name'] ?? '')) ?>" disabled></div>
      <div><label>Incident Number</label><input value="<?= e((string)($incident['incident_number'] ?? $incident['id'] ?? '')) ?>" disabled></div>
      <div class="govai-dt-wrap" data-govai-datetime>
        <div><label>Start Date/Time</label><input class="govai-dt-display" type="text" inputmode="numeric" pattern="[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}" placeholder="YYYY-MM-DD HH:mm" name="start_datetime" value="<?= e((string)$header['start_datetime']) ?>"></div>
        <button type="button" class="btn btn-secondary govai-dt-button">Pick</button>
      </div>
      <div><label>Page</label><div class="inline-fields"><input type="number" min="1" name="page_no" value="<?= e((string)$header['page_no']) ?>"><span>of</span><input type="number" min="1" name="total_pages" value="<?= e((string)$header['total_pages']) ?>"></div></div>
    </div>

    <label>Check-In Location</label>
    <div class="ics211-radio-row">
      <?php foreach (['ICP'=>'ICP', 'Staging Area'=>'Staging Area', 'Base'=>'Base', 'Camp'=>'Camp', 'Helibase'=>'Helibase', 'Other'=>'Other'] as $value => $label): ?>
        <label><input type="radio" name="check_in_location" value="<?= e($value) ?>" <?= (($header['check_in_location'] ?? 'ICP') === $value) ? 'checked' : '' ?>> <?= e($label) ?></label>
      <?php endforeach; ?>
    </div>

    <div class="ics211-section-title">Check-In Information</div>
    <div class="grid">
      <div><label>Agency / CAT.</label><input name="category" placeholder="Agency or category"></div>
      <div><label>Kind</label>
        <select name="kind" id="ics211_kind_select">
          <option value="">-- Select kind --</option>
          <?php foreach (($kindTypeCatalog['categories'] ?? []) as $cat): ?>
            <optgroup label="<?= e((string)($cat['category_name'] ?? 'Other')) ?>">
              <?php foreach (($cat['kinds'] ?? []) as $kind): ?>
                <option value="<?= e((string)($kind['kind_name'] ?? '')) ?>"><?= e((string)($kind['kind_name'] ?? '')) ?></option>
              <?php endforeach; ?>
            </optgroup>
          <?php endforeach; ?>
        </select>
      </div>
      <div><label>Type ST/TF</label>
        <select name="type_st_tf" id="ics211_type_select">
          <option value="">-- Select type --</option>
        </select>
      </div>
      <div><label>Resource Name or ID #</label><input name="resource_identifier"></div>
      <div><label>Order / Request #</label><input name="order_request_number"></div>
      <div><label>LDW Request #</label><input name="ldw_request_number"></div>
      <div class="govai-dt-wrap" data-govai-datetime>
        <div><label>Date/Time Check-In</label><input class="govai-dt-display" type="text" inputmode="numeric" pattern="[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}" placeholder="YYYY-MM-DD HH:mm" name="check_in_datetime" value="<?= e(current_datetime_local_value()) ?>"></div>
        <button type="button" class="btn btn-secondary govai-dt-button">Pick</button>
      </div>
      <div><label>Leader's Name</label><input name="leader_name"></div>
      <div><label>Total # Personnel</label><input type="number" min="0" name="total_personnel"></div>
      <div><label>Contact Information - Email</label><input name="email" type="email"></div>
      <div><label>Contact Information - Phone</label><input name="phone"></div>
      <div><label>Home Unit / Agency</label><input name="agency"></div>
      <div><label>Departure Point</label><input name="departure_point"></div>
      <div><label>Method of Travel</label><input name="method_of_travel" placeholder="P/T, vehicle, air, other"></div>
      <div><label>Incident Assignment</label><input name="assignment_details"></div>
      <div><label>Other Qualifications</label><input name="other_qualifications"></div>
    </div>
    <label>Remarks or Comments</label>
    <textarea name="remarks"></textarea>
    <div class="checkbox-row">
      <input type="checkbox" name="sent_to_resource_unit" value="1" id="sent_to_resource_unit">
      <label for="sent_to_resource_unit">Sent to Resource Unit</label>
    </div>
  <?php
if (function_exists('render_ics_form_actions')) {
    $faPerms = $permissions ?? $formPermissions ?? ['can_view'=>true,'can_create'=>true,'can_edit'=>true,'can_submit_final'=>true];
    $faIncident = $incidentId ?? ($_GET['incident_id'] ?? ($_POST['incident_id'] ?? ''));
    $faOp = $operationalPeriodId ?? $opId ?? ($_GET['op_id'] ?? ($_GET['operational_period_id'] ?? ''));
    render_ics_form_actions([
        'incident_id' => $faIncident ?? null,
        'op_id' => $faOp ?? null,
        'form_code' => 'ICS211',
        'form_title' => 'ICS211',
        'permissions' => $faPerms,
        'is_final' => !empty($record['is_final']) || !empty($formRecord['is_final']),        'blank_download_url' => file_exists(__DIR__ . '/blank.php') ? 'blank.php' : null,
    ]);
}
?>
</form>
  <?php endif; ?>
</div>

<div class="card ics211-card">
  <h2>Existing Check-In Entries</h2>
  <div class="ics211-table-wrap">
    <table class="ics211-table cb-cards">
      <tr>
        <th>Agency / CAT.</th><th>Kind / Type</th><th>Resource Name or ID #</th><th>Order / Request #</th><th>LDW #</th>
        <th>Date/Time Check-In</th><th>Leader</th><th>Total Personnel</th><th>Contact</th><th>Home Unit / Agency</th>
        <th>Departure Point</th><th>Travel</th><th>Assignment</th><th>Other Quals</th><th>Sent to RESL</th><th>Remarks</th>
      </tr>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td><?= e($r['category'] ?? '') ?></td>
          <td><?= e(trim((string)($r['kind'] ?? '') . (((string)($r['kind'] ?? '') !== '' && (string)($r['type_st_tf'] ?? '') !== '') ? ' / ' : '') . (string)($r['type_st_tf'] ?? ''))) ?></td>
          <td><?= e($r['resource_identifier'] ?: $r['person_name']) ?></td>
          <td><?= e($r['order_request_number'] ?? '') ?></td>
          <td><?= e($r['ldw_request_number'] ?? '') ?></td>
          <td><?= e($r['arrival_date']) ?></td>
          <td><?= e($r['leader_name'] ?? '') ?></td>
          <td><?= e((string)($r['total_personnel'] ?? '')) ?></td>
          <td><?= e($r['contact_information'] ?? '') ?></td>
          <td><?= e($r['home_agency'] ?? '') ?></td>
          <td><?= e($r['departure_point'] ?? '') ?></td>
          <td><?= e($r['method_of_travel'] ?? '') ?></td>
          <td><?= e($r['incident_assignment'] ?? '') ?></td>
          <td><?= e($r['other_qualifications'] ?? '') ?></td>
          <td><?= !empty($r['sent_to_resource_unit']) ? 'Yes' : 'No' ?></td>
          <td><?= e($r['remarks'] ?? '') ?></td>
        </tr>
      <?php endforeach; ?>
    </table>
  </div>
</div>


<script>
(function(){
  const catalog = <?= json_encode($kindTypeCatalog['categories'] ?? []) ?>;
  const kindSelect = document.getElementById('ics211_kind_select');
  const typeSelect = document.getElementById('ics211_type_select');
  if (!kindSelect || !typeSelect) return;
  const byKind = {};
  catalog.forEach(cat => (cat.kinds || []).forEach(kind => { byKind[kind.kind_name] = kind.types || []; }));
  function refreshTypes() {
    const current = typeSelect.value;
    typeSelect.innerHTML = '<option value="">-- Select type --</option>';
    (byKind[kindSelect.value] || []).forEach(t => {
      const opt = document.createElement('option');
      opt.value = t.label || '';
      opt.textContent = t.label || '';
      typeSelect.appendChild(opt);
    });
    if (current) typeSelect.value = current;
  }
  kindSelect.addEventListener('change', refreshTypes);
  refreshTypes();
})();
</script>

<?php render_footer(); ?>
