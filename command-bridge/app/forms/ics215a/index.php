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
$user = current_user();
$userId = (int)($user['id'] ?? 0);
$permissions = $incidentId > 0 ? get_current_user_form_permissions('ICS215A', $incidentId) : get_form_permission_defaults('ICS215A');
$canView = !empty($permissions['can_view']);
$canEdit = !empty($permissions['can_edit']) || !empty($permissions['can_create']);
$canSign = current_user_has_any_role_codes(['IC','SOFR','OSC'], $incidentId);
$formRecord = $incidentId > 0 ? get_form_record($incidentId, 'ICS215A') : null;
$formRecordId = (int)($formRecord['id'] ?? 0);
$formStatus = (string)($formRecord['status'] ?? 'draft');
$preparer = $incidentId > 0 ? resolve_form_preparer($incidentId, 'ICS215A') : ['name'=>'TBD','position_title'=>'SOFR'];
$signature = $formRecordId > 0 ? get_form_signature($formRecordId) : null;
$error = null;

function i215a_dt(?string $v): string { return $v ? date('Y-m-d H:i', strtotime($v)) : ''; }
function i215a_name(array $u): string { return trim((string)($u['real_name'] ?? $u['display_name'] ?? $u['username'] ?? '')); }
function i215a_table_exists(PDO $pdo, string $table): bool { try { $s=$pdo->query("SHOW TABLES LIKE ".$pdo->quote($table)); return (bool)($s && $s->fetchColumn()); } catch (Throwable $e) { return false; } }

if ($incidentId > 0 && !$canView) { http_response_code(403); render_header('ICS 215A - Safety Analysis'); echo '<div class="card"><p>Forbidden</p></div>'; render_footer(); exit; }
if ($incidentId <= 0) { render_header('ICS 215A - Safety Analysis'); echo '<div class="card"><p>No active incident is available.</p></div>'; render_footer(); exit; }

$op = null;
try {
  $st=$pdo->prepare("SELECT * FROM operational_periods WHERE incident_id=? AND deleted_at IS NULL AND op_start <= NOW() AND op_end >= NOW() ORDER BY op_start DESC LIMIT 1");
  $st->execute([$incidentId]); $op=$st->fetch() ?: null;
  if (!$op) { $st=$pdo->prepare("SELECT * FROM operational_periods WHERE incident_id=? AND deleted_at IS NULL ORDER BY op_start DESC LIMIT 1"); $st->execute([$incidentId]); $op=$st->fetch() ?: null; }
} catch(Throwable $e){}
$opText = $op ? i215a_dt($op['op_start'] ?? '') . ' to ' . i215a_dt($op['op_end'] ?? '') : '';

$sofrName=''; $oscName='';
$h=[];
try {
  $st=$pdo->prepare("SELECT r.role_code, COALESCE(u.real_name,u.display_name,u.username) nm FROM incident_positions ip INNER JOIN roles r ON r.id=ip.role_id INNER JOIN users u ON u.id=ip.user_id WHERE ip.incident_id=? AND ip.deleted_at IS NULL AND (ip.assigned_end IS NULL OR ip.assigned_end >= NOW()) AND r.role_code IN ('SOFR','OSC') ORDER BY ip.assigned_start DESC");
  $st->execute([$incidentId]);
  foreach($st->fetchAll() as $r){ if($r['role_code']==='SOFR' && $sofrName==='') $sofrName=$r['nm']; if($r['role_code']==='OSC' && $oscName==='') $oscName=$r['nm']; }
} catch(Throwable $e){}

