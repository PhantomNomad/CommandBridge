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
$user = current_user();
$userId = (int)($user['id'] ?? 0);
$error = null;

if ($incidentId <= 0) {
    render_header('ICS 201 - Incident Briefing');
    echo '<div class="card"><p>No active incident is available.</p></div>';
    render_footer();
    exit;
}

$formRecord = get_form_record($incidentId, 'ICS201');
$formRecordId = (int)($formRecord['id'] ?? 0);
$formStatus = (string)($formRecord['status'] ?? 'draft');
$preparer = resolve_form_preparer($incidentId, 'ICS201');
$signature = $formRecordId > 0 ? get_form_signature($formRecordId) : null;
$permissions = get_current_user_form_permissions('ICS201', $incidentId);
$canView = !empty($permissions['can_view']);
$isLockedFinal = in_array($formStatus, ['submitted','approved'], true);
$canEdit = !$isLockedFinal && !empty($permissions['can_edit']);
$canSubmit = false; // ICS 201 is approved/submitted through the operational period workflow, not from this form page.

$record = get_ics201_record($incidentId);
if (!$canView) {
    http_response_code(403);
    render_header('ICS 201 - Incident Briefing');
    echo '<div class="card"><p>Forbidden</p></div>';
    render_footer();
    exit;
}

if (!$record && $canEdit) {
    $formRecordId = get_or_create_form_record($incidentId, 'ICS201', '201', $userId);
    apply_form_preparer_defaults($formRecordId, $preparer);
    $record = ensure_ics201_record($incidentId, $userId);
}
$form201Id = (int)($record['id'] ?? 0);

function normalize_action_rows(array $times, array $texts): array {
    $max = max(count($times), count($texts));
    $rows = [];
    for ($i = 0; $i < $max; $i++) {
        $rows[] = [
            'action_time' => (string)($times[$i] ?? ''),
            'action_text' => (string)($texts[$i] ?? ''),
        ];
    }
    return $rows;
}

function normalize_resource_rows(array $ordered, array $identification, array $eta, array $onScene, array $locationAssignment): array {
    $max = max(count($ordered), count($identification), count($eta), count($onScene), count($locationAssignment));
    $rows = [];
    for ($i = 0; $i < $max; $i++) {
        $rows[] = [
            'resources_ordered' => (string)($ordered[$i] ?? ''),
            'resource_identification' => (string)($identification[$i] ?? ''),
            'eta' => (string)($eta[$i] ?? ''),
            'on_scene' => (string)($onScene[$i] ?? ''),
            'location_assignment' => (string)($locationAssignment[$i] ?? ''),
        ];
    }
    return $rows;
}

