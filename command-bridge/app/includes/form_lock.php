<?php
declare(strict_types=1);
/*
 * One lock rule for every ICS form, plus "Revise".
 *
 *  - A form is LOCKED once the person who prepared it signs it ("submitted", waiting for approval) and stays locked
 *    after it is approved. Form pages call cb_form_assert_editable() before saving anything.
 *  - "Revise" (cb_form_revise()) is the only way to change a locked form: it keeps a record of the version being
 *    replaced (who signed and approved it, why it was revised, and a PDF of it), clears both signatures and opens the
 *    form as a draft with the next revision number. The form then has to be signed and approved again.
 *  - A few things record what happened rather than change the plan, so they are allowed on a locked form:
 *    marking an ICS 230 meeting held or cancelled (forms/ics230/meeting_status.php) and new ICS 213 messages and
 *    replies (each message is already locked once it is saved).
 *  - cb_form_status_banner() draws the status banner at the top of each form (same look as the ICS 213RR): status,
 *    steps, the next action with its buttons, and the revision history.
 *
 * The ICS 213RR keeps its own approval workflow (spending needs the Section Chief and Logistics) and does not use this.
 */
require_once __DIR__ . '/signoff.php';
require_once __DIR__ . '/form_action_buttons.php';

const CB_FORM_LOCKED_STATUSES = ['submitted', 'approved'];

/** Forms that use the shared lock and Revise (the sign-off forms plus the ICS 202 and 204 with their own approval). */
function cb_form_lock_supported(string $code): bool
{
    $c = signoff_normalize($code);
    return signoff_form_supported($c) || in_array($c, ['ICS202', 'ICS204'], true);
}

function cb_form_is_locked_status(?string $status): bool
{
    return in_array(strtolower(trim((string)$status)), CB_FORM_LOCKED_STATUSES, true);
}

/** Short status text used on the banner and in lists. */
function cb_form_status_text(?string $status): string
{
    $s = strtolower(trim((string)$status));
    return match ($s) {
        'submitted' => 'Waiting for approval',
        'approved' => 'Approved',
        'rejected' => 'Returned for changes',
        '', 'draft' => 'Draft',
        default => ucwords(str_replace('_', ' ', $s)),
    };
}

function cb_form_locked_message(string $code, ?string $status): string
{
    $label = ics_audit_form_label(signoff_normalize($code));
    return strtolower((string)$status) === 'approved'
        ? $label . ' has been approved and is locked. Use Revise at the top of the form to change it.'
        : $label . ' has been signed and is waiting for approval, so it is locked. Use Revise at the top of the form to change it.';
}

/**
 * Throws when the form record is locked. Pass the record row (or null for a form that has not been saved yet).
 * Form pages call this before saving, adding or deleting anything.
 */
function cb_form_assert_editable(?array $formRecord, string $code): void
{
    if ($formRecord && cb_form_is_locked_status((string)($formRecord['status'] ?? ''))) {
        throw new RuntimeException(cb_form_locked_message($code, (string)$formRecord['status']));
    }
}

/** Same check by record id (reads the current status from the database). */
function cb_form_assert_editable_id(int $formRecordId, string $code): void
{
    if ($formRecordId <= 0) { return; }
    $st = db()->prepare('SELECT status FROM form_records WHERE id = ? LIMIT 1');
    $st->execute([$formRecordId]);
    $status = $st->fetchColumn();
    if ($status !== false && cb_form_is_locked_status((string)$status)) {
        throw new RuntimeException(cb_form_locked_message($code, (string)$status));
    }
}

/**
 * Call near the top of a form page, before it handles a POST. If the form is signed or approved, any POST is refused
 * with a message and the page reloads, except the actions listed in $allowed (e.g. ['action' => ['approve_psc']]).
 * When $formRecordId is 0 the record is looked up by incident, form and operational period.
 */
function cb_form_block_locked_post(string $code, int $incidentId, int $opId = 0, int $formRecordId = 0, array $allowed = []): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST' || $incidentId <= 0) { return; }
    foreach ($allowed as $field => $values) {
        if (in_array((string)($_POST[$field] ?? ''), (array)$values, true)) { return; }
    }
    $code = signoff_normalize($code);
    if ($formRecordId > 0) {
        $st = db()->prepare('SELECT status FROM form_records WHERE id = ? LIMIT 1');
        $st->execute([$formRecordId]);
        $status = (string)($st->fetchColumn() ?: '');
    } else {
        $row = get_form_record($incidentId, $code, $opId > 0 ? $opId : null);
        $status = (string)($row['status'] ?? '');
    }
    if (!cb_form_is_locked_status($status)) { return; }
    flash('error', cb_form_locked_message($code, $status));
    $back = (string)($_SERVER['REQUEST_URI'] ?? '');
    if ($back === '' || $back[0] !== '/' || strpos($back, '//') === 0) { $back = app_url('/dashboard/index.php'); }
    header('Location: ' . $back);
    exit;
}

