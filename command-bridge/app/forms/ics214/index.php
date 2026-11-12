<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/security.php';
require_csrf_for_post();

require_once __DIR__ . '/../../includes/form_action_buttons.php'; ?>
<?php

require_once dirname(dirname(__DIR__)) . '/includes/layout.php';
require_once dirname(dirname(__DIR__)) . '/includes/ics214_activity.php';
require_login();

$pdo = db();
$incident = active_incident();
$incidentId = (int)($incident['id'] ?? 0);

if ($incidentId > 0) {
    require_active_operational_period($incidentId);
}
$user = current_user();
$userId = (int)($user['id'] ?? 0);
$permissions = $incidentId > 0 ? get_current_user_form_permissions('ICS214', $incidentId) : get_form_permission_defaults('ICS214');
$canView = !empty($permissions['can_view']);
$canEdit = !empty($permissions['can_edit']) || !empty($permissions['can_create']);
$formRecord = $incidentId > 0 ? get_form_record($incidentId, 'ICS214') : null;
$formRecordId = (int)($formRecord['id'] ?? 0);
$formStatus = (string)($formRecord['status'] ?? 'draft');
$preparer = $incidentId > 0 ? resolve_form_preparer($incidentId, 'ICS214') : ['name'=>'TBD','position_title'=>'DOCL'];
$signature = $formRecordId > 0 ? get_form_signature($formRecordId) : null;
$error = null;

function ics214_table_exists(PDO $pdo, string $table): bool { try { $s=$pdo->query("SHOW TABLES LIKE ".$pdo->quote($table)); return (bool)($s && $s->fetchColumn()); } catch (Throwable $e) { return false; } }
function ics214_cols(PDO $pdo, string $table): array { try { return array_column($pdo->query("SHOW COLUMNS FROM `$table`")->fetchAll(), 'Field'); } catch (Throwable $e) { return []; } }
function ics214_dt(?string $v): string { return $v ? date('Y-m-d H:i', strtotime($v)) : ''; }
function ics214_user_name(array $u): string { return trim((string)($u['real_name'] ?? $u['display_name'] ?? $u['username'] ?? '')); }

if ($incidentId > 0 && !$canView) {
    http_response_code(403);
    render_header('ICS 214 - Activity Log');
    echo '<div class="card"><p>Forbidden</p></div>';
    render_footer(); exit;
}
if ($incidentId <= 0) {
    render_header('ICS 214 - Activity Log');
    echo '<div class="card"><p>No active incident is available.</p></div>';
    render_footer(); exit;
}

$positionTitle = '';
try {
    $st=$pdo->prepare("SELECT r.role_name FROM incident_positions ip INNER JOIN roles r ON r.id=ip.role_id WHERE ip.incident_id=? AND ip.user_id=? AND ip.deleted_at IS NULL AND (ip.assigned_end IS NULL OR ip.assigned_end >= NOW()) ORDER BY ip.assigned_start DESC, ip.id DESC LIMIT 1");
    $st->execute([$incidentId,$userId]); $positionTitle=(string)($st->fetchColumn() ?: '');
} catch (Throwable $e) {}