// Signed or approved: locked until someone revises it (includes/form_lock.php).
require_once dirname(dirname(__DIR__)) . '/includes/form_lock.php';
cb_form_block_locked_post('ICS201', (int)($incidentId ?? 0), 0, (int)($formRecordId ?? 0));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if ($isLockedFinal) {
            throw new RuntimeException('ICS 201 has been submitted as the incident briefing record and is locked.');
        }
        if (!$canEdit) {
            throw new RuntimeException('You do not have permission to edit ICS 201.');
        }

        $formRecordId = get_or_create_form_record($incidentId, 'ICS201', '201', $userId);
        apply_form_preparer_defaults($formRecordId, resolve_form_preparer($incidentId, 'ICS201'));
        $record = ensure_ics201_record($incidentId, $userId);
        $form201Id = (int)$record['id'];
        $action = (string)($_POST['form_action'] ?? 'save');

        if ($action === 'submit') {
            if (!$canSubmit) {
                throw new RuntimeException('You are not authorized to sign this form.');
            }
            if ($formStatus === 'submitted') {
                throw new RuntimeException('ICS 201 has already been submitted.');
            }

            $submitterName = (string)($user['display_name'] ?? $user['real_name'] ?? $user['username'] ?? 'Unknown User');

            submit_form_record($formRecordId, 'ICS201', $incidentId, $userId);
            $stmt = $pdo->prepare("
                UPDATE form_201_incident_briefing
                SET status = 'submitted',
                    submitted_at = NOW(),
                    signed_at = NOW(),
                    signed_by_user_id = ?,
                    signature_name = ?,
                    updated_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([$userId, $submitterName, $form201Id]);

            audit_log($userId, 'submit', 'ICS201', $form201Id, 'Submitted ICS 201.');
            flash('success', 'ICS 201 submitted and digitally signed.');
            header('Location: ' . app_url('/forms/ics201/index.php'));
            exit;
        }

        if ($formStatus === 'submitted') {
            throw new RuntimeException('ICS 201 is already submitted and can no longer be edited.');
        }

        $preparedByName = trim((string)($_POST['prepared_by_name'] ?? ''));
        $preparedByPosition = trim((string)($_POST['prepared_by_position'] ?? ''));
        $datetimePrepared = trim((string)($_POST['datetime_prepared'] ?? ''));

        if ($preparedByName === '') {
            $preparedByName = (string)($preparer['name'] ?? '');
        }
        if ($preparedByPosition === '') {
            $preparedByPosition = (string)($preparer['position_title'] ?? '');
        }

        $currentActionRows = normalize_action_rows(
            array_values((array)($_POST['current_action_time'] ?? [])),
            array_values((array)($_POST['current_action_text'] ?? []))
        );
        $plannedActionRows = normalize_action_rows(
            array_values((array)($_POST['planned_action_time'] ?? [])),
            array_values((array)($_POST['planned_action_text'] ?? []))
        );
        $resourceRows = normalize_resource_rows(
            array_values((array)($_POST['resources_ordered'] ?? [])),
            array_values((array)($_POST['resource_identification'] ?? [])),
            array_values((array)($_POST['resource_eta'] ?? [])),
            array_values((array)($_POST['resource_on_scene'] ?? [])),
            array_values((array)($_POST['resource_location_assignment'] ?? []))
        );

        $pdo->beginTransaction();

        $stmt = $pdo->prepare("
            UPDATE form_201_incident_briefing
            SET prepared_by_user_id = ?,
                prepared_by_name = ?,
                prepared_by_position = ?,
                datetime_prepared = ?,
                situation_summary = ?,
                safety_briefing = ?,
                current_objectives = ?,
                planned_objectives = ?,
                current_organization = ?,
                status = 'draft',
                updated_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([
            $userId,
            $preparedByName,
            $preparedByPosition,
            $datetimePrepared !== '' ? date('Y-m-d H:i:s', strtotime($datetimePrepared)) : null,
            trim((string)($_POST['situation_summary'] ?? '')) ?: null,
            trim((string)($_POST['safety_briefing'] ?? '')) ?: null,
            trim((string)($_POST['current_objectives'] ?? '')) ?: null,
            trim((string)($_POST['planned_objectives'] ?? '')) ?: null,
            trim((string)($_POST['current_organization'] ?? '')) ?: null,
            $form201Id
        ]);

        replace_ics201_actions($form201Id, 'current', $currentActionRows);
        replace_ics201_actions($form201Id, 'planned', $plannedActionRows);
        replace_ics201_resources($form201Id, $resourceRows);

        $pdo->commit();

        audit_log($userId, 'update', 'ICS201', $form201Id, 'Saved ICS 201 draft.');
        flash('success', 'ICS 201 draft saved.');
        header('Location: ' . app_url('/forms/ics201/index.php'));
        exit;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $error = $e->getMessage();
    }
}

$formRecord = get_form_record($incidentId, 'ICS201');
$formRecordId = (int)($formRecord['id'] ?? 0);
$formStatus = (string)($formRecord['status'] ?? 'draft');
$preparer = resolve_form_preparer($incidentId, 'ICS201');
$signature = $formRecordId > 0 ? get_form_signature($formRecordId) : null;
$record = get_ics201_record($incidentId);
$form201Id = (int)($record['id'] ?? 0);
$attachments = $form201Id > 0 ? get_ics201_attachments($form201Id) : [];
$currentActions = $form201Id > 0 ? get_ics201_actions($form201Id, 'current') : [];
$plannedActions = $form201Id > 0 ? get_ics201_actions($form201Id, 'planned') : [];
$resources = $form201Id > 0 ? get_ics201_resources($form201Id) : [];