function cb_form_revision_number(int $formRecordId): int
{
    if ($formRecordId <= 0) { return 0; }
    try {
        $st = db()->prepare('SELECT revision FROM form_records WHERE id = ? LIMIT 1');
        $st->execute([$formRecordId]);
        return (int)($st->fetchColumn() ?: 0);
    } catch (Throwable $e) { return 0; }   // database not updated yet (migration 0005)
}

/** Earlier versions of a form, newest first. */
function cb_form_revisions(int $formRecordId): array
{
    if ($formRecordId <= 0) { return []; }
    try {
        $st = db()->prepare('SELECT * FROM form_revisions WHERE form_record_id = ? ORDER BY revision DESC, id DESC');
        $st->execute([$formRecordId]);
        return $st->fetchAll() ?: [];
    } catch (Throwable $e) { return []; }
}

/** The position that normally approves a form (for the ICS 202 / 204, which have their own approval steps). */
function cb_form_approver_position(string $code): string
{
    $c = signoff_normalize($code);
    if ($c === 'ICS202') { return 'IC'; }
    if ($c === 'ICS204') { return 'PSC'; }
    $map = signoff_approver_map();
    return (string)($map[$c]['primary'] ?? '');
}

/**
 * Who may revise a locked form: the person who signed it, the named approver, people who hold the position that
 * approves it, anyone who can edit or sign the form, and a SysAdmin. The ICS 214 is a personal log: only its owner,
 * the named approver (while waiting) and a SysAdmin.
 */
function cb_form_can_revise(int $formRecordId): bool
{
    $user = current_user();
    if (!$user || $formRecordId <= 0) { return false; }
    $me = (int)$user['id'];
    if (!empty($user['is_sysadmin'])) { return true; }
    $state = signoff_state($formRecordId);
    $rec = $state['record'];
    if (!$rec || !cb_form_is_locked_status((string)$rec['status'])) { return false; }
    $code = signoff_normalize((string)$rec['form_type']);
    $incidentId = (int)$rec['incident_id'];
    $creatorId = (int)($state['creator']['signer_user_id'] ?? 0);
    $approverId = (int)($rec['current_approver_user_id'] ?? 0);
    $status = strtolower((string)$rec['status']);
    if ($code === 'ICS214') {
        require_once __DIR__ . '/ics214_log.php';
        $owner = ics214_owner_of_record($formRecordId);
        return $owner === $me || ($status === 'submitted' && $approverId === $me);
    }
    if ($creatorId === $me || $approverId === $me) { return true; }
    $perms = get_current_user_form_permissions($code, $incidentId);
    if (!empty($perms['can_edit']) || !empty($perms['can_submit_final'])) { return true; }
    $pos = cb_form_approver_position($code);
    if ($pos !== '' && $pos[0] !== '@' && in_array($me, signoff_position_holders($incidentId, $pos), true)) { return true; }
    return false;
}

/** The PHP command-line program, for making the PDF snapshot in a separate process. */
function cb_php_cli_binary(): string
{
    $bin = (string)PHP_BINARY;
    if ($bin !== '' && preg_match('/^php[0-9.]*$/', basename($bin)) && is_executable($bin)) { return $bin; }
    foreach (['/usr/bin/php', '/usr/local/bin/php', '/usr/bin/php' . PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION] as $c) {
        if (is_executable($c)) { return $c; }
    }
    return 'php';
}

/**
 * Saves a PDF of the form as it is now (before it is revised), by running app/bin/form_snapshot.php as the current
 * user. Returns [relative file name or null, error message or null]. Never throws: a revision still goes ahead
 * without a PDF, and the history says why.
 */
function cb_form_snapshot_pdf(array $rec, int $revision): array
{
    try {
        if (!function_exists('proc_open')) { return [null, 'The server cannot run programs (proc_open is disabled).']; }
        $incidentId = (int)$rec['incident_id'];
        $code = signoff_normalize((string)$rec['form_type']);
        $relDir = 'uploads/incidents/' . $incidentId . '/form_revisions';
        $dir = storage_root_path() . '/' . $relDir;
        ensure_directory($dir);
        $file = $code . '_record' . (int)$rec['id'] . '_rev' . $revision . '_' . date('Ymd_His') . '.pdf';
        $out = $dir . '/' . $file;
        $cmd = [cb_php_cli_binary(), dirname(__DIR__) . '/bin/form_snapshot.php',
            '--user=' . (int)(current_user()['id'] ?? 0), '--record=' . (int)$rec['id'], '--out=' . $out];
        $proc = @proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__), null, ['bypass_shell' => true]);
        if (!is_resource($proc)) { return [null, 'Could not start the PDF snapshot.']; }
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $output = '';
        $deadline = microtime(true) + 90;
        while (true) {
            $output .= (string)stream_get_contents($pipes[1]) . (string)stream_get_contents($pipes[2]);
            $st = proc_get_status($proc);
            if (!$st['running']) { break; }
            if (microtime(true) > $deadline) { proc_terminate($proc); $output .= ' (timed out)'; break; }
            usleep(100000);
        }
        $output .= (string)stream_get_contents($pipes[1]) . (string)stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        proc_close($proc);
        if (is_file($out) && filesize($out) > 100 && file_get_contents($out, false, null, 0, 5) === '%PDF-') {
            return [$relDir . '/' . $file, null];
        }
        @unlink($out);
        $msg = trim(preg_replace('/\s+/', ' ', strip_tags($output)) ?? '');
        return [null, mb_substr($msg !== '' ? $msg : 'The PDF could not be made.', 0, 250)];
    } catch (Throwable $e) {
        security_log($e, 'form_snapshot');
        return [null, 'The PDF could not be made.'];
    }
}

