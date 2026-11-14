<?php
declare(strict_types=1);
/*
 * ICS 214 Activity Log: one log per person per operational period (see includes/ics214_log.php).
 *
 *   index.php                      your own log for the selected operational period
 *   index.php?user_id=N[&op_id=N]  someone else's log (IC/UC, SysAdmin, their supervisor, DOCL)
 *   index.php?record_id=N          a specific log (used by sign-off and notification links)
 *
 * Entries are only ever added, never edited or deleted. A log takes entries while it is a draft or returned.
 */
require_once __DIR__ . '/../../includes/security.php';
require_csrf_for_post();
require_once __DIR__ . '/../../includes/form_action_buttons.php';
require_once dirname(__DIR__, 2) . '/includes/layout.php';
require_once dirname(__DIR__, 2) . '/includes/ics214_log.php';
require_login();

$pdo = db();
$incident = active_incident();
$incidentId = (int)($incident['id'] ?? 0);
if ($incidentId <= 0) {
    render_header('ICS 214 - Activity Log');
    echo '<div class="card"><p>No active incident is available.</p></div>';
    render_footer(); exit;
}
require_active_operational_period($incidentId);

$me = current_user();
$myId = (int)($me['id'] ?? 0);
$myName = trim((string)($me['display_name'] ?? $me['username'] ?? ''));

// Whose log, and for which operational period.
$log = null;
$recordParam = (int)($_GET['record_id'] ?? $_POST['record_id'] ?? 0);
if ($recordParam > 0) {
    $log = ics214_find_log_by_record($incidentId, $recordParam);
    if (!$log) {
        http_response_code(404);
        render_header('ICS 214 - Activity Log');
        echo '<div class="card"><p>That activity log was not found.</p></div>';
        render_footer(); exit;
    }
    $ownerId = (int)$log['owner_user_id'];
    $opId = (int)$log['operational_period_id'];
    $op = $opId > 0 ? get_operational_period_by_id($incidentId, $opId) : null;
} else {
    $ownerId = (int)($_GET['user_id'] ?? $_POST['user_id'] ?? 0) ?: $myId;
    $op = current_operational_period($incidentId);
    $opId = (int)($op['id'] ?? 0);
}

$access = ics214_access($incidentId, $ownerId, $opId);
if (!$access['view']) {
    http_response_code(403);
    render_header('ICS 214 - Activity Log');
    echo '<div class="card"><h2>ICS 214 - Activity Log</h2><p>You can open your own activity log. Other people&rsquo;s logs can be opened by the '
        . 'Incident Commander, a SysAdmin, their supervisor and the Documentation Unit Leader.</p>'
        . '<p><a class="btn" href="' . e(ics214_url()) . '">Open my activity log</a></p></div>';
    render_footer(); exit;
}

$isOwner = $ownerId === $myId;
// Your own log exists from the moment you open it (normally it was started when your login was created).
if ($isOwner && !$log && $opId > 0) {
    ics214_ensure_log($incidentId, $opId, $ownerId, $myId);
}
if (!$log) { $log = ics214_find_log($incidentId, $opId, $ownerId); }
$recordId = (int)($log['id'] ?? 0);
$status = strtolower((string)($log['status'] ?? 'not started'));
$isOpen = ics214_is_open($log);
$owner = ics214_person($incidentId, $ownerId);
$ownerName = $owner['name'] !== '' ? $owner['name'] : ('User #' . $ownerId);
$selfUrl = $recordId > 0 ? ics214_url(0, 0, $recordId) : ics214_url($ownerId, $opId);