if ($formRecordId>0 && i215a_table_exists($pdo,'form_215a_headers')) { $st=$pdo->prepare("SELECT * FROM form_215a_headers WHERE form_record_id=?"); $st->execute([$formRecordId]); $h=$st->fetch() ?: []; }
if ($_SERVER['REQUEST_METHOD']==='POST' && $canEdit) {
  try {
    $pdo->beginTransaction();
    if ($formRecordId <= 0) {
      $stmt=$pdo->prepare("INSERT INTO form_records (incident_id, operational_period_id, form_type, form_number, status, prepared_by_user_id) VALUES (?, ?, 'ICS215A', '215A', 'draft', ?)");
      $stmt->execute([$incidentId,(int)($_POST['operational_period_id'] ?? 0) ?: null,$userId]);
      $formRecordId=(int)$pdo->lastInsertId();
    }
    $stmt=$pdo->prepare("INSERT INTO form_215a_headers (form_record_id,operational_period_id,incident_name,date_time_prepared,sofr_name,sofr_signature,sofr_signed_at,osc_name,osc_signature,osc_signed_at) VALUES (?,?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE operational_period_id=VALUES(operational_period_id),incident_name=VALUES(incident_name),date_time_prepared=VALUES(date_time_prepared),sofr_name=VALUES(sofr_name),sofr_signature=VALUES(sofr_signature),sofr_signed_at=VALUES(sofr_signed_at),osc_name=VALUES(osc_name),osc_signature=VALUES(osc_signature),osc_signed_at=VALUES(osc_signed_at)");
    $stmt->execute([$formRecordId,(int)($_POST['operational_period_id'] ?? 0) ?: null,trim($_POST['incident_name'] ?? ''),trim($_POST['date_time_prepared'] ?? '') ?: null,($canSign ? trim($_POST['sofr_name'] ?? '') : (string)($h['sofr_name'] ?? $sofrName)), '', ($canSign ? (trim($_POST['sofr_signed_at'] ?? '') ?: null) : ($h['sofr_signed_at'] ?? null)), ($canSign ? trim($_POST['osc_name'] ?? '') : (string)($h['osc_name'] ?? $oscName)), '', ($canSign ? (trim($_POST['osc_signed_at'] ?? '') ?: null) : ($h['osc_signed_at'] ?? null))]);
    $pdo->prepare("DELETE FROM form_215a_rows WHERE form_record_id=?")->execute([$formRecordId]);
    $areas=$_POST['area'] ?? []; $hazards=$_POST['hazards'] ?? []; $mit=$_POST['mitigations'] ?? [];
    $ins=$pdo->prepare("INSERT INTO form_215a_rows (form_record_id,row_order,incident_area,hazards_risks,mitigations) VALUES (?,?,?,?,?)");
    foreach($areas as $i=>$area){ $area=trim((string)$area); $hz=trim((string)($hazards[$i]??'')); $mt=trim((string)($mit[$i]??'')); if($area==='' && $hz==='' && $mt==='') continue; $ins->execute([$formRecordId,$i+1,$area,$hz,$mt]); }
    $pdo->commit(); header('Location: index.php?saved=1'); exit;
  } catch(Throwable $e){ if($pdo->inTransaction()) $pdo->rollBack(); $error=$e->getMessage(); }
}

$incidentName=(string)($h['incident_name'] ?? $incident['name'] ?? $incident['incident_name'] ?? '');
$rows=[];
if ($formRecordId>0 && i215a_table_exists($pdo,'form_215a_rows')) { $st=$pdo->prepare("SELECT * FROM form_215a_rows WHERE form_record_id=? ORDER BY row_order,id"); $st->execute([$formRecordId]); $rows=$st->fetchAll(); }