/**
 * Revise a locked form: save the replaced version (signatures + PDF), clear the signatures and approval, and open it
 * as a draft with the next revision number. Throws when not allowed.
 */
function cb_form_revise(int $formRecordId, string $reason): int
{
    $reason = trim($reason);
    if ($reason === '') { throw new RuntimeException('Enter a reason for the revision.'); }
    $state = signoff_state($formRecordId);
    $rec = $state['record'];
    if (!$rec) { throw new RuntimeException('That form could not be found.'); }
    $code = signoff_normalize((string)$rec['form_type']);
    if (!cb_form_lock_supported($code)) { throw new RuntimeException('This form has its own workflow and cannot be revised here.'); }
    $status = strtolower((string)$rec['status']);
    if (!cb_form_is_locked_status($status)) { throw new RuntimeException('This form is already open for editing.'); }
    if (!cb_form_can_revise($formRecordId)) { throw new RuntimeException('You cannot revise this form.'); }

    $pdo = db();
    $user = current_user() ?: [];
    $me = (int)($user['id'] ?? 0);
    $myName = (string)(($user['display_name'] ?? '') ?: ($user['real_name'] ?? '') ?: ($user['username'] ?? ''));
    $incidentId = (int)$rec['incident_id'];
    $revision = cb_form_revision_number($formRecordId);
    $creator = $state['creator'] ?? [];
    $approval = $state['approval'] ?? [];
    // ICS 202 / 204 keep their approval on their own header table.
    if (!$approval && $code === 'ICS202') {
        $st = $pdo->prepare('SELECT approved_by_ic_name AS signer_name, approved_at AS signed_at FROM form_202_headers WHERE form_record_id = ? LIMIT 1');
        $st->execute([$formRecordId]);
        $row = $st->fetch();
        if ($row && trim((string)$row['signer_name']) !== '') { $approval = $row + ['signer_position_title' => 'Incident Commander']; }
    }
    if (!$approval && $code === 'ICS204' && $status === 'approved') {
        $st = $pdo->prepare('SELECT approved_by_name AS signer_name FROM form_204_headers WHERE form_record_id = ? LIMIT 1');
        $st->execute([$formRecordId]);
        $row = $st->fetch();
        if ($row && trim((string)$row['signer_name']) !== '') {
            $approval = $row + ['signer_position_title' => 'Planning Section Chief', 'signed_at' => (string)($rec['approved_at'] ?? '')];
        }
    }

    // 1. A PDF of the version being replaced (made before anything changes).
    [$snapshot, $snapshotError] = !empty($GLOBALS['cb_skip_revision_snapshot'])
        ? [null, 'Not saved (smoke test).']
        : cb_form_snapshot_pdf($rec, $revision);

    // 2. Record it, clear the signatures and approval, open the form as the next revision.
    $ownTransaction = !$pdo->inTransaction();   // the smoke test runs inside its own transaction
    if ($ownTransaction) { $pdo->beginTransaction(); }
    try {
        $pdo->prepare('INSERT INTO form_revisions (form_record_id, incident_id, form_code, revision, status_before, reason, revised_by_user_id,
                revised_by_name, revised_at, prepared_by_name, prepared_by_position_title, prepared_signed_at, approved_by_name,
                approved_by_position_title, approved_signed_at, snapshot_file, snapshot_error, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?, ?, ?, ?, ?, ?, ?, ?, NOW())')
            ->execute([$formRecordId, $incidentId, $code, $revision, $status, mb_substr($reason, 0, 300), $me ?: null, $myName,
                ($creator['signer_name'] ?? null) ?: null, ($creator['signer_position_title'] ?? null) ?: null, ($creator['signed_at'] ?? null) ?: null,
                ($approval['signer_name'] ?? null) ?: null, ($approval['signer_position_title'] ?? null) ?: null, ($approval['signed_at'] ?? null) ?: null,
                $snapshot, $snapshotError]);
        $pdo->prepare("UPDATE form_records SET status = 'draft', current_approver_user_id = NULL, approved_at = NULL, submitted_at = NULL,
                revision = revision + 1, updated_at = NOW() WHERE id = ?")->execute([$formRecordId]);
        $pdo->prepare("DELETE FROM form_signatures WHERE form_record_id = ? AND signature_type IN ('prepared_by', 'submitted_by', 'approved_by')")
            ->execute([$formRecordId]);
        if ($code === 'ICS202') {
            $pdo->prepare('UPDATE form_202_headers SET approved_by_ic_user_id = NULL, approved_by_ic_name = NULL, approved_at = NULL WHERE form_record_id = ?')
                ->execute([$formRecordId]);
        } elseif ($code === 'ICS204') {
            $pdo->prepare('UPDATE form_204_headers SET approved_by_user_id = NULL, approved_by_name = NULL WHERE form_record_id = ?')
                ->execute([$formRecordId]);
        } elseif (in_array($code, ['ICS209', 'ICS209WF'], true) && $status === 'approved') {
            // Block 14 is filled in when the report is approved (forms/signoff.php); the new revision needs a new approval.
            $cols = table_columns('form_209');
            if (in_array('approved_by_name', $cols, true) && in_array('approved_datetime', $cols, true)) {
                $pdo->prepare('UPDATE form_209 SET approved_by_name = NULL, approved_datetime = NULL WHERE record_id = ?')->execute([$formRecordId]);
            }
        }
        if ($ownTransaction) { $pdo->commit(); }
    } catch (Throwable $e) {
        if ($ownTransaction && $pdo->inTransaction()) { $pdo->rollBack(); }
        throw $e;
    }

    $label = ics_audit_form_label($code);
    audit_log($me ?: null, 'revise', $code, $formRecordId, 'Revised ' . $label . ' (record #' . $formRecordId . ', was '
        . cb_form_status_text($status) . ', now revision ' . ($revision + 1) . '): ' . mb_substr($reason, 0, 300)
        . ($snapshot ? '' : ' [no PDF of the earlier version: ' . (string)$snapshotError . ']'));

    // 3. Tell the person who signed it and the approver (if that is not who revised it).
    $link = $code === 'ICS214'
        ? app_url('/forms/ics214/index.php') . '?' . http_build_query(['record_id' => $formRecordId])
        : app_url('/forms/' . strtolower($code) . '/index.php') . '?' . http_build_query(['incident_id' => $incidentId, 'op_id' => (int)$rec['operational_period_id']]);
    $notify = [(int)($creator['signer_user_id'] ?? 0), (int)($rec['current_approver_user_id'] ?? 0)];
    foreach (array_unique(array_filter($notify, static fn($id) => $id > 0 && $id !== $me)) as $uid) {
        try {
            $title = $label . ' revised: needs signing again';
            $pdo->prepare("INSERT INTO notifications (incident_id, user_id, role_code, form_record_id, notification_type, title, subject, body, link_url,
                    related_table, related_id, urgency, is_read, created_at)
                VALUES (?, ?, NULL, ?, 'form_revised', ?, ?, ?, ?, 'form_records', ?, 'high', 0, NOW())")
                ->execute([$incidentId, $uid, $formRecordId, $title, $title,
                    $myName . ' revised ' . $label . ' (revision ' . ($revision + 1) . '): ' . mb_substr($reason, 0, 250)
                    . ' The signatures were cleared; it has to be signed and approved again.', $link, $formRecordId]);
        } catch (Throwable $e) { security_log($e, 'form_revise_notify'); }
    }
    return $revision + 1;
}

/** Revision history and the PDF links, for the banner. */
function cb_form_revision_history_html(int $formRecordId): string
{
    $rows = cb_form_revisions($formRecordId);
    if (!$rows) { return ''; }
    $h = '<details class="cb-form-history"><summary>Revision history (' . count($rows) . ' earlier version' . (count($rows) === 1 ? '' : 's') . ')</summary>'
        . '<div class="help-table-wrap"><table class="table"><thead><tr><th>Version</th><th>Revised</th><th>Reason</th><th>Signed by</th><th>Approved by</th><th>PDF</th></tr></thead><tbody>';
    foreach ($rows as $r) {
        $ver = (int)$r['revision'] === 0 ? 'Original' : 'Revision ' . (int)$r['revision'];
        $signed = trim((string)$r['prepared_by_name']) !== ''
            ? e((string)$r['prepared_by_name']) . ($r['prepared_by_position_title'] ? ' (' . e((string)$r['prepared_by_position_title']) . ')' : '')
              . ($r['prepared_signed_at'] ? '<br><span class="small">' . e((string)$r['prepared_signed_at']) . '</span>' : '')
            : '<span class="small">-</span>';
        $approved = trim((string)$r['approved_by_name']) !== ''
            ? e((string)$r['approved_by_name']) . ($r['approved_by_position_title'] ? ' (' . e((string)$r['approved_by_position_title']) . ')' : '')
              . ($r['approved_signed_at'] ? '<br><span class="small">' . e((string)$r['approved_signed_at']) . '</span>' : '')
            : '<span class="small">Not approved</span>';
        $pdf = !empty($r['snapshot_file'])
            ? '<a href="' . e(app_url('/forms/revision_pdf.php') . '?id=' . (int)$r['id']) . '" target="_blank" rel="noopener">PDF</a>'
            : '<span class="small" title="' . e((string)$r['snapshot_error']) . '">No PDF</span>';
        $h .= '<tr><td>' . e($ver) . '</td><td>' . e((string)$r['revised_at']) . '<br><span class="small">by ' . e((string)$r['revised_by_name']) . '</span></td>'
            . '<td>' . e((string)$r['reason']) . '</td><td>' . $signed . '</td><td>' . $approved . '</td><td>' . $pdf . '</td></tr>';
    }
    return $h . '</tbody></table></div></details>';
}

/**
 * Status banner at the top of a form page (same look as the ICS 213RR): form name, status, the steps, what happens
 * next (with the buttons for whoever acts next) and the revision history. Replaces the sign-off box that used to sit
 * under the form. Draw it outside the page's own <form>.
 *
 * $opts:
 *   subtitle        - e.g. "Operational period 2 (2026-11-14 01:00 to 13:00)"
 *   approve         - for the ICS 202 / 204: ['can' => bool, 'field' => 'action', 'value' => 'approve_ics202', 'label' => '...']
 *   submit_hint     - text for a draft: how to sign/submit this form
 *   preview_url / download_url - override the PDF links
 */
function cb_form_status_banner(string $formCode, int $incidentId, int $opId, int $formRecordId, array $opts = []): void
{
    $code = signoff_normalize($formCode);
    $GLOBALS['cb_form_banner_done'] = true;
    $label = ics_audit_form_label($code);
    $names = [
        'ICS201' => 'Incident Briefing', 'ICS202' => 'Incident Objectives', 'ICS203' => 'Organization Assignment List',
        'ICS204' => 'Assignment List', 'ICS205' => 'Incident Radio Communications Plan', 'ICS205A' => 'Communications List',
        'ICS206' => 'Medical Plan', 'ICS207' => 'Incident Organization Chart', 'ICS208' => 'Safety Message/Plan',
        'ICS209' => 'Incident Status Summary', 'ICS209WF' => 'Wildfire Incident Status Summary', 'ICS210' => 'Resource Status Change',
        'ICS211' => 'Check-In List', 'ICS213' => 'General Message', 'ICS214' => 'Activity Log',
        'ICS215A' => 'Incident Action Plan Safety Analysis', 'ICS218' => 'Support Vehicle/Equipment Inventory', 'ICS230' => 'Daily Meeting Schedule',
    ];
    $state = $formRecordId > 0 ? signoff_state($formRecordId) : ['record' => null, 'creator' => null, 'approval' => null];
    $rec = $state['record'];
    $status = strtolower((string)($rec['status'] ?? 'draft'));
    if ($status === '') { $status = 'draft'; }
    $locked = cb_form_is_locked_status($status);
    // ICS 213: each message locks itself when saved; new messages and replies are always allowed.
    $pageLocked = $locked && $code !== 'ICS213';
    $GLOBALS['cb_form_locked'] = $pageLocked;
    $revision = cb_form_revision_number($formRecordId);
    $me = (int)(current_user()['id'] ?? 0);
    $isSysadmin = signoff_is_sysadmin();
    $creator = $state['creator'];
    $approval = $state['approval'];
    $creatorId = (int)($creator['signer_user_id'] ?? 0);
    $approverId = (int)($rec['current_approver_user_id'] ?? 0);
    $ownApproval = in_array($code, ['ICS202', 'ICS204'], true);
    $approvePos = cb_form_approver_position($code);
    $approverName = (string)(($rec['approver_name'] ?? '') ?: ($rec['approver_username'] ?? ''));

    // ICS 202 / 204 keep the approver on their own header row.
    if ($ownApproval && $formRecordId > 0 && !$approval) {
        try {
            if ($code === 'ICS202') {
                $st = db()->prepare('SELECT approved_by_ic_name AS signer_name, approved_at AS signed_at FROM form_202_headers WHERE form_record_id = ? LIMIT 1');
            } else {
                $st = db()->prepare('SELECT approved_by_name AS signer_name, NULL AS signed_at FROM form_204_headers WHERE form_record_id = ? LIMIT 1');
            }
            $st->execute([$formRecordId]);
            $row = $st->fetch();
            if ($row && trim((string)$row['signer_name']) !== '' && $status === 'approved') {
                $approval = ['signer_name' => $row['signer_name'], 'signer_position_title' => $code === 'ICS202' ? 'Incident Commander' : 'Planning Section Chief',
                    'signed_at' => (string)($row['signed_at'] ?: ($rec['approved_at'] ?? ''))];
            }
        } catch (Throwable $e) { /* older database */ }
    }

    $positionNames = ['IC' => 'Incident Commander', 'PSC' => 'Planning Section Chief', 'LSC' => 'Logistics Section Chief',
        'OSC' => 'Operations Section Chief', 'FSC' => 'Finance/Administration Section Chief', '@supervisor' => 'supervisor'];
    $approverRole = $positionNames[$approvePos] ?? $approvePos;

    // Steps: Prepare -> Sign -> Approval -> Approved (the ICS 202 has no separate signing step).
    $steps = $code === 'ICS202' ? [1 => 'Prepare', 2 => 'IC approval', 3 => 'Approved']
        : [1 => 'Prepare', 2 => ($code === 'ICS204' ? 'Submit' : 'Sign'), 3 => 'Approval', 4 => 'Approved'];
    $last = count($steps);
    $step = match ($status) {
        'approved' => $last,
        'submitted' => $code === 'ICS202' ? 2 : 3,
        default => 1,
    };
    $pill = cb_form_status_text($status);
    if ($status === 'submitted') {
        $pill = $approverName !== '' ? 'Waiting for ' . $approverName : 'Waiting for approval';
    }

    $pdf = function_exists('ics_form_pdf_urls') ? ics_form_pdf_urls($code, ['incident_id' => $incidentId, 'op_id' => $opId, 'extra_query' => (array)($opts['extra_query'] ?? [])]) : ['available' => false];
    $previewUrl = (string)($opts['preview_url'] ?? ($pdf['preview'] ?? ''));
    $downloadUrl = (string)($opts['download_url'] ?? ($pdf['download'] ?? ''));

    $returnUrl = (string)($_SERVER['REQUEST_URI'] ?? '');
    $GLOBALS['cb_signoff_form_needed'] = true;
    $ctx = '';
    foreach (['signoff_form_code' => $code, 'signoff_record_id' => $formRecordId, 'signoff_incident_id' => $incidentId, 'signoff_op_id' => $opId, 'signoff_return' => $returnUrl] as $k => $v) {
        $ctx .= '<input type="hidden" form="cb-signoff-form" name="' . e($k) . '" value="' . e((string)$v) . '">';
    }
    $btn = static function (string $action, string $text, string $class, string $confirm = ''): string {
        return '<button type="submit" form="cb-signoff-form" name="signoff_action" value="' . e($action) . '" class="btn ' . e($class) . '"'
            . ($confirm !== '' ? ' onclick="return confirm(\'' . e($confirm) . '\');"' : '') . '>' . e($text) . '</button> ';
    };
    $reasonBox = static function (string $id, string $labelText, string $action, string $button, string $confirm) use ($btn): string {
        return '<div class="cb-form-reason"><label for="' . e($id) . '">' . e($labelText) . '</label>'
            . '<input form="cb-signoff-form" type="text" id="' . e($id) . '" name="signoff_reason" maxlength="300" onkeydown="if(event.keyCode===13){event.preventDefault();}"> '
            . $btn($action, $button, 'btn-secondary', $confirm) . '</div>';
    };

    $perms = $incidentId > 0 ? get_current_user_form_permissions($code, $incidentId) : [];
    $canSign = $isSysadmin || !empty($perms['can_submit_final']);
    $canEdit = $isSysadmin || !empty($perms['can_edit']) || !empty($perms['can_create']);
    if ($code === 'ICS214') {
        require_once __DIR__ . '/ics214_log.php';
        $owner = $formRecordId > 0 ? ics214_owner_of_record($formRecordId) : $me;
        $canSign = $owner > 0 && $owner === $me;
        $canEdit = $canSign || $isSysadmin;
    }
    $isApprover = $approverId > 0 && $approverId === $me;
    $canRevise = $locked && cb_form_can_revise($formRecordId);

    // ---- what happens next -------------------------------------------------------------------------------------
    $next = '';
    if ($formRecordId <= 0 || in_array($status, ['draft', 'rejected'], true)) {
        $who = $code === 'ICS202' ? 'the Incident Commander approves it' : 'the person who prepared it signs it, then the ' . $approverRole . ' approves it';
        $next .= '<p><strong>' . ($revision > 0 ? 'Revision ' . $revision . ' (draft):' : 'Draft:') . '</strong> fill in the form and save it. When it is ready, ' . e($who) . '. '
            . 'Signing locks the form.</p>';
        if ($formRecordId > 0 && signoff_form_supported($code) && signoff_needs_creator_sign($code)) {
            $next .= $canSign
                ? '<p>' . $btn('creator_sign', 'Sign and submit ' . $label, 'btn-success', 'Sign and submit this form? It will be locked; changing it after that needs a revision.') . '</p>'
                : '<p class="small">' . ($code === 'ICS214' ? 'Only the owner of this activity log can sign it.' : 'You do not have permission to sign this form.') . '</p>';
        } elseif (!empty($opts['submit_hint'])) {
            $next .= '<p class="small">' . e((string)$opts['submit_hint']) . '</p>';
        } elseif ($code !== 'ICS202') {
            $next .= '<p class="small">Use <strong>Sign and Submit</strong> at the bottom of the form.</p>';
        }
        if ($code === 'ICS202' && !empty($opts['approve']['can'])) {
            $next .= '<p class="small">Save the form, then approve it with <strong>' . e((string)($opts['approve']['label'] ?? 'Approve')) . '</strong> below.</p>';
        }
    } elseif ($status === 'submitted') {
        $signed = trim((string)($creator['signer_name'] ?? '')) !== ''
            ? e((string)$creator['signer_name']) . (!empty($creator['signer_position_title']) ? ' (' . e((string)$creator['signer_position_title']) . ')' : '')
              . (!empty($creator['signed_at']) ? ' on ' . e((string)$creator['signed_at']) : '')
            : 'the preparer';
        $next .= '<p><strong>Signed by</strong> ' . $signed . '.</p>';
        if ($ownApproval) {
            $next .= '<p class="cb-form-waiting"><strong>Not approved yet.</strong> The ' . e($approverRole) . ' still has to approve it.</p>';
            if (!empty($opts['approve']['can'])) {
                $GLOBALS['cb_page_action_form_needed'] = true;
                $next .= '<p><button type="submit" form="cb-page-action-form" name="' . e((string)$opts['approve']['field']) . '" value="' . e((string)$opts['approve']['value'])
                    . '" class="btn btn-success" onclick="return confirm(\'Approve and sign this form?\');">' . e((string)$opts['approve']['label']) . '</button></p>';
            }
        } else {
            $next .= $approverId > 0
                ? '<p class="cb-form-waiting"><strong>Not approved yet.</strong> Waiting for <strong>' . e($approverName) . '</strong> to approve it.</p>'
                : '<p class="cb-form-waiting priority-high"><strong>Not approved yet, and no approver is named.</strong> Choose who approves it below.</p>';
            if ($isApprover || $isSysadmin) {
                $next .= '<p>' . $btn('approve', 'Approve and sign', 'btn-success', 'Approve and digitally sign this form?') . '</p>';
                $next .= $reasonBox('cb_return_reason', 'Or return it for changes (reason required). This starts a new revision.', 'revise', 'Return for changes',
                    'Return this form for changes? The signature is cleared and it opens as a new revision.');
            }
            $canNameApprover = $canSign || $canEdit || ($creatorId > 0 && $creatorId === $me);
            if ($canNameApprover && !$isApprover) {
                $selfOk = signoff_self_approval_allowed($incidentId, $creatorId);
                $cands = signoff_approver_candidates($incidentId, $selfOk ? 0 : $creatorId);
                $suggest = $approverId <= 0 ? signoff_resolve_approver($incidentId, $code, $creatorId, $opId) : ['user_id' => 0, 'position' => '', 'choices' => []];
                $next .= '<div class="cb-form-approver"><label for="signoff_approver">' . ($approverId > 0 ? 'Change approver' : 'Approver') . '</label> '
                    . '<select form="cb-signoff-form" id="signoff_approver" name="signoff_approver_id"><option value="">-- Select approver --</option>';
                foreach ($cands as $c) {
                    $sel = ((int)$c['id'] === $approverId || ($approverId <= 0 && (int)$c['id'] === (int)$suggest['user_id'])) ? ' selected' : '';
                    $next .= '<option value="' . (int)$c['id'] . '"' . $sel . '>' . e($c['name'] . ($c['roles'] ? ' (' . $c['roles'] . ')' : (!empty($c['is_sysadmin']) ? ' (SysAdmin)' : ''))) . '</option>';
                }
                $next .= '</select> ' . $btn('request', $approverId > 0 ? 'Update approver' : 'Send for approval', 'btn-primary') . '</div>';
            }
        }
        if ($canRevise && !($isApprover || ($isSysadmin && !$ownApproval))) {
            $next .= $reasonBox('cb_revise_reason', 'Revise (reason required). Clears the signature and opens a new revision.', 'revise', 'Revise',
                'Revise this form? The signature is cleared and it opens as a new revision that has to be signed and approved again.');
        } elseif ($canRevise && $ownApproval) {
            $next .= $reasonBox('cb_revise_reason', 'Revise (reason required). Clears the signature and opens a new revision.', 'revise', 'Revise',
                'Revise this form? It opens as a new revision that has to be submitted and approved again.');
        }
    } elseif ($status === 'approved') {
        if ($creator && trim((string)($creator['signer_name'] ?? '')) !== '' && $code !== 'ICS202') {
            $next .= '<p><strong>Signed by</strong> ' . e((string)$creator['signer_name']) . (!empty($creator['signer_position_title']) ? ' (' . e((string)$creator['signer_position_title']) . ')' : '')
                . (!empty($creator['signed_at']) ? ' on ' . e((string)$creator['signed_at']) : '') . '.</p>';
        }
        $next .= '<p><strong>Approved by</strong> ' . e((string)($approval['signer_name'] ?? $approverName))
            . (!empty($approval['signer_position_title']) ? ' (' . e((string)$approval['signer_position_title']) . ')' : '')
            . (!empty($approval['signed_at']) ? ' on ' . e((string)$approval['signed_at']) : '') . '. The form is complete and locked.</p>';
        if ($canRevise) {
            $next .= $reasonBox('cb_revise_reason', 'Revise (reason required). Clears both signatures and opens a new revision.', 'revise', 'Revise',
                'Revise this approved form? Both signatures are cleared and it opens as a new revision that has to be signed and approved again.');
        }
    }
    if ($code === 'ICS230' && $locked) {
        $next .= '<p class="small">Meetings can still be marked held or cancelled with <strong>Was it held?</strong> in the meeting list. Adding, moving or removing a meeting needs a revision.</p>';
    }
    if ($code === 'ICS213' && $locked) {
        $next .= '<p class="small">New messages and replies can still be added; each message is locked once it is saved.</p>';
    }

    $subtitle = (string)($opts['subtitle'] ?? '');
    $subtitleParts = array_filter([$subtitle, $revision > 0 ? 'Revision ' . $revision : '']);
    ?>
    <div class="cb-form-banner no-print">
      <div class="card cb-form-hero<?= $locked ? ' is-locked' : '' ?>">
        <div>
          <p class="cb-form-eyebrow"><?= e($label) ?></p>
          <h1 class="cb-form-title"><?= e($names[$code] ?? $label) ?></h1>
          <?php if ($subtitleParts): ?><p class="cb-form-subtitle"><?= e(implode(' · ', $subtitleParts)) ?></p><?php endif; ?>
        </div>
        <div class="cb-form-hero-side">
          <span class="cb-form-status cb-form-status-<?= e($status) ?>"><?= $locked ? '&#128274; ' : '' ?><?= e($pill) ?></span><br>
          <?php if ($previewUrl !== '' && !empty($pdf['available'])): ?>
            <a class="btn" target="_blank" rel="noopener" href="<?= e($previewUrl) ?>">Preview PDF</a>
            <a class="btn" target="_blank" rel="noopener" href="<?= e($downloadUrl) ?>">Download PDF</a>
          <?php endif; ?>
        </div>
      </div>
      <div class="cb-form-steps" style="grid-template-columns:repeat(<?= (int)$last ?>, minmax(0,1fr))" aria-label="Form sign-off steps">
        <?php foreach ($steps as $n => $text):
            $cls = $n === $step ? ($n === $last ? ' complete current' : ' current') : ($n < $step ? ' complete' : ''); ?>
          <div class="cb-form-step<?= $cls ?>"><?= (int)$n ?>. <?= e($text) ?></div>
        <?php endforeach; ?>
      </div>
      <section class="cb-form-next" aria-labelledby="cb-form-next-heading">
        <h2 id="cb-form-next-heading">Next action</h2>
        <?= $ctx ?>
        <?= $next ?>
        <?= cb_form_revision_history_html($formRecordId) ?>
      </section>
    </div>
    <?php if ($pageLocked): ?>
    <script>
    // Locked form: make the page's own inputs read-only. Forms marked .cb-lock-exempt (ICS 213 messages/replies,
    // ICS 230 "Was it held?") and the banner itself stay usable. The server refuses changes either way.
    document.addEventListener('DOMContentLoaded', function () {
      document.querySelectorAll('main form:not(.cb-lock-exempt)').forEach(function (f) {
        if (f.id === 'cb-signoff-form' || f.id === 'cb-page-action-form' || f.closest('.cb-form-banner')) { return; }
        if ((f.getAttribute('method') || 'get').toLowerCase() !== 'post') { return; }
        f.querySelectorAll('input:not([type=hidden]), textarea, select').forEach(function (el) { el.disabled = true; });
        f.querySelectorAll('button, input[type=submit]').forEach(function (b) {
          if (b.getAttribute('form') === 'cb-signoff-form') { return; }
          b.disabled = true; b.title = 'Locked: use Revise at the top of the form to make changes.';
        });
      });
      document.querySelectorAll('main .js-add-row, main .js-remove-row').forEach(function (b) { b.disabled = true; });
    });
    </script>
    <?php endif;
}

/** Hidden form the ICS 202 / 204 approve button posts with (rendered in the footer, outside the page's own form). */
function cb_page_action_form_html(): string
{
    if (empty($GLOBALS['cb_page_action_form_needed'])) { return ''; }
    return '<form id="cb-page-action-form" method="post" action="" style="display:none">' . csrf_field() . '</form>';
}