// ---------------------------------------------------------------------------------------------- actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');
    try {
        if ($action === 'add_entry') {
            if (!$access['add']) { throw new RuntimeException('You cannot add entries to this activity log.'); }
            if (!$isOpen) { throw new RuntimeException('This activity log is signed and locked. It has to be returned for changes before entries can be added.'); }
            $pdo->beginTransaction();
            $recordId = $recordId ?: ics214_ensure_log($incidentId, $opId, $ownerId, $myId);
            ics214_add_entry($recordId, (string)($_POST['entry_time'] ?? ''), (string)($_POST['entry_text'] ?? ''), $myId, $myName);
            $pdo->commit();
            if (!$isOwner) {
                audit_log($myId, 'ics214_entry_added', 'ICS214', $recordId, 'Added an entry to the ICS 214 activity log of ' . $ownerName);
            }
            flash('success', 'Entry added.');
        } elseif ($action === 'save_header') {
            if (!$access['edit_header']) { throw new RuntimeException('Only the owner can change these details.'); }
            if (!$isOpen) { throw new RuntimeException('This activity log is signed and locked.'); }
            $recordId = $recordId ?: ics214_ensure_log($incidentId, $opId, $ownerId, $myId);
            $prepared = trim((string)($_POST['date_time_prepared'] ?? ''));
            if ($prepared !== '' && (!preg_match('/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}$/', $prepared) || strtotime($prepared) === false)) {
                throw new InvalidArgumentException('Enter Date/Time Prepared as YYYY-MM-DD HH:MM.');
            }
            $pdo->prepare("UPDATE form_214_headers SET personnel_name = ?, ICS_position_title = ?, home_agency = ?, unit_name = ?,
                    date_time_prepared = ?, prepared_by_name = ?, prepared_by_position_title = ? WHERE form_record_id = ?")
                ->execute([trim((string)($_POST['personnel_name'] ?? '')), trim((string)($_POST['ICS_position_title'] ?? '')),
                    trim((string)($_POST['home_agency'] ?? '')), trim((string)($_POST['unit_name'] ?? '')),
                    $prepared !== '' ? date('Y-m-d H:i:s', (int)strtotime($prepared)) : null,
                    trim((string)($_POST['personnel_name'] ?? '')), trim((string)($_POST['ICS_position_title'] ?? '')), $recordId]);
            $pdo->prepare('UPDATE form_records SET updated_at = NOW() WHERE id = ?')->execute([$recordId]);
            flash('success', 'Details saved.');
        } elseif ($action === 'request_update') {
            if (!$access['request_update']) { throw new RuntimeException('You cannot request changes to this activity log.'); }
            $reason = trim((string)($_POST['reason'] ?? ''));
            if ($reason === '') { throw new InvalidArgumentException('Say what needs updating.'); }
            $reason = mb_substr($reason, 0, 1000);
            $link = $recordId > 0 ? ics214_url(0, 0, $recordId) : ics214_url(0, $opId);
            $title = 'Update requested for your ICS 214 activity log';
            $pdo->prepare("INSERT INTO notifications (incident_id, user_id, role_code, form_record_id, notification_type, title, subject, body, link_url,
                    related_table, related_id, urgency, is_read, created_at)
                VALUES (?, ?, NULL, ?, 'ics214_update_requested', ?, ?, ?, ?, 'form_records', ?, 'high', 0, NOW())")
                ->execute([$incidentId, $ownerId, $recordId ?: null, $title, $title, $myName . ': ' . $reason, $link, $recordId ?: null]);
            audit_log($myId, 'ics214_update_requested', 'ICS214', $recordId,
                'Asked ' . $ownerName . ' to update their ICS 214 activity log: ' . mb_substr($reason, 0, 300));
            flash('success', $ownerName . ' has been asked to update their activity log.');
        } else {
            throw new RuntimeException('Unknown action.');
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        if (!$e instanceof InvalidArgumentException && !$e instanceof RuntimeException) { security_log($e, 'ics214'); }
        flash('error', $e instanceof PDOException ? 'The activity log could not be saved. Please try again.' : $e->getMessage());
        $_SESSION['ics214_draft'] = ['time' => (string)($_POST['entry_time'] ?? ''), 'text' => (string)($_POST['entry_text'] ?? '')];
    }
    header('Location: ' . ($recordId > 0 ? ics214_url(0, 0, $recordId) : $selfUrl));
    exit;
}
$draft = $_SESSION['ics214_draft'] ?? ['time' => '', 'text' => ''];
unset($_SESSION['ics214_draft']);

// ---------------------------------------------------------------------------------------------- view
$rows = ics214_rows($incidentId, $op, $ownerId, $recordId);
$opText = $op ? trim((string)($op['period_name'] ?? '')) . ' (' . date('Y-m-d H:i', (int)strtotime((string)$op['op_start'])) . ' to '
    . date('Y-m-d H:i', (int)strtotime((string)$op['op_end'])) . ')' : '';
$header = [
    'personnel_name' => (string)($log['personnel_name'] ?? $owner['name']),
    'ICS_position_title' => (string)($log['ICS_position_title'] ?? $owner['position']),
    'home_agency' => (string)($log['home_agency'] ?? ''),
    'unit_name' => (string)($log['unit_name'] ?? ''),
    'date_time_prepared' => !empty($log['date_time_prepared']) ? date('Y-m-d H:i', (int)strtotime((string)$log['date_time_prepared'])) : '',
];
$canEditHeader = $access['edit_header'] && $isOpen;
$statusLabels = ['not started' => 'Not started', 'draft' => 'Draft (open for entries)', 'rejected' => 'Returned for changes (open for entries)',
    'submitted' => 'Signed and submitted (locked)', 'approved' => 'Approved (locked)'];
$viewingAs = ['owner' => '', 'sysadmin' => 'SysAdmin', 'command' => 'Incident Command', 'supervisor' => 'their supervisor',
    'docl' => 'Documentation Unit Leader (view only)'][$access['as']] ?? '';

render_header('ICS 214 - Activity Log');
?>
<style>
.ics214-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px}
.ics214-log{width:100%;border-collapse:collapse}
.ics214-log th,.ics214-log td{border:1px solid #bbb;padding:6px;vertical-align:top}
.ics214-log td.when{white-space:nowrap;width:150px}
.ics214-log td.src{width:150px;font-size:13px;color:#52606d}
.ics214-log tr.entry td{background:#fbfcfe}
.ics214-add{display:grid;grid-template-columns:220px 1fr;gap:10px;align-items:start}
.ics214-add textarea{width:100%;min-height:70px;box-sizing:border-box}
.ics214-viewing{background:#eef2f7;border:1px solid #9fb3c8;border-radius:4px;padding:10px 14px;margin-bottom:12px}
.ics214-dt-wrap{display:flex;align-items:center;gap:8px;flex-wrap:nowrap;position:relative}
.ics214-dt-display{min-width:150px}
.ics214-dt-popover{position:absolute;top:100%;left:0;z-index:1000;background:#fff;border:1px solid #999;border-radius:4px;box-shadow:0 4px 14px rgba(0,0,0,.18);padding:10px;margin-top:4px;width:292px;color:#111}
.ics214-dt-head{display:flex;align-items:center;justify-content:space-between;margin-bottom:8px;gap:6px}
.ics214-dt-month{font-weight:700;text-align:center;flex:1}
.ics214-dt-grid{display:grid;grid-template-columns:repeat(7,1fr);gap:3px}
.ics214-dt-dow{font-size:12px;text-align:center;font-weight:700;color:#555;padding:3px 0}
.ics214-dt-popover .ics214-dt-grid button.ics214-dt-day{appearance:none;padding:6px 0 !important;border:1px solid #ddd !important;background:#fff !important;color:#111 !important;cursor:pointer;text-align:center;border-radius:3px;font:inherit;height:auto !important;min-height:0 !important}
.ics214-dt-popover .ics214-dt-grid button.ics214-dt-day.selected,.ics214-dt-popover .ics214-dt-grid button.ics214-dt-day:hover{background:#1f5f99 !important;color:#fff !important}
.ics214-dt-day.blank{visibility:hidden}
.ics214-dt-time{display:flex;align-items:center;gap:6px;margin-top:10px}
.ics214-dt-actions{display:flex;justify-content:flex-end;gap:8px;margin-top:10px}
@media(max-width:800px){.ics214-grid,.ics214-add{grid-template-columns:1fr}}
</style>

<?php require_once dirname(dirname(__DIR__)) . '/includes/form_lock.php';
if ($recordId > 0) { cb_form_status_banner('ICS214', (int)$incidentId, (int)$opId, (int)$recordId, ['subtitle' => $ownerName . ' · ' . $opText, 'extra_query' => ['record_id' => $recordId]]); } ?>
<div class="card">
  <h2>ICS 214 - Activity Log: <?= e($ownerName) ?></h2>
  <?php if (!$isOwner): ?>
    <div class="ics214-viewing">You are viewing <strong><?= e($ownerName) ?></strong>&rsquo;s activity log<?= $viewingAs !== '' ? ' as ' . e($viewingAs) : '' ?>.
      <a href="<?= e(ics214_url()) ?>">Open my own log</a></div>
  <?php endif; ?>
  <p><strong>Status:</strong> <?= e($statusLabels[$status] ?? ucfirst($status)) ?> &middot; <strong>Operational period:</strong> <?= e($opText) ?></p>
  <p class="no-print">
    <a href="help.php">Form instructions</a>
    <?php if ($access['list']): ?> &middot; <a href="<?= e(app_url('/forms/ics214/logs.php')) ?>">Everyone&rsquo;s activity logs</a><?php endif; ?>
  </p>

  <form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="save_header">
    <input type="hidden" name="user_id" value="<?= (int)$ownerId ?>">
    <?php if ($recordId > 0): ?><input type="hidden" name="record_id" value="<?= (int)$recordId ?>"><?php endif; ?>
    <div class="ics214-grid">
      <label>1. Incident Name<input value="<?= e((string)($log['header_incident_name'] ?? $incident['name'] ?? '')) ?>" readonly></label>
      <label>2. Operational Period<input value="<?= e($opText) ?>" readonly></label>
      <label>3. Name<input name="personnel_name" value="<?= e($header['personnel_name']) ?>" <?= $canEditHeader ? '' : 'readonly' ?>></label>
      <label>4. ICS Position<input name="ICS_position_title" value="<?= e($header['ICS_position_title']) ?>" <?= $canEditHeader ? '' : 'readonly' ?>></label>
      <label>5. Home Agency<input name="home_agency" value="<?= e($header['home_agency']) ?>" <?= $canEditHeader ? '' : 'readonly' ?>></label>
      <label>6. Unit Name<input name="unit_name" value="<?= e($header['unit_name']) ?>" <?= $canEditHeader ? '' : 'readonly' ?>></label>
      <label>7. Date/Time Prepared<input name="date_time_prepared" placeholder="YYYY-MM-DD HH:MM" value="<?= e($header['date_time_prepared']) ?>" <?= $canEditHeader ? '' : 'readonly' ?>></label>
    </div>
    <?php if ($canEditHeader): ?><p><button type="submit" class="btn btn-secondary">Save details</button></p><?php endif; ?>
  </form>
</div>

<div class="card">
  <h3>Activity log</h3>
  <p class="muted">Recorded automatically: <?= $isOwner ? 'your' : e($ownerName) . '&rsquo;s' ?> sign-ins and sign-outs, forms saved, submitted and approved,
    changes to <?= $isOwner ? 'your' : 'their' ?> account, and meetings marked as held on the ICS 230. Entries are never changed or removed: to correct one,
    add a new entry saying what was wrong.</p>
  <p class="no-print" id="ics214-filter"><strong>Show:</strong>
    <?php foreach (['entry' => 'Entries', 'form' => 'Forms', 'signin' => 'Sign-ins', 'account' => 'Account', 'meeting' => 'Meetings', 'system' => 'Other'] as $cat => $label): ?>
      <label style="display:inline;margin-left:8px"><input type="checkbox" data-cat-filter="<?= e($cat) ?>" checked> <?= e($label) ?></label>
    <?php endforeach; ?>
  </p>
  <table class="ics214-log">
    <thead><tr><th>Date/Time</th><th>Notable Activities</th><th>Source</th></tr></thead>
    <tbody>
      <?php if (!$rows): ?><tr><td colspan="3" class="muted">Nothing recorded yet for this operational period.</td></tr><?php endif; ?>
      <?php foreach ($rows as $r): ?>
        <tr data-cat="<?= e((string)$r['cat']) ?>"<?= $r['manual'] ? ' class="entry"' : '' ?>>
          <td class="when"><?= e($r['time']) ?></td>
          <td><?= nl2br(e($r['text'])) ?></td>
          <td class="src"><?php if ($r['manual']): ?>Entry<?= $r['by'] !== '' ? ' by ' . e($r['by']) : '' ?><?php if (($r['added_at'] ?? '') !== '' && $r['added_at'] !== $r['time']): ?><br><span class="small">added <?= e($r['added_at']) ?></span><?php endif; ?><?php else: ?><?= e($r['source']) ?><?php endif; ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>

  <?php if ($access['add'] && $isOpen): ?>
    <h3 class="no-print">Add an entry</h3>
    <form method="post" class="no-print">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="add_entry">
      <input type="hidden" name="user_id" value="<?= (int)$ownerId ?>">
      <?php if ($recordId > 0): ?><input type="hidden" name="record_id" value="<?= (int)$recordId ?>"><?php endif; ?>
      <div class="ics214-add">
        <label>Date/Time <span class="small">(blank = now)</span>
          <span class="ics214-dt-wrap" data-ics214-datetime><input class="ics214-dt-display" name="entry_time" placeholder="YYYY-MM-DD HH:MM" value="<?= e((string)$draft['time']) ?>"><button type="button" class="ics214-dt-button btn-small">Pick</button></span></label>
        <label>Notable activity<textarea name="entry_text" required maxlength="4000"><?= e((string)$draft['text']) ?></textarea></label>
      </div>
      <p><button type="submit" class="btn btn-primary">Add entry</button>
        <?php if (!$isOwner): ?><span class="small">The entry shows that you added it.</span><?php endif; ?></p>
    </form>
  <?php elseif ($access['add']): ?>
    <p class="small no-print">This log is signed and locked. To add entries it has to be returned for changes (Sign-off, below).</p>
  <?php endif; ?>

  <?php if ($access['request_update']): ?>
    <details class="no-print" style="margin-top:14px"><summary><strong>Ask <?= e($ownerName) ?> to update this log</strong></summary>
      <form method="post" style="margin-top:8px">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="request_update">
        <input type="hidden" name="user_id" value="<?= (int)$ownerId ?>">
        <?php if ($recordId > 0): ?><input type="hidden" name="record_id" value="<?= (int)$recordId ?>"><?php endif; ?>
        <label>What needs updating?<textarea name="reason" required maxlength="1000" style="width:100%;min-height:60px"></textarea></label>
        <p><button type="submit" class="btn btn-secondary">Send request</button>
          <span class="small"><?= e($ownerName) ?> gets a notification. If the log is already signed, its approver or a SysAdmin has to return it first.</span></p>
      </form>
    </details>
  <?php endif; ?>
</div>

<?php
if ($recordId > 0) {
    // PDF buttons and the shared sign-off panel (only the owner can sign; the approver approves or returns it).
    render_ics_form_actions([
        'incident_id' => $incidentId,
        'op_id' => $opId,
        'form_code' => 'ICS214',
        'form_title' => 'ICS 214',
        'form_record_id' => $recordId,
        'permissions' => ['can_view' => true],
        'status' => $status,
        'extra_query' => ['record_id' => $recordId],
        'blank_download_url' => null,
    ]);
} else {
    echo '<div class="card no-print"><p class="small">' . e($ownerName) . ' has not started an activity log for this operational period yet. '
        . 'It starts when they open it, or when an entry is added.</p></div>';
}
?>

<script>
(function(){
 function pad2(n){ return String(n).padStart(2,'0'); }
 function parse(v){ var m=String(v||'').match(/^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})$/); return m?{y:+m[1],mo:+m[2]-1,d:+m[3],h:+m[4],mi:+m[5]}:null; }
 document.querySelectorAll('[data-ics214-datetime]').forEach(function(wrap){
   var input=wrap.querySelector('input'), button=wrap.querySelector('button'), pop=null, now=new Date();
   var state=parse(input.value)||{y:now.getFullYear(),mo:now.getMonth(),d:now.getDate(),h:now.getHours(),mi:Math.floor(now.getMinutes()/5)*5};
   function close(){ if(pop){ pop.remove(); pop=null; } }
   function draw(){
     close(); pop=document.createElement('div'); pop.className='ics214-dt-popover';
     pop.addEventListener('click',function(ev){ ev.stopPropagation(); });
     pop.innerHTML='<div class="ics214-dt-head"><button type="button" data-prev>&lt;</button><div class="ics214-dt-month"></div><button type="button" data-next>&gt;</button></div><div class="ics214-dt-grid"></div><div class="ics214-dt-time"><span>Time</span><select data-hour></select><span>:</span><select data-minute></select></div><div class="ics214-dt-actions"><button type="button" data-clear>Clear</button><button type="button" data-apply>Apply</button></div>';
     pop.querySelector('.ics214-dt-month').textContent=new Date(state.y,state.mo,1).toLocaleString('en-CA',{month:'long',year:'numeric'});
     var grid=pop.querySelector('.ics214-dt-grid');
     ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'].forEach(function(d){ var e=document.createElement('div'); e.className='ics214-dt-dow'; e.textContent=d; grid.appendChild(e); });
     for(var b=0;b<new Date(state.y,state.mo,1).getDay();b++){ var bl=document.createElement('div'); bl.className='ics214-dt-day blank'; grid.appendChild(bl); }
     for(var day=1;day<=new Date(state.y,state.mo+1,0).getDate();day++){ var c=document.createElement('button'); c.type='button'; c.className='ics214-dt-day'+(day===state.d?' selected':''); c.textContent=day; c.addEventListener('click',(function(dd){return function(){state.d=dd; draw();};})(day)); grid.appendChild(c); }
     var hs=pop.querySelector('[data-hour]'), ms=pop.querySelector('[data-minute]');
     for(var h=0;h<24;h++) hs.add(new Option(pad2(h),h,h===state.h,h===state.h));
     for(var mi=0;mi<60;mi+=5) ms.add(new Option(pad2(mi),mi,mi===state.mi,mi===state.mi));
     hs.onchange=function(){state.h=+this.value;}; ms.onchange=function(){state.mi=+this.value;};
     pop.querySelector('[data-prev]').onclick=function(){ state.mo--; if(state.mo<0){state.mo=11;state.y--;} state.d=Math.min(state.d,new Date(state.y,state.mo+1,0).getDate()); draw(); };
     pop.querySelector('[data-next]').onclick=function(){ state.mo++; if(state.mo>11){state.mo=0;state.y++;} state.d=Math.min(state.d,new Date(state.y,state.mo+1,0).getDate()); draw(); };
     pop.querySelector('[data-clear]').onclick=function(){ input.value=''; close(); };
     pop.querySelector('[data-apply]').onclick=function(){ input.value=state.y+'-'+pad2(state.mo+1)+'-'+pad2(state.d)+' '+pad2(state.h)+':'+pad2(state.mi); close(); };
     wrap.appendChild(pop);
   }
   button.addEventListener('click',function(ev){ ev.stopPropagation(); var p=parse(input.value); if(p) state=p; pop?close():draw(); });
   document.addEventListener('click',function(ev){ if(pop && !wrap.contains(ev.target)) close(); });
 });
 document.querySelectorAll('[data-cat-filter]').forEach(function(box){
   box.addEventListener('change',function(){
     document.querySelectorAll('.ics214-log tr[data-cat="'+box.dataset.catFilter+'"]').forEach(function(tr){ tr.style.display=box.checked?'':'none'; });
   });
 });
})();
</script>
<?php render_footer(); ?>