$op = null;
try {
    $st=$pdo->prepare("SELECT * FROM operational_periods WHERE incident_id=? AND deleted_at IS NULL AND op_start <= NOW() AND op_end >= NOW() ORDER BY op_start DESC LIMIT 1");
    $st->execute([$incidentId]); $op=$st->fetch() ?: null;
    if (!$op) { $st=$pdo->prepare("SELECT * FROM operational_periods WHERE incident_id=? AND deleted_at IS NULL ORDER BY op_start DESC LIMIT 1"); $st->execute([$incidentId]); $op=$st->fetch() ?: null; }
} catch (Throwable $e) {}
$opText = $op ? trim((string)($op['period_name'] ?? '')) . ' (' . ics214_dt($op['op_start'] ?? '') . ' to ' . ics214_dt($op['op_end'] ?? '') . ')' : '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $canEdit) {
    try {
        $pdo->beginTransaction();
        if ($formRecordId <= 0) {
            $stmt = $pdo->prepare("INSERT INTO form_records (incident_id, operational_period_id, form_type, form_number, status, prepared_by_user_id) VALUES (?, ?, 'ICS214', '214', 'draft', ?)");
            $stmt->execute([$incidentId, (int)($op['id'] ?? 0) ?: null, $userId]);
            $formRecordId = (int)$pdo->lastInsertId();
        }
        $stmt=$pdo->prepare("INSERT INTO form_214_headers (form_record_id, operational_period_id, incident_name, unit_name, personnel_name, ICS_position_title, home_agency, date_time_prepared, prepared_by_name, prepared_by_position_title) VALUES (?, ?, ?, ?, ?, ?, ?, NULLIF(?,''), ?, ?) ON DUPLICATE KEY UPDATE operational_period_id=VALUES(operational_period_id), incident_name=VALUES(incident_name), unit_name=VALUES(unit_name), personnel_name=VALUES(personnel_name), ICS_position_title=VALUES(ICS_position_title), home_agency=VALUES(home_agency), date_time_prepared=VALUES(date_time_prepared), prepared_by_name=VALUES(prepared_by_name), prepared_by_position_title=VALUES(prepared_by_position_title)");
        $stmt->execute([$formRecordId, (int)($_POST['operational_period_id'] ?? 0) ?: null, trim($_POST['incident_name'] ?? ''), trim($_POST['unit_name'] ?? ''), trim($_POST['personnel_name'] ?? ''), trim($_POST['ICS_position_title'] ?? ''), trim($_POST['home_agency'] ?? ''), trim($_POST['date_time_prepared'] ?? ''), trim($_POST['prepared_by_name'] ?? ''), trim($_POST['prepared_by_position_title'] ?? '')]);
        if (ics214_table_exists($pdo,'form_214_activity_log')) {
            $pdo->prepare("DELETE FROM form_214_activity_log WHERE form_record_id=? AND source_type='manual'")->execute([$formRecordId]);
            $times=$_POST['log_time'] ?? []; $texts=$_POST['log_text'] ?? [];
            $ins=$pdo->prepare("INSERT INTO form_214_activity_log (form_record_id,row_order,activity_time,activity_text,source_type) VALUES (?,?,?,?, 'manual')");
            foreach ($texts as $i=>$txt) {
                $txt=trim((string)$txt); if ($txt==='') continue;
                $ins->execute([$formRecordId, $i+1, trim((string)($times[$i] ?? '')), $txt]);
            }
        }
        $pdo->commit(); header('Location: index.php?saved=1'); exit;
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); $error=$e->getMessage(); }
}

$data = [];
if ($formRecordId > 0) { $stmt=$pdo->prepare("SELECT * FROM form_214_headers WHERE form_record_id=?"); $stmt->execute([$formRecordId]); $data=$stmt->fetch() ?: []; }
$incidentName = (string)($data['incident_name'] ?? $incident['name'] ?? $incident['incident_name'] ?? '');
$personName = (string)($data['personnel_name'] ?? ics214_user_name($user));
$pos = (string)($data['ICS_position_title'] ?? $positionTitle);
$unit = (string)($data['unit_name'] ?? '');
$homeAgency = (string)($data['home_agency'] ?? '');
$preparedBy = (string)($data['prepared_by_name'] ?? ics214_user_name($user));
$preparedPos = (string)($data['prepared_by_position_title'] ?? $pos);
$preparedAt = isset($data['date_time_prepared']) ? ics214_dt($data['date_time_prepared']) : '';