if (!$currentActions) {
    $currentActions = [['action_time' => '', 'action_text' => '']];
}
if (!$plannedActions) {
    $plannedActions = [['action_time' => '', 'action_text' => '']];
}
if (!$resources) {
    $resources = [[
        'resources_ordered' => '',
        'resource_identification' => '',
        'eta' => '',
        'on_scene' => '',
        'location_assignment' => '',
    ]];
}

$isPrintView = (string)($_GET['view'] ?? '') === 'print';
$pageTitle = $isPrintView ? 'ICS 201 - Incident Briefing Print View' : 'ICS 201 - Incident Briefing';
render_header($pageTitle);
?>
<style>
.ics201-dt-wrap{display:flex;align-items:center;gap:8px;flex-wrap:nowrap}
.ics201-dt-display{min-width:180px}
.ics201-dt-button{white-space:nowrap}
.ics201-dt-popover{position:absolute;z-index:1000;background:#fff;border:1px solid #999;border-radius:4px;box-shadow:0 4px 14px rgba(0,0,0,.18);padding:10px;margin-top:4px;width:292px;color:#111}
.ics201-dt-head{display:flex;align-items:center;justify-content:space-between;margin-bottom:8px;gap:6px}
.ics201-dt-head button{padding:4px 8px}
.ics201-dt-month{font-weight:700;text-align:center;flex:1}
.ics201-dt-grid{display:grid;grid-template-columns:repeat(7,1fr);gap:3px}
.ics201-dt-dow{font-size:12px;text-align:center;font-weight:700;color:#555;padding:3px 0}
.ics201-dt-popover .ics201-dt-grid button.ics201-dt-day{
  appearance:none;-webkit-appearance:none;
  padding:6px 0 !important;
  border:1px solid #ddd !important;
  background:#fff !important;
  background-color:#fff !important;
  color:#111 !important;
  cursor:pointer;
  text-align:center;
  border-radius:3px;
  font:inherit;
  box-shadow:none !important;
  text-shadow:none !important;
}
.ics201-dt-popover .ics201-dt-grid button.ics201-dt-day:hover,
.ics201-dt-popover .ics201-dt-grid button.ics201-dt-day.selected{
  background:#1f5f99 !important;
  background-color:#1f5f99 !important;
  color:#fff !important;
}
.ics201-dt-day.blank{visibility:hidden}
.ics201-dt-time{display:flex;align-items:center;gap:6px;margin-top:10px}
.ics201-dt-time select{width:auto}
.ics201-dt-actions{display:flex;justify-content:flex-end;gap:8px;margin-top:10px}
@media(max-width:640px){.ics201-dt-wrap{flex-wrap:wrap}.ics201-dt-popover{width:270px}}
</style>
<?php

function field_value(array $record, string $key): string {
    return (string)($record[$key] ?? '');
}

function datetime_local_value(?string $value): string {
    if (!$value) {
        return '';
    }
    $ts = strtotime($value);
    return $ts ? date('Y-m-d H:i', $ts) : '';
}
?>
<?php if ($error): ?><div class="flash error"><?= e($error) ?></div><?php endif; ?>
<?php if (!$isPrintView) { cb_form_status_banner('ICS201', $incidentId, 0, $formRecordId, ['subtitle' => 'Incident briefing']); } ?>
<p class="small no-print">ICS 201 is normally prepared by the initial Incident Commander; the Planning Section Chief and Documentation Unit Leader can also fill it in. Attachments accepted: BMP, JPG, JPEG, PNG, PDF and TXT.</p>

<?php if (!$isPrintView): ?>
<div class="card">
  <h2>Edit ICS 201</h2>
  <?php if ($isLockedFinal): ?>
    <p>This ICS 201 has been <?= h($formStatus === 'approved' ? 'approved' : 'submitted') ?> and is locked. Use <strong>Preview PDF</strong> to read it, or <strong>Revise</strong> at the top to change it.</p>
  <?php elseif (!$canEdit): ?>
    <p>You do not have permission to edit this form. By default only the IC, DOCL or PSC can create or edit it.</p>
  <?php endif; ?>
  <?php
  // The edit form below carries the action buttons; when it is hidden, still offer Preview/Download PDF.
  if (!$canEdit && function_exists('render_ics_form_actions')) {
      render_ics_form_actions([
          'incident_id' => $incidentId,
          'op_id' => null,
          'form_code' => 'ICS201',
          'form_title' => 'ICS201',
          'permissions' => ['can_view' => $canView],   // view-only: no Save button here
          'is_final' => $isLockedFinal,
          'blank_download_url' => file_exists(__DIR__ . '/blank.php') ? 'blank.php' : null,
          'show_submit' => false,
      ]);
  }
  ?>

  <?php if ($canEdit && $formStatus !== 'submitted'): ?>
  <form method="post" enctype="multipart/form-data">
    <?= csrf_field() ?>
    <input type="hidden" name="form_action" value="save">

    <div class="grid">
      <div>
        <label>Date / Time Prepared</label>
        <div class="ics201-dt-wrap" data-ics201-datetime>
          <input class="ics201-dt-display" type="text" inputmode="numeric" pattern="[0-9]{4}-[0-9]{2}-[0-9]{2}[ T][0-9]{2}:[0-9]{2}" placeholder="YYYY-MM-DD HH:mm" name="datetime_prepared" value="<?= e(datetime_local_value(field_value($record ?? [], 'datetime_prepared'))) ?>">
          <button type="button" class="btn btn-secondary ics201-dt-button">Pick</button>
        </div>
      </div>
      <div>
        <label>Prepared By Name</label>
        <input type="text" name="prepared_by_name" value="<?= e(field_value($record ?? [], 'prepared_by_name') ?: (string)($preparer['name'] ?? '')) ?>">
      </div>
      <div>
        <label>Prepared By Position / Title</label>
        <input type="text" name="prepared_by_position" value="<?= e(field_value($record ?? [], 'prepared_by_position') ?: (string)($preparer['position_title'] ?? '')) ?>">
      </div>
    </div>

    <label>Situation Summary</label>
    <textarea name="situation_summary" rows="6"><?= e(field_value($record ?? [], 'situation_summary')) ?></textarea>

    <label>Health and Safety Briefing</label>
    <textarea name="safety_briefing" rows="4"><?= e(field_value($record ?? [], 'safety_briefing')) ?></textarea>

    <div class="grid">
      <div>
        <label>Current Objectives</label>
        <textarea name="current_objectives" rows="5"><?= e(field_value($record ?? [], 'current_objectives')) ?></textarea>
      </div>
      <div>
        <label>Planned Objectives</label>
        <textarea name="planned_objectives" rows="5"><?= e(field_value($record ?? [], 'planned_objectives')) ?></textarea>
      </div>
    </div>

    <label>Current Actions, Strategies and Tactics</label>
    <table class="repeatable-table" id="current-actions-table">
      <thead>
        <tr>
          <th style="width:180px;">Time</th>
          <th>Actions</th>
          <th style="width:90px;">Remove</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($currentActions as $row): ?>
        <tr>
          <td><input type="text" name="current_action_time[]" value="<?= e((string)($row['action_time'] ?? '')) ?>" placeholder="0800 or 0800-1200"></td>
          <td><textarea name="current_action_text[]" rows="2"><?= e((string)($row['action_text'] ?? '')) ?></textarea></td>
          <td><button type="button" class="btn btn-secondary js-remove-row">Remove</button></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <p><button type="button" class="btn btn-secondary js-add-row" data-target="current-actions-table" data-template="current-action-row-template">Add Current Action Row</button></p>

    <label>Planned Actions, Strategies and Tactics</label>
    <table class="repeatable-table" id="planned-actions-table">
      <thead>
        <tr>
          <th style="width:180px;">Time</th>
          <th>Actions</th>
          <th style="width:90px;">Remove</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($plannedActions as $row): ?>
        <tr>
          <td><input type="text" name="planned_action_time[]" value="<?= e((string)($row['action_time'] ?? '')) ?>" placeholder="1200 or TBD"></td>
          <td><textarea name="planned_action_text[]" rows="2"><?= e((string)($row['action_text'] ?? '')) ?></textarea></td>
          <td><button type="button" class="btn btn-secondary js-remove-row">Remove</button></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <p><button type="button" class="btn btn-secondary js-add-row" data-target="planned-actions-table" data-template="planned-action-row-template">Add Planned Action Row</button></p>

    <label>Current Organization</label>
    <textarea name="current_organization" rows="5"><?= e(field_value($record ?? [], 'current_organization')) ?></textarea>

    <label>Resource Summary</label>
    <table class="repeatable-table" id="resources-table">
      <thead>
        <tr>
          <th style="width:120px;">Resources Ordered</th>
          <th style="width:180px;">Resource Identification</th>
          <th style="width:90px;">ETA</th>
          <th style="width:90px;">On Scene</th>
          <th>Location / Assignment</th>
          <th style="width:90px;">Remove</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($resources as $row): ?>
        <tr>
          <td><input type="text" name="resources_ordered[]" value="<?= e((string)($row['resources_ordered'] ?? '')) ?>"></td>
          <td><input type="text" name="resource_identification[]" value="<?= e((string)($row['resource_identification'] ?? '')) ?>"></td>
          <td><input type="text" name="resource_eta[]" value="<?= e((string)($row['eta'] ?? '')) ?>"></td>
          <td><input type="text" name="resource_on_scene[]" value="<?= e((string)($row['on_scene'] ?? '')) ?>"></td>
          <td><input type="text" name="resource_location_assignment[]" value="<?= e((string)($row['location_assignment'] ?? '')) ?>"></td>
          <td><button type="button" class="btn btn-secondary js-remove-row">Remove</button></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <p><button type="button" class="btn btn-secondary js-add-row" data-target="resources-table" data-template="resource-row-template">Add Resource Row</button></p>
  <?php
if (function_exists('render_ics_form_actions')) {
    $faPerms = $permissions ?? $formPermissions ?? ['can_view'=>true,'can_create'=>true,'can_edit'=>true,'can_submit_final'=>true];
    $faIncident = $incidentId ?? ($_GET['incident_id'] ?? ($_POST['incident_id'] ?? ''));
    $faOp = $operationalPeriodId ?? $opId ?? ($_GET['op_id'] ?? ($_GET['operational_period_id'] ?? ''));
    render_ics_form_actions([
        'incident_id' => $faIncident ?? null,
        'op_id' => $faOp ?? null,
        'form_code' => 'ICS201',
        'form_title' => 'ICS201',
        'permissions' => $faPerms,
        'is_final' => !empty($record['is_final']) || !empty($formRecord['is_final']),        'blank_download_url' => file_exists(__DIR__ . '/blank.php') ? 'blank.php' : null,
        'show_submit' => false,
    ]);
}
?>
</form>

  <template id="current-action-row-template">
    <tr>
      <td><input type="text" name="current_action_time[]" placeholder="0800 or 0800-1200"></td>
      <td><textarea name="current_action_text[]" rows="2"></textarea></td>
      <td><button type="button" class="btn btn-secondary js-remove-row">Remove</button></td>
    </tr>
  </template>

  <template id="planned-action-row-template">
    <tr>
      <td><input type="text" name="planned_action_time[]" placeholder="1200 or TBD"></td>
      <td><textarea name="planned_action_text[]" rows="2"></textarea></td>
      <td><button type="button" class="btn btn-secondary js-remove-row">Remove</button></td>
    </tr>
  </template>

  <template id="resource-row-template">
    <tr>
      <td><input type="text" name="resources_ordered[]"></td>
      <td><input type="text" name="resource_identification[]"></td>
      <td><input type="text" name="resource_eta[]"></td>
      <td><input type="text" name="resource_on_scene[]"></td>
      <td><input type="text" name="resource_location_assignment[]"></td>
      <td><button type="button" class="btn btn-secondary js-remove-row">Remove</button></td>
    </tr>
  </template>

  <script>
  (function () {
    function bindRemoveButtons(scope) {
      scope.querySelectorAll('.js-remove-row').forEach(function (button) {
        if (button.dataset.bound === '1') return;
        button.dataset.bound = '1';
        button.addEventListener('click', function () {
          var tr = button.closest('tr');
          var tbody = tr ? tr.parentElement : null;
          if (!tr || !tbody) return;
          if (tbody.querySelectorAll('tr').length === 1) {
            tr.querySelectorAll('input, textarea').forEach(function (field) { field.value = ''; });
            return;
          }
          tr.remove();
        });
      });
    }

    document.querySelectorAll('.js-add-row').forEach(function (button) {
      button.addEventListener('click', function () {
        var table = document.getElementById(button.dataset.target);
        var template = document.getElementById(button.dataset.template);
        if (!table || !template) return;
        table.querySelector('tbody').insertAdjacentHTML('beforeend', template.innerHTML.trim());
        bindRemoveButtons(table);
      });
    });


    function pad2(n){ return String(n).padStart(2,'0'); }
    function parseDateTime(v){
      var m = String(v || '').match(/^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})$/);
      if(!m) return null;
      return {y:+m[1], mo:+m[2]-1, d:+m[3], h:+m[4], mi:+m[5]};
    }
    function initCustomDateTimePicker(wrap){
      var input = wrap.querySelector('.ics201-dt-display');
      var button = wrap.querySelector('.ics201-dt-button');
      if(!input || !button || wrap.dataset.pickerBound === '1') return;
      wrap.dataset.pickerBound = '1';
      var picked = parseDateTime(input.value);
      var now = new Date();
      var state = picked || {y:now.getFullYear(), mo:now.getMonth(), d:now.getDate(), h:now.getHours(), mi:Math.round(now.getMinutes()/5)*5};
      if(state.mi >= 60){ state.mi = 55; }
      var pop = null;
      function close(){ if(pop){ pop.remove(); pop=null; } }
      function setValue(){
        input.value = state.y+'-'+pad2(state.mo+1)+'-'+pad2(state.d)+' '+pad2(state.h)+':'+pad2(state.mi);
        close();
      }
      function draw(){
        close();
        pop = document.createElement('div');
        pop.className = 'ics201-dt-popover';
        pop.addEventListener('click', function(ev){ ev.stopPropagation(); });
        var monthName = new Date(state.y,state.mo,1).toLocaleString('en-CA',{month:'long',year:'numeric'});
        pop.innerHTML = '<div class="ics201-dt-head"><button type="button" data-prev>&lt;</button><div class="ics201-dt-month"></div><button type="button" data-next>&gt;</button></div><div class="ics201-dt-grid"></div><div class="ics201-dt-time"><label>Time</label><select data-hour></select><span>:</span><select data-minute></select></div><div class="ics201-dt-actions"><button type="button" data-clear>Clear</button><button type="button" data-apply>Apply</button></div>';
        pop.querySelector('.ics201-dt-month').textContent = monthName;
        var grid = pop.querySelector('.ics201-dt-grid');
        ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'].forEach(function(d){ var e=document.createElement('div'); e.className='ics201-dt-dow'; e.textContent=d; grid.appendChild(e); });
        for(var b=0;b<new Date(state.y,state.mo,1).getDay();b++){ var blank=document.createElement('div'); blank.className='ics201-dt-day blank'; grid.appendChild(blank); }
        var days = new Date(state.y,state.mo+1,0).getDate();
        for(var day=1; day<=days; day++){
          var cell=document.createElement('button'); cell.type='button'; cell.className='ics201-dt-day'+(day===state.d?' selected':''); cell.textContent=day;
          cell.addEventListener('click',(function(dd){return function(){state.d=dd; draw();};})(day));
          grid.appendChild(cell);
        }
        var hs=pop.querySelector('[data-hour]'), ms=pop.querySelector('[data-minute]');
        for(var h=0;h<24;h++){ hs.add(new Option(pad2(h),pad2(h),h===state.h,h===state.h)); }
        for(var mi=0;mi<60;mi+=5){ ms.add(new Option(pad2(mi),pad2(mi),mi===state.mi,mi===state.mi)); }
        hs.addEventListener('change',function(){state.h=+this.value;});
        ms.addEventListener('change',function(){state.mi=+this.value;});
        pop.querySelector('[data-prev]').onclick=function(){state.mo--; if(state.mo<0){state.mo=11;state.y--;} state.d=Math.min(state.d,new Date(state.y,state.mo+1,0).getDate()); draw();};
        pop.querySelector('[data-next]').onclick=function(){state.mo++; if(state.mo>11){state.mo=0;state.y++;} state.d=Math.min(state.d,new Date(state.y,state.mo+1,0).getDate()); draw();};
        pop.querySelector('[data-clear]').onclick=function(){input.value=''; close();};
        pop.querySelector('[data-apply]').onclick=setValue;
        wrap.appendChild(pop);
      }
      button.addEventListener('click',function(ev){ ev.stopPropagation(); var p=parseDateTime(input.value); if(p) state=p; pop ? close() : draw(); });
      document.addEventListener('click',function(ev){ if(pop && !wrap.contains(ev.target)) close(); });
    }
    document.querySelectorAll('[data-ics201-datetime]').forEach(initCustomDateTimePicker);

    bindRemoveButtons(document);
  })();
  </script>
  <?php endif; ?>