render_header('ICS 215A - Safety Analysis');
render_ics_form_status('ICS215A', $formRecord, $preparer, $signature, ['error'=>$error, 'show_print'=>false, 'show_pdf'=>true, 'pdf_url'=>'/forms/ics215a/pdf.php', 'note'=>'This form remains editable while it is in draft.']);
?>
<style>
.ics215a-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px}.ics215a-readonly{background:#f4f6f8}.ics215a-table{width:100%;border-collapse:collapse}.ics215a-table th,.ics215a-table td{border:1px solid #bbb;padding:6px;vertical-align:top}.ics215a-table input,.ics215a-table textarea{width:100%;box-sizing:border-box}.ics215a-table textarea{min-height:70px}.ics215a-add-row{margin-top:8px}.ics215a-sign{display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-top:12px}.ics215a-dt-wrap{display:flex;align-items:center;gap:8px;flex-wrap:nowrap}.ics215a-dt-display{min-width:180px}.ics215a-dt-button{white-space:nowrap}
.ics215a-dt-popover{position:absolute;z-index:1000;background:#fff;border:1px solid #999;border-radius:4px;box-shadow:0 4px 14px rgba(0,0,0,.18);padding:10px;margin-top:4px;width:292px;color:#111}.ics215a-dt-head{display:flex;align-items:center;justify-content:space-between;margin-bottom:8px;gap:6px}.ics215a-dt-month{font-weight:700;text-align:center;flex:1}.ics215a-dt-grid{display:grid;grid-template-columns:repeat(7,1fr);gap:3px}.ics215a-dt-dow{font-size:12px;text-align:center;font-weight:700;color:#555;padding:3px 0}.ics215a-dt-popover .ics215a-dt-grid button.ics215a-dt-day{appearance:none;-webkit-appearance:none;padding:6px 0!important;border:1px solid #ddd!important;background:#fff!important;color:#111!important;cursor:pointer;text-align:center;border-radius:3px;font:inherit;box-shadow:none!important;text-shadow:none!important}.ics215a-dt-popover .ics215a-dt-grid button.ics215a-dt-day:hover,.ics215a-dt-popover .ics215a-dt-grid button.ics215a-dt-day.selected{background:#1f5f99!important;color:#fff!important}.ics215a-dt-day.blank{visibility:hidden}.ics215a-dt-time{display:flex;align-items:center;gap:6px;margin-top:10px}.ics215a-dt-time select{width:auto}.ics215a-dt-actions{display:flex;justify-content:flex-end;gap:8px;margin-top:10px}@media(max-width:800px){.ics215a-grid,.ics215a-sign{grid-template-columns:1fr}.ics215a-dt-wrap{flex-wrap:wrap}}
</style>
<div class="card">
<h2>ICS215A - Incident Action Safety Plan Analysis</h2>
<?php if(isset($_GET['saved'])): ?><p class="notice">Saved.</p><?php endif; ?><?php if($error): ?><p class="error"><?= h($error) ?></p><?php endif; ?>
<p><a href="help.php">Help / Instructions</a></p>
<form method="post">
    <?= csrf_field() ?>
<div class="ics215a-grid">
<label>1. Incident Name<input class="ics215a-readonly" name="incident_name" value="<?= h($incidentName) ?>" readonly></label>
<label>2. Date/Time Prepared<span class="ics215a-dt-wrap" data-ics215a-datetime><input class="ics215a-dt-display" name="date_time_prepared" placeholder="YYYY-MM-DD HH:mm" value="<?= h(i215a_dt($h['date_time_prepared'] ?? '')) ?>"><button type="button" class="ics215a-dt-button">Pick</button></span></label>
<label>3. Operational Period<input class="ics215a-readonly" value="<?= h($opText) ?>" readonly><input type="hidden" name="operational_period_id" value="<?= h((string)($op['id'] ?? $h['operational_period_id'] ?? '')) ?>"></label>
</div>
<h3>Risk Analysis</h3>
<table class="ics215a-table"><thead><tr><th style="width:25%">4. Incident Area</th><th style="width:35%">5. Hazards / Risks</th><th>6. Mitigations</th></tr></thead><tbody>
<?php foreach($rows as $r): ?><tr class="risk-row"><td><textarea name="area[]"><?= h($r['incident_area'] ?? '') ?></textarea></td><td><textarea name="hazards[]"><?= h($r['hazards_risks'] ?? '') ?></textarea></td><td><textarea name="mitigations[]"><?= h($r['mitigations'] ?? '') ?></textarea></td></tr><?php endforeach; ?>
<tr class="risk-row"><td><textarea name="area[]"></textarea></td><td><textarea name="hazards[]"></textarea></td><td><textarea name="mitigations[]"></textarea></td></tr>
</tbody></table>
<?php if($canEdit): ?><p><button type="button" class="ics215a-add-row" id="ics215a-add-risk-row">Add Risk Analysis Row</button></p><?php endif; ?>
<h3>7. Prepared / Reviewed By</h3>
<?php if(!$canSign): ?><p class="muted">Only the IC, SOFR, or OSC can complete the review/sign-off fields. Other users may view this form.</p><?php endif; ?>
<div class="ics215a-sign">
<fieldset><legend>Safety Officer (SOFR)</legend><label>Name<input name="sofr_name" value="<?= h($h['sofr_name'] ?? $sofrName) ?>" <?= $canSign ? '' : 'readonly' ?>></label><label>Date/Time<span class="ics215a-dt-wrap" data-ics215a-datetime><input class="ics215a-dt-display" name="sofr_signed_at" placeholder="YYYY-MM-DD HH:mm" value="<?= h(i215a_dt($h['sofr_signed_at'] ?? '')) ?>" <?= $canSign ? '' : 'readonly' ?>><button type="button" class="ics215a-dt-button" <?= $canSign ? '' : 'disabled' ?>>Pick</button></span></label></fieldset>
<fieldset><legend>Operations Section Chief (OSC)</legend><label>Name<input name="osc_name" value="<?= h($h['osc_name'] ?? $oscName) ?>" <?= $canSign ? '' : 'readonly' ?>></label><label>Date/Time<span class="ics215a-dt-wrap" data-ics215a-datetime><input class="ics215a-dt-display" name="osc_signed_at" placeholder="YYYY-MM-DD HH:mm" value="<?= h(i215a_dt($h['osc_signed_at'] ?? '')) ?>" <?= $canSign ? '' : 'readonly' ?>><button type="button" class="ics215a-dt-button" <?= $canSign ? '' : 'disabled' ?>>Pick</button></span></label></fieldset>
</div>
<?php
if (function_exists('render_ics_form_actions')) {
    $faPerms = $permissions ?? $formPermissions ?? ['can_view'=>true,'can_create'=>true,'can_edit'=>true,'can_submit_final'=>true];
    $faIncident = $incidentId ?? ($_GET['incident_id'] ?? ($_POST['incident_id'] ?? ''));
    $faOp = $operationalPeriodId ?? $opId ?? ($_GET['op_id'] ?? ($_GET['operational_period_id'] ?? ''));
    render_ics_form_actions([
        'incident_id' => $faIncident ?? null,
        'op_id' => $faOp ?? null,
        'form_code' => 'ICS215A',
        'form_title' => 'ICS215A',
        'permissions' => $faPerms,
        'is_final' => !empty($record['is_final']) || !empty($formRecord['is_final']),        'blank_download_url' => file_exists(__DIR__ . '/blank.php') ? 'blank.php' : null,
    ]);
}
?>
</form></div>
<script>
(function(){
  const tbody = document.querySelector('.ics215a-table tbody');

  function pad2(n){ return String(n).padStart(2,'0'); }
  function parse(v){
    var m = String(v||'').match(/^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})$/);
    return m ? {y:+m[1],mo:+m[2]-1,d:+m[3],h:+m[4],mi:+m[5]} : null;
  }

  function init(wrap){
    var input=wrap.querySelector('.ics215a-dt-display'),button=wrap.querySelector('.ics215a-dt-button');
    if(!input||!button||wrap.dataset.pickerBound==='1')return;
    wrap.dataset.pickerBound='1';
    var p=parse(input.value),now=new Date(),state=p||{y:now.getFullYear(),mo:now.getMonth(),d:now.getDate(),h:now.getHours(),mi:Math.min(55,Math.round(now.getMinutes()/5)*5)},pop=null;
    function close(){if(pop){pop.remove();pop=null}}
    function set(){input.value=state.y+'-'+pad2(state.mo+1)+'-'+pad2(state.d)+' '+pad2(state.h)+':'+pad2(state.mi);close();input.dispatchEvent(new Event('input',{bubbles:true}))}
    function draw(){
      close();pop=document.createElement('div');pop.className='ics215a-dt-popover';pop.addEventListener('click',e=>e.stopPropagation());
      pop.innerHTML='<div class="ics215a-dt-head"><button type="button" data-prev>&lt;</button><div class="ics215a-dt-month"></div><button type="button" data-next>&gt;</button></div><div class="ics215a-dt-grid"></div><div class="ics215a-dt-time"><label>Time</label><select data-hour></select><span>:</span><select data-minute></select></div><div class="ics215a-dt-actions"><button type="button" data-clear>Clear</button><button type="button" data-apply>Apply</button></div>';
      pop.querySelector('.ics215a-dt-month').textContent=new Date(state.y,state.mo,1).toLocaleString('en-CA',{month:'long',year:'numeric'});
      var grid=pop.querySelector('.ics215a-dt-grid');['Sun','Mon','Tue','Wed','Thu','Fri','Sat'].forEach(d=>{var e=document.createElement('div');e.className='ics215a-dt-dow';e.textContent=d;grid.appendChild(e)});
      for(var b=0;b<new Date(state.y,state.mo,1).getDay();b++){var blank=document.createElement('div');blank.className='ics215a-dt-day blank';grid.appendChild(blank)}
      for(var day=1,days=new Date(state.y,state.mo+1,0).getDate();day<=days;day++){var cell=document.createElement('button');cell.type='button';cell.className='ics215a-dt-day'+(day===state.d?' selected':'');cell.textContent=day;cell.addEventListener('click',((dd)=>()=>{state.d=dd;draw()})(day));grid.appendChild(cell)}
      var hs=pop.querySelector('[data-hour]'),ms=pop.querySelector('[data-minute]');for(var h=0;h<24;h++)hs.add(new Option(pad2(h),pad2(h),h===state.h,h===state.h));for(var mi=0;mi<60;mi+=5)ms.add(new Option(pad2(mi),pad2(mi),mi===state.mi,mi===state.mi));
      hs.onchange=function(){state.h=+this.value};ms.onchange=function(){state.mi=+this.value};
      pop.querySelector('[data-prev]').onclick=function(){state.mo--;if(state.mo<0){state.mo=11;state.y--}state.d=Math.min(state.d,new Date(state.y,state.mo+1,0).getDate());draw()};
      pop.querySelector('[data-next]').onclick=function(){state.mo++;if(state.mo>11){state.mo=0;state.y++}state.d=Math.min(state.d,new Date(state.y,state.mo+1,0).getDate());draw()};
      pop.querySelector('[data-clear]').onclick=function(){input.value='';close();input.dispatchEvent(new Event('input',{bubbles:true}))};
      pop.querySelector('[data-apply]').onclick=set;wrap.appendChild(pop)
    }
    button.addEventListener('click',e=>{e.stopPropagation();var p=parse(input.value);if(p)state=p;pop?close():draw()});
    document.addEventListener('click',e=>{if(pop&&!wrap.contains(e.target))close()})
  }

  function bind(scope){(scope||document).querySelectorAll('[data-ics215a-datetime]').forEach(init)}

  function rowHasRiskText(row){
    if(!row) return false;
    return Array.prototype.some.call(row.querySelectorAll('textarea[name="area[]"],textarea[name="hazards[]"],textarea[name="mitigations[]"]'), function(t){
      return t.value.trim() !== '';
    });
  }

  function addBlankRiskRow(){
    if(!tbody) return;
    var rows = tbody.querySelectorAll('tr.risk-row');
    var last = rows[rows.length - 1];
    if(!rowHasRiskText(last)){
      alert('Enter text in at least one Incident Area, Hazards/Risks, or Mitigations field before adding another row.');
      return;
    }
    var tr = last.cloneNode(true);
    Array.prototype.forEach.call(tr.querySelectorAll('textarea'), function(e){ e.value=''; });
    tbody.appendChild(tr);
  }

  var addBtn = document.getElementById('ics215a-add-risk-row');
  if(addBtn){
    addBtn.addEventListener('click', addBlankRiskRow);
  }

  bind(document);
})();
</script>
<?php render_footer(); ?>