$autoRows=[];
try {
    if (ics214_table_exists($pdo,'operational_period_meetings')) {
        $st=$pdo->prepare("SELECT m.scheduled_at, m.title, mt.name type_name FROM operational_period_meetings m LEFT JOIN meeting_types mt ON mt.id=m.meeting_type_id WHERE m.incident_id=? AND m.deleted_at IS NULL AND m.scheduled_at <= NOW() ORDER BY m.scheduled_at ASC");
        $st->execute([$incidentId]);
        foreach ($st->fetchAll() as $r) $autoRows[]=['time'=>ics214_dt($r['scheduled_at']),'text'=>'Meeting held: '.trim((string)($r['title'] ?: $r['type_name'])),'source'=>'Meeting'];
    }
} catch (Throwable $e) {}
foreach (ics214_audit_rows($pdo, $incidentId, $op) as $ar) { $autoRows[] = ['time'=>$ar['time'],'text'=>$ar['text'],'source'=>$ar['source'],'cat'=>$ar['cat']]; }
$manualRows=[];
if ($formRecordId && ics214_table_exists($pdo,'form_214_activity_log')) { $st=$pdo->prepare("SELECT * FROM form_214_activity_log WHERE form_record_id=? AND source_type='manual' ORDER BY row_order,id"); $st->execute([$formRecordId]); foreach($st->fetchAll() as $r) $manualRows[]=['time'=>ics214_dt($r['activity_time']),'text'=>$r['activity_text'],'source'=>'Manual']; }

$displayRows = [];
foreach ($autoRows as $r) {
    $displayRows[] = ['time'=>$r['time'], 'text'=>$r['text'], 'source'=>$r['source'], 'manual'=>false, 'cat'=>($r['cat'] ?? 'meeting')];
}
foreach ($manualRows as $r) {
    $displayRows[] = ['time'=>$r['time'], 'text'=>$r['text'], 'source'=>'Manual', 'manual'=>true];
}
usort($displayRows, function($a, $b) {
    $at = trim((string)($a['time'] ?? ''));
    $bt = trim((string)($b['time'] ?? ''));
    if ($at === '' && $bt === '') return 0;
    if ($at === '') return 1;
    if ($bt === '') return -1;
    $cmp = strcmp($at, $bt);
    if ($cmp !== 0) return $cmp;
    return strcmp((string)($a['source'] ?? ''), (string)($b['source'] ?? ''));
});