</div>

<div class="card">
  <h2>Attachments</h2>
  <?php if ($form201Id <= 0): ?>
    <p>Save the ICS 201 draft before adding attachments.</p>
  <?php else: ?>
    <?php if ($canEdit && $formStatus !== 'submitted'): ?>
      <form method="post" action="<?= e(app_url('/forms/ics201/attachment_upload.php')) ?>" enctype="multipart/form-data">
        <?= csrf_field() ?>
        <input type="file" name="attachments[]" multiple accept=".bmp,.jpg,.jpeg,.png,.pdf,.txt,image/bmp,image/jpeg,image/png,application/pdf,text/plain" required>
        <p class="small">Accepted: BMP, JPG, JPEG, PNG, PDF, and TXT. Maximum file size per upload: 15 MB.</p>
        <button type="submit">Upload Attachment(s)</button>
      </form>
    <?php else: ?>
      <p class="small">Attachments accepted here: BMP, JPG, JPEG, PNG, PDF, and TXT.</p>
    <?php endif; ?>

    <?php if (!$attachments): ?>
      <p style="margin-top:16px;">No attachments uploaded yet.</p>
    <?php else: ?>
    <table style="margin-top:16px;">
      <tr>
        <th>File</th>
        <th>Type</th>
        <th>Size</th>
        <th>Uploaded</th>
        <th>Open</th>
        <?php if ($canEdit && $formStatus !== 'submitted'): ?><th>Action</th><?php endif; ?>
      </tr>
      <?php foreach ($attachments as $attachment): ?>
        <tr>
          <td><?= e($attachment['original_filename']) ?></td>
          <td><?= e(extension_to_badge_label((string)$attachment['file_ext'])) ?></td>
          <td><?= e(format_bytes_human((int)$attachment['file_size_bytes'])) ?></td>
          <td><?= e((string)$attachment['created_at']) ?></td>
          <td><a href="<?= e(ics201_attachment_url((int)$attachment['id'])) ?>" target="_blank">Open</a></td>
          <?php if ($canEdit && $formStatus !== 'submitted'): ?>
          <td>
            <form method="post" action="<?= e(app_url('/forms/ics201/attachment_delete.php')) ?>" onsubmit="return confirm('Remove this attachment?');">
              <?= csrf_field() ?>
              <input type="hidden" name="attachment_id" value="<?= e((string)$attachment['id']) ?>">
              <button type="submit">Delete</button>
            </form>
          </td>
          <?php endif; ?>
        </tr>
      <?php endforeach; ?>
    </table>
    <?php endif; ?>
  <?php endif; ?>