render_header('ICS 214 - Activity Log');
render_ics_form_status('ICS214', $formRecord, $preparer, $signature, ['error'=>$error, 'show_print'=>false, 'show_pdf'=>true, 'pdf_url'=>'/forms/ics214/pdf.php', 'note'=>'This form remains editable while it is in draft.']);
?>
<style>
.ics214-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px}.ics214-readonly{background:#f4f6f8}.ics214-log{width:100%;border-collapse:collapse}.ics214-log th,.ics214-log td{border:1px solid #bbb;padding:6px;vertical-align:top}.ics214-log input{width:100%;box-sizing:border-box}.ics214-log textarea{width:100%;min-height:42px;box-sizing:border-box}.ics214-actions{margin-top:12px}
.ics214-dt-wrap{display:flex;align-items:center;gap:8px;flex-wrap:nowrap}
.ics214-dt-display{min-width:180px}
.ics214-dt-button{white-space:nowrap}
.ics214-dt-popover{position:absolute;z-index:1000;background:#fff;border:1px solid #999;border-radius:4px;box-shadow:0 4px 14px rgba(0,0,0,.18);padding:10px;margin-top:4px;width:292px;color:#111}
.ics214-dt-head{display:flex;align-items:center;justify-content:space-between;margin-bottom:8px;gap:6px}
.ics214-dt-head button{padding:4px 8px}
.ics214-dt-month{font-weight:700;text-align:center;flex:1}
.ics214-dt-grid{display:grid;grid-template-columns:repeat(7,1fr);gap:3px}
.ics214-dt-dow{font-size:12px;text-align:center;font-weight:700;color:#555;padding:3px 0}
.ics214-dt-popover .ics214-dt-grid button.ics214-dt-day{appearance:none;-webkit-appearance:none;padding:6px 0 !important;border:1px solid #ddd !important;background:#fff !important;background-color:#fff !important;color:#111 !important;cursor:pointer;text-align:center;border-radius:3px;font:inherit;box-shadow:none !important;text-shadow:none !important}
.ics214-dt-popover .ics214-dt-grid button.ics214-dt-day:hover,.ics214-dt-popover .ics214-dt-grid button.ics214-dt-day.selected{background:#1f5f99 !important;background-color:#1f5f99 !important;color:#fff !important}
.ics214-dt-day.blank{visibility:hidden}
.ics214-dt-time{display:flex;align-items:center;gap:6px;margin-top:10px}
.ics214-dt-time select{width:auto}
.ics214-dt-actions{display:flex;justify-content:flex-end;gap:8px;margin-top:10px}
@media(max-width:800px){.ics214-grid{grid-template-columns:1fr}.ics214-dt-wrap{flex-wrap:wrap}.ics214-dt-popover{width:270px}}
</style>
<div class="card">
  <h2>ICS214 - Activity Log</h2>
  <?php if (isset($_GET['saved'])): ?><p class="notice">Saved.</p><?php endif; ?>
  <?php if ($error): ?><p class="error"><?= h($error) ?></p><?php endif; ?>
  <p><a href="help.php">Help / Instructions</a></p>
  <form method="post">
    <?= csrf_field() ?>
    <div class="ics214-grid">
      <label>1. Incident Name<input class="ics214-readonly" name="incident_name" value="<?= h($incidentName) ?>" readonly></label>
      <label>2. Operational Period<input class="ics214-readonly" value="<?= h($opText) ?>" readonly><input type="hidden" name="operational_period_id" value="<?= h((string)($op['id'] ?? $data['operational_period_id'] ?? '')) ?>"></label>
      <label>3. Name<input name="personnel_name" value="<?= h($personName) ?>"></label>
      <label>4. ICS Position<input name="ICS_position_title" value="<?= h($pos) ?>"></label>
      <label>5. Home Agency<input name="home_agency" value="<?= h($homeAgency) ?>"></label>
      <label>6. Unit Name<input name="unit_name" value="<?= h($unit) ?>"></label>
      <label>7. Date/Time Prepared<span class="ics214-dt-wrap" data-ics214-datetime><input class="ics214-dt-display" name="date_time_prepared" placeholder="YYYY-MM-DD HH:mm" value="<?= h($preparedAt) ?>"><button type="button" class="ics214-dt-button">Pick</button></span></label>
      <label>8. Prepared by<input name="prepared_by_name" value="<?= h($preparedBy) ?>"></label>
      <label>Prepared by Position/Title<input name="prepared_by_position_title" value="<?= h($preparedPos) ?>"></label>
    </div>
    <h3>Activity Log</h3>
    <p class="muted">Recorded automatically: past meetings, form saves and submissions, new logins, PIN/password resets and changes (the new PIN or password is never recorded), account changes, sign-ins and settings changes. Manual rows are saved only when event text is entered.</p>
    <p class="no-print" id="ics214-filter"><strong>Show:</strong>
      <label style="display:inline;margin-left:8px"><input type="checkbox" data-cat-filter="form" checked> Forms</label>
      <label style="display:inline;margin-left:8px"><input type="checkbox" data-cat-filter="account" checked> Accounts</label>
      <label style="display:inline;margin-left:8px"><input type="checkbox" data-cat-filter="signin" checked> Sign-ins</label>
      <label style="display:inline;margin-left:8px"><input type="checkbox" data-cat-filter="system" checked> System</label>
      <label style="display:inline;margin-left:8px"><input type="checkbox" data-cat-filter="meeting" checked> Meetings</label>
    </p>
    <table class="ics214-log">
      <thead><tr><th style="width:190px">Date/Time</th><th>Notable Activities</th><th style="width:90px">Source</th></tr></thead>
      <tbody>
        <?php foreach ($displayRows as $r): ?>
          <?php if (!empty($r['manual'])): ?>
            <tr class="manual-row"><td><span class="ics214-dt-wrap" data-ics214-datetime><input class="ics214-dt-display" name="log_time[]" placeholder="YYYY-MM-DD HH:mm" value="<?= h($r['time']) ?>"><button type="button" class="ics214-dt-button">Pick</button></span></td><td><textarea name="log_text[]"><?= h($r['text']) ?></textarea></td><td>Manual</td></tr>
          <?php else: ?>
            <tr data-cat="<?= h((string)($r['cat'] ?? 'meeting')) ?>"><td><?= h($r['time']) ?></td><td><?= h($r['text']) ?></td><td><?= h($r['source']) ?></td></tr>
          <?php endif; ?>
        <?php endforeach; ?>
        <tr class="manual-row"><td><span class="ics214-dt-wrap" data-ics214-datetime><input class="ics214-dt-display" name="log_time[]" placeholder="YYYY-MM-DD HH:mm"><button type="button" class="ics214-dt-button">Pick</button></span></td><td><textarea name="log_text[]"></textarea></td><td>Manual</td></tr>
      </tbody>
    </table>
    <?php
if (function_exists('render_ics_form_actions')) {
    $faPerms = $permissions ?? $formPermissions ?? ['can_view'=>true,'can_create'=>true,'can_edit'=>true,'can_submit_final'=>true];
    $faIncident = $incidentId ?? ($_GET['incident_id'] ?? ($_POST['incident_id'] ?? ''));
    $faOp = $operationalPeriodId ?? $opId ?? ($_GET['op_id'] ?? ($_GET['operational_period_id'] ?? ''));
    render_ics_form_actions([
        'incident_id' => $faIncident ?? null,
        'op_id' => $faOp ?? null,
        'form_code' => 'ICS214',
        'form_title' => 'ICS214',
        'permissions' => $faPerms,
        'is_final' => !empty($record['is_final']) || !empty($formRecord['is_final']),        'blank_download_url' => file_exists(__DIR__ . '/blank.php') ? 'blank.php' : null,
    ]);
}
?>
</form>
</div>
<script>
(function(){
 const tbody=document.querySelector('.ics214-log tbody');

 function pad2(n){ return String(n).padStart(2,'0'); }
 function parseDateTime(v){
   var m = String(v || '').match(/^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})$/);
   if(!m) return null;
   return {y:+m[1], mo:+m[2]-1, d:+m[3], h:+m[4], mi:+m[5]};
 }
 function initCustomDateTimePicker(wrap){
   var input = wrap.querySelector('.ics214-dt-display');
   var button = wrap.querySelector('.ics214-dt-button');
   if(!input || !button || wrap.dataset.pickerBound === '1') return;
   wrap.dataset.pickerBound = '1';
   var picked = parseDateTime(input.value);
   var now = new Date();
   var state = picked || {y:now.getFullYear(), mo:now.getMonth(), d:now.getDate(), h:now.getHours(), mi:Math.round(now.getMinutes()/5)*5};
   if(state.mi >= 60){ state.mi = 55; }
   var pop = null;
   function close(){ if(pop){ pop.remove(); pop=null; } }
   function setValue(){ input.value = state.y+'-'+pad2(state.mo+1)+'-'+pad2(state.d)+' '+pad2(state.h)+':'+pad2(state.mi); close(); input.dispatchEvent(new Event('input',{bubbles:true})); }
   function draw(){
     close();
     pop = document.createElement('div');
     pop.className = 'ics214-dt-popover';
     pop.addEventListener('click', function(ev){ ev.stopPropagation(); });
     pop.innerHTML = '<div class="ics214-dt-head"><button type="button" data-prev>&lt;</button><div class="ics214-dt-month"></div><button type="button" data-next>&gt;</button></div><div class="ics214-dt-grid"></div><div class="ics214-dt-time"><label>Time</label><select data-hour></select><span>:</span><select data-minute></select></div><div class="ics214-dt-actions"><button type="button" data-clear>Clear</button><button type="button" data-apply>Apply</button></div>';
     pop.querySelector('.ics214-dt-month').textContent = new Date(state.y,state.mo,1).toLocaleString('en-CA',{month:'long',year:'numeric'});
     var grid = pop.querySelector('.ics214-dt-grid');
     ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'].forEach(function(d){ var e=document.createElement('div'); e.className='ics214-dt-dow'; e.textContent=d; grid.appendChild(e); });
     for(var b=0;b<new Date(state.y,state.mo,1).getDay();b++){ var blank=document.createElement('div'); blank.className='ics214-dt-day blank'; grid.appendChild(blank); }
     var days = new Date(state.y,state.mo+1,0).getDate();
     for(var day=1; day<=days; day++){ var cell=document.createElement('button'); cell.type='button'; cell.className='ics214-dt-day'+(day===state.d?' selected':''); cell.textContent=day; cell.addEventListener('click',(function(dd){return function(){state.d=dd; draw();};})(day)); grid.appendChild(cell); }
     var hs=pop.querySelector('[data-hour]'), ms=pop.querySelector('[data-minute]');
     for(var h=0;h<24;h++){ hs.add(new Option(pad2(h),pad2(h),h===state.h,h===state.h)); }
     for(var mi=0;mi<60;mi+=5){ ms.add(new Option(pad2(mi),pad2(mi),mi===state.mi,mi===state.mi)); }
     hs.addEventListener('change',function(){state.h=+this.value;});
     ms.addEventListener('change',function(){state.mi=+this.value;});
     pop.querySelector('[data-prev]').onclick=function(){state.mo--; if(state.mo<0){state.mo=11;state.y--;} state.d=Math.min(state.d,new Date(state.y,state.mo+1,0).getDate()); draw();};
     pop.querySelector('[data-next]').onclick=function(){state.mo++; if(state.mo>11){state.mo=0;state.y++;} state.d=Math.min(state.d,new Date(state.y,state.mo+1,0).getDate()); draw();};
     pop.querySelector('[data-clear]').onclick=function(){input.value=''; close(); input.dispatchEvent(new Event('input',{bubbles:true}));};
     pop.querySelector('[data-apply]').onclick=setValue;
     wrap.appendChild(pop);
   }
   button.addEventListener('click',function(ev){ ev.stopPropagation(); var p=parseDateTime(input.value); if(p) state=p; pop ? close() : draw(); });
   document.addEventListener('click',function(ev){ if(pop && !wrap.contains(ev.target)) close(); });
 }

 function bindPickers(scope){ (scope || document).querySelectorAll('[data-ics214-datetime]').forEach(initCustomDateTimePicker); }
 function ensureBlank(){
   const rows=[...tbody.querySelectorAll('tr.manual-row')];
   const last=rows[rows.length-1]; if(!last) return;
   const txt=last.querySelector('textarea');
   if(txt && txt.value.trim()!==''){
     const tr=last.cloneNode(true);
     tr.querySelectorAll('input,textarea').forEach(e=>e.value='');
     tr.querySelectorAll('[data-picker-bound]').forEach(e=>delete e.dataset.pickerBound);
     tr.querySelectorAll('.ics214-dt-popover').forEach(e=>e.remove());
     tbody.appendChild(tr);
     bindPickers(tr);
   }
 }
 bindPickers(document);
 tbody.addEventListener('input', function(e){ if(e.target && e.target.name==='log_text[]') ensureBlank(); });
})();
</script>
<script>
document.querySelectorAll('[data-cat-filter]').forEach(function(box){
  box.addEventListener('change', function(){
    document.querySelectorAll('.ics214-log tr[data-cat="'+box.dataset.catFilter+'"]').forEach(function(tr){ tr.style.display = box.checked ? '' : 'none'; });
  });
});
</script>
<?php render_footer(); ?>