</div>


<?php else: ?>
<div class="print-sheet">
  <div class="card print-card">
    <div class="print-form-header">
      <div>
        <h2>ICS201 - Incident Briefing</h2>
        <div><strong>Incident Name:</strong> <?= e((string)($incident['incident_name'] ?? $incident['name'] ?? '')) ?></div>
        <div><strong>Incident Number:</strong> <?= e((string)($incident['incident_number'] ?? '')) ?></div>
        <div><strong>Status:</strong> <?= e(ucfirst($formStatus)) ?></div>
      </div>
      <div>
        <div><strong>Date / Time Prepared:</strong> <?= e(field_value($record ?? [], 'datetime_prepared')) ?></div>
        <div><strong>Prepared By:</strong> <?= e(field_value($record ?? [], 'prepared_by_name')) ?><?php if (field_value($record ?? [], 'prepared_by_position') !== ''): ?> (<?= e(field_value($record ?? [], 'prepared_by_position')) ?>)<?php endif; ?></div>
      </div>
    </div>

    <div class="print-section">
      <h3>1. Situation Summary</h3>
      <div class="print-box"><?= nl2br(e(field_value($record ?? [], 'situation_summary'))) ?></div>
    </div>

    <div class="print-section">
      <h3>2. Health and Safety Briefing</h3>
      <div class="print-box"><?= nl2br(e(field_value($record ?? [], 'safety_briefing'))) ?></div>
    </div>

    <div class="print-section">
      <h3>3. Current Objectives</h3>
      <div class="print-box"><?= nl2br(e(field_value($record ?? [], 'current_objectives'))) ?></div>
    </div>

    <div class="print-section">
      <h3>4. Planned Objectives</h3>
      <div class="print-box"><?= nl2br(e(field_value($record ?? [], 'planned_objectives'))) ?></div>
    </div>

    <div class="print-section">
      <h3>5. Current Actions, Strategies and Tactics</h3>
      <table>
        <tr><th style="width:180px;">Time</th><th>Actions</th></tr>
        <?php foreach ($currentActions as $row): ?>
        <tr>
          <td><?= e((string)($row['action_time'] ?? '')) ?></td>
          <td><?= nl2br(e((string)($row['action_text'] ?? ''))) ?></td>
        </tr>
        <?php endforeach; ?>
      </table>
    </div>

    <div class="print-section">
      <h3>6. Planned Actions, Strategies and Tactics</h3>
      <table>
        <tr><th style="width:180px;">Time</th><th>Actions</th></tr>
        <?php foreach ($plannedActions as $row): ?>
        <tr>
          <td><?= e((string)($row['action_time'] ?? '')) ?></td>
          <td><?= nl2br(e((string)($row['action_text'] ?? ''))) ?></td>
        </tr>
        <?php endforeach; ?>
      </table>
    </div>

    <div class="print-section">
      <h3>7. Current Organization</h3>
      <div class="print-box"><?= nl2br(e(field_value($record ?? [], 'current_organization'))) ?></div>
    </div>

    <div class="print-section">
      <h3>8. Resource Summary</h3>
      <table>
        <tr>
          <th>Resources Ordered</th>
          <th>Resource Identification</th>
          <th>ETA</th>
          <th>On Scene</th>
          <th>Location / Assignment</th>
        </tr>
        <?php foreach ($resources as $row): ?>
        <tr>
          <td><?= e((string)($row['resources_ordered'] ?? '')) ?></td>
          <td><?= e((string)($row['resource_identification'] ?? '')) ?></td>
          <td><?= e((string)($row['eta'] ?? '')) ?></td>
          <td><?= e((string)($row['on_scene'] ?? '')) ?></td>
          <td><?= e((string)($row['location_assignment'] ?? '')) ?></td>
        </tr>
        <?php endforeach; ?>
      </table>
    </div>

    <?php if ($attachments): ?>
    <div class="print-section">
      <h3>9. Attachments</h3>
      <ul>
        <?php foreach ($attachments as $attachment): ?>
          <li><?= e($attachment['original_filename']) ?> (<?= e(extension_to_badge_label((string)$attachment['file_ext'])) ?>, <?= e(format_bytes_human((int)$attachment['file_size_bytes'])) ?>)</li>
        <?php endforeach; ?>
      </ul>
    </div>
    <?php endif; ?>
  </div>
</div>
<?php endif; ?>
<?php render_footer(); ?>
