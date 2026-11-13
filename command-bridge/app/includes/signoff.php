<?php
declare(strict_types=1);

/**
 * Staged sign-off shared by the ICS forms: the creator signs (submits) the form, names an approver, and that person
 * approves and signs it (or returns it for changes).
 *
 *   draft --(creator signs / submits)--> submitted --(approver approves)--> approved
 *                                           |  \--(approver or editor returns / reopens)--> draft
 *
 * The panel is rendered once per page by render_ics_form_actions() and posts to /forms/signoff.php. The ICS 213RR forms keep
 * their own workflow; ICS 202 and ICS 204 already have their own approval step.
 * No database change is needed: form_records.current_approver_user_id / approved_at and the 'approved_by' signature type exist.
 */

function signoff_normalize(string $code): string
{
    return strtoupper(str_replace([' ', '_', '-'], '', trim($code)));
}

function signoff_form_supported(string $code): bool
{
    $c = signoff_normalize($code);
    if ($c === '' || strpos($c, 'ICS213RR') === 0 || in_array($c, ['FINANCE', 'RESOURCES', 'ICS202', 'ICS204'], true)) { return false; }
    return (bool)preg_match('/^ICS\d+[A-Z]*$/', $c);
}

/** Forms with no submit button of their own get a "Sign and submit" button in the panel. */
function signoff_needs_creator_sign(string $code): bool
{
    return in_array(signoff_normalize($code), ['ICS213', 'ICS214', 'ICS215A', 'ICS218'], true);
}

function signoff_is_tsc(): bool
{
    return !empty(current_user()['is_tsc_admin']);
}

/** @return array{record: ?array, creator: ?array, approval: ?array} */
function signoff_state(int $formRecordId): array
{
    $pdo = db();
    $st = $pdo->prepare('SELECT fr.id, fr.incident_id, fr.operational_period_id, fr.form_type, fr.status, fr.current_approver_user_id, fr.approved_at,
            fr.submitted_by_user_id, fr.submitted_by_name, fr.prepared_by_user_id, fr.prepared_by_name, fr.submitted_at,
            u.real_name AS approver_name, u.username AS approver_username
        FROM form_records fr LEFT JOIN users u ON u.id = fr.current_approver_user_id
        WHERE fr.id = ? AND fr.is_deleted = 0 LIMIT 1');
    $st->execute([$formRecordId]);
    $record = $st->fetch() ?: null;
    $sig = function (string $type) use ($pdo, $formRecordId): ?array {
        $s = $pdo->prepare('SELECT signer_user_id, signer_name, signer_position_title, signed_at FROM form_signatures
            WHERE form_record_id = ? AND signature_type = ? ORDER BY id DESC LIMIT 1');
        $s->execute([$formRecordId, $type]);
        return $s->fetch() ?: null;
    };
    $creator = $record ? $sig('prepared_by') : null;
    // Forms that mark themselves submitted without a signature row: fall back to who submitted / prepared them.
    if ($record && !$creator && in_array(strtolower((string)$record['status']), ['submitted', 'approved'], true)) {
        $uid = (int)($record['submitted_by_user_id'] ?: $record['prepared_by_user_id'] ?: 0);
        $creator = ['signer_user_id' => $uid, 'signer_name' => (string)($record['submitted_by_name'] ?: $record['prepared_by_name'] ?: ''),
            'signer_position_title' => '', 'signed_at' => (string)($record['submitted_at'] ?? '')];
    }
    return ['record' => $record, 'creator' => $creator, 'approval' => $record ? $sig('approved_by') : null];
}

/** People who can be named as approver: active users holding a position in the incident, plus TSC admins. */
function signoff_approver_candidates(int $incidentId, int $excludeUserId = 0): array
{
    try {
        return signoff_approver_candidates_query($incidentId, $excludeUserId);
    } catch (Throwable $e) {
        security_log($e, 'signoff_candidates');
        return [];
    }
}

function signoff_approver_candidates_query(int $incidentId, int $excludeUserId): array
{
    $st = db()->prepare("SELECT u.id, COALESCE(NULLIF(u.real_name, ''), u.username) AS name, u.is_tsc_admin,
            GROUP_CONCAT(DISTINCT r.role_code ORDER BY r.role_code SEPARATOR ', ') AS roles
        FROM users u
        LEFT JOIN incident_positions ip ON ip.user_id = u.id AND ip.incident_id = ? AND ip.deleted_at IS NULL
            AND (ip.assigned_end IS NULL OR ip.assigned_end >= NOW())
        LEFT JOIN roles r ON r.id = ip.role_id
        WHERE u.is_active = 1 AND u.deleted_at IS NULL AND u.id <> ? AND (ip.id IS NOT NULL OR u.is_tsc_admin = 1)
        GROUP BY u.id, u.real_name, u.username, u.is_tsc_admin
        ORDER BY name");
    $st->execute([$incidentId, $excludeUserId]);
    return $st->fetchAll() ?: [];
}

// ---------------------------------------------------------------------------------------------------------------
// Automatic approver selection (1.0.2.55)
// ---------------------------------------------------------------------------------------------------------------

/** Who normally approves each form (position acronym, or '@supervisor' = the signer's own supervisor). Editable in Settings. */
function signoff_default_approver_map(): array
{
    $m = [
        'ICS201' => 'IC', 'ICS203' => 'PSC', 'ICS205' => 'LSC', 'ICS205A' => 'LSC', 'ICS206' => 'LSC', 'ICS207' => 'PSC',
        'ICS208' => 'IC', 'ICS209' => 'PSC', 'ICS209WF' => 'PSC', 'ICS210' => 'PSC', 'ICS211' => 'PSC', 'ICS213' => '@supervisor',
        'ICS214' => '@supervisor', 'ICS215A' => 'IC', 'ICS218' => 'LSC', 'ICS230' => 'IC',
    ];
    $out = [];
    foreach ($m as $f => $p) { $out[$f] = ['primary' => $p, 'fallback' => '']; }
    return $out;
}

/** Defaults overlaid with whatever the administrator saved (app_settings key signoff_approver_map). */
function signoff_approver_map(): array
{
    $map = signoff_default_approver_map();
    try {
        $raw = function_exists('get_app_setting') ? get_app_setting('signoff_approver_map', '') : '';
        $saved = $raw ? json_decode((string)$raw, true) : null;
        if (is_array($saved)) {
            foreach ($saved as $form => $cfg) {
                $f = signoff_normalize((string)$form);
                if (!is_array($cfg)) { continue; }
                $primary = strtoupper(trim((string)($cfg['primary'] ?? '')));
                if ($primary === '@SUPERVISOR') { $primary = '@supervisor'; }
                if ($primary === '') { continue; }
                $map[$f] = ['primary' => $primary, 'fallback' => strtoupper(trim((string)($cfg['fallback'] ?? '')))];
            }
        }
    } catch (Throwable $e) { /* defaults */ }
    return $map;
}

/** position acronym => reports_to acronym, from the chain-of-command catalogue. */
function signoff_reports_to_map(): array
{
    static $cache = null;
    if ($cache !== null) { return $cache; }
    $cache = [];
    try {
        foreach (db()->query("SELECT acronym, reports_to_acronym FROM incident_position_catalog WHERE is_active = 1")->fetchAll() as $r) {
            $cache[strtoupper((string)$r['acronym'])] = strtoupper(trim((string)($r['reports_to_acronym'] ?? '')));
        }
    } catch (Throwable $e) { /* catalogue missing: chain falls back to IC / UC */ }
    return $cache;
}

/** Active users holding a position in the incident; those assigned to the operational period first. */
function signoff_position_holders(int $incidentId, string $acronym, int $opId = 0): array
{
    if ($acronym === '') { return []; }
    try {
        $st = db()->prepare("SELECT DISTINCT u.id FROM incident_positions ip
            INNER JOIN roles r ON r.id = ip.role_id INNER JOIN users u ON u.id = ip.user_id
            WHERE ip.incident_id = ? AND r.role_code = ? AND ip.deleted_at IS NULL AND (ip.assigned_end IS NULL OR ip.assigned_end >= NOW())
              AND u.is_active = 1 AND u.deleted_at IS NULL ORDER BY u.id");
        $st->execute([$incidentId, $acronym]);
        $ids = array_map('intval', array_column($st->fetchAll(), 'id'));
    } catch (Throwable $e) { return []; }
    if (count($ids) > 1 && $opId > 0 && function_exists('operational_period_position_assignments')) {
        try {
            $assigned = [];
            foreach (operational_period_position_assignments($incidentId, $opId) as $a) {
                if (strtoupper((string)($a['position_code'] ?? '')) === $acronym) { $assigned[] = (int)$a['user_id']; }
            }
            $pref = array_values(array_intersect($ids, $assigned));
            if ($pref) { $ids = $pref; }
        } catch (Throwable $e) { /* keep all */ }
    }
    return $ids;
}

/** Holding IC or UC (top of the chain) or TSC: may approve a form they signed themselves when nobody ranks above (always logged). */
function signoff_self_approval_allowed(int $incidentId, int $userId): bool
{
    if (signoff_is_tsc()) { return true; }
    try { return (bool)array_intersect(['IC', 'UC'], array_map('strtoupper', get_user_role_codes($incidentId, $userId))); }
    catch (Throwable $e) { return false; }
}

/**
 * Works out who should approve a form.
 * @return array{user_id:int, position:string, choices:int[], self:bool}
 *   user_id > 0: use this person. user_id = 0 and choices non-empty: several people hold the position, signer picks.
 */
function signoff_resolve_approver(int $incidentId, string $formCode, int $signerUserId, int $opId = 0): array
{
    $none = ['user_id' => 0, 'position' => '', 'choices' => [], 'self' => false];
    try {
        $code = signoff_normalize($formCode);
        $cfg = signoff_approver_map()[$code] ?? ['primary' => 'IC', 'fallback' => ''];
        $up = signoff_reports_to_map();
        $climb = static function (string $from) use ($up): array {
            $out = []; $seen = [];
            $p = $from;
            for ($i = 0; $i < 12 && $p !== '' && !isset($seen[$p]); $i++) { $seen[$p] = true; $p = $up[$p] ?? ''; if ($p !== '') { $out[] = $p; } }
            return $out;
        };
        $seq = [];
        if ($cfg['primary'] === '@supervisor') {
            // The signer's own supervisor: the position their highest role reports to.
            foreach (get_user_role_codes($incidentId, $signerUserId) as $rc) {
                $rc = strtoupper($rc);
                if (($up[$rc] ?? '') !== '') { $seq[] = $up[$rc]; foreach ($climb($up[$rc]) as $c) { $seq[] = $c; } break; }
            }
        } else {
            $seq[] = $cfg['primary'];
            if ($cfg['fallback'] !== '') { $seq[] = $cfg['fallback']; }
            foreach ($climb($cfg['primary']) as $c) { $seq[] = $c; }
        }
        $seq[] = 'IC'; $seq[] = 'UC';
        $tried = [];
        foreach ($seq as $pos) {
            if (isset($tried[$pos])) { continue; }
            $tried[$pos] = true;
            $holders = array_values(array_diff(signoff_position_holders($incidentId, $pos, $opId), [$signerUserId]));
            if (count($holders) === 1) { return ['user_id' => $holders[0], 'position' => $pos, 'choices' => [], 'self' => false]; }
            if (count($holders) > 1) { return ['user_id' => 0, 'position' => $pos, 'choices' => $holders, 'self' => false]; }
        }
        // Nobody in the chain: a TSC admin, else the IC / UC signing their own form.
        $st = db()->prepare("SELECT id FROM users WHERE is_tsc_admin = 1 AND is_active = 1 AND deleted_at IS NULL AND id <> ? ORDER BY id");
        $st->execute([$signerUserId]);
        $tsc = array_map('intval', array_column($st->fetchAll(), 'id'));
        if (count($tsc) === 1) { return ['user_id' => $tsc[0], 'position' => 'TSC', 'choices' => [], 'self' => false]; }
        if (count($tsc) > 1) { return ['user_id' => 0, 'position' => 'TSC', 'choices' => $tsc, 'self' => false]; }
        if (signoff_self_approval_allowed($incidentId, $signerUserId)) { return ['user_id' => $signerUserId, 'position' => 'IC', 'choices' => [], 'self' => true]; }
    } catch (Throwable $e) {
        security_log($e, 'signoff_resolve');
    }
    return $none;
}

/** When a form has just been signed and has no approver, pick one from the chain of command, record it and notify them. */
function signoff_auto_assign(int $formRecordId): void
{
    try {
        $state = signoff_state($formRecordId);
        $rec = $state['record'];
        if (!$rec || strtolower((string)$rec['status']) !== 'submitted' || (int)$rec['current_approver_user_id'] > 0) { return; }
        $code = signoff_normalize((string)$rec['form_type']);
        if (!signoff_form_supported($code)) { return; }
        $incidentId = (int)$rec['incident_id'];
        $signerId = (int)($state['creator']['signer_user_id'] ?? 0);
        $res = signoff_resolve_approver($incidentId, $code, $signerId, (int)$rec['operational_period_id']);
        if ($res['user_id'] <= 0) { return; }
        $pdo = db();
        $upd = $pdo->prepare('UPDATE form_records SET current_approver_user_id = ?, updated_at = NOW() WHERE id = ? AND (current_approver_user_id IS NULL OR current_approver_user_id = 0)');
        $upd->execute([$res['user_id'], $formRecordId]);
        if ($upd->rowCount() < 1) { return; }
        $label = function_exists('ics_audit_form_label') ? ics_audit_form_label($code) : $code;
        audit_log($signerId ?: null, 'auto_approver', $code, $formRecordId, 'Approver for ' . $label . ' (record #' . $formRecordId . ') chosen automatically: user #' . $res['user_id'] . ' as ' . $res['position'] . ($res['self'] ? ' (self-approval: nobody ranks above)' : ''));
        if ($res['user_id'] !== $signerId) {
            $link = $code === 'ICS214'
                ? app_url('/forms/ics214/index.php') . '?' . http_build_query(['record_id' => $formRecordId])
                : app_url('/forms/' . strtolower($code) . '/index.php') . '?' . http_build_query(['incident_id' => $incidentId, 'op_id' => (int)$rec['operational_period_id']]);
            $body = (string)($state['creator']['signer_name'] ?? 'A user') . ' signed ' . $label . ' and it needs your approval.';
            $pdo->prepare("INSERT INTO notifications
                (incident_id, user_id, role_code, form_record_id, notification_type, title, subject, body, link_url, related_table, related_id, urgency, is_read, created_at)
                VALUES (?, ?, NULL, ?, 'form_approval_requested', ?, ?, ?, ?, 'form_records', ?, 'high', 0, NOW())")
                ->execute([$incidentId, $res['user_id'], $formRecordId, $label . ' needs your approval', $label . ' needs your approval', $body, $link, $formRecordId]);
        }
    } catch (Throwable $e) {
        security_log($e, 'signoff_auto_assign');
    }
}

function signoff_hidden_form_html(): string
{
    if (empty($GLOBALS['cb_signoff_form_needed'])) { return ''; }
    return '<form id="cb-signoff-form" method="post" action="' . e(app_url('/forms/signoff.php')) . '" style="display:none">' . csrf_field() . '</form>';
}

/** Renders the sign-off panel. Called by render_ics_form_actions(). */
function signoff_render_panel(string $formCode, int $incidentId, int $opId, int $formRecordId): void
{
    if (!signoff_form_supported($formCode) || $formRecordId <= 0) { return; }
    $state = signoff_state($formRecordId);
    $rec = $state['record'];
    if (!$rec) { return; }
    $code = signoff_normalize($formCode);
    $status = strtolower((string)$rec['status']);
    $me = (int)(current_user()['id'] ?? 0);
    $isTsc = signoff_is_tsc();
    $perms = get_current_user_form_permissions($code, $incidentId);
    $canSign = $isTsc || !empty($perms['can_submit_final']);
    $canEdit = $isTsc || !empty($perms['can_edit']) || !empty($perms['can_create']);
    $canReopenApproved = $canSign;
    if ($code === 'ICS214') {
        // Personal activity log: only its owner signs it; once approved it stays locked (a TSC administrator can reopen it).
        require_once __DIR__ . '/ics214_log.php';
        $logOwnerId = ics214_owner_of_record($formRecordId);
        $canSign = $logOwnerId > 0 && $logOwnerId === $me;
        $canEdit = $canSign || $isTsc;
        $canReopenApproved = $isTsc;
    }
    $creator = $state['creator'];
    $creatorId = (int)($creator['signer_user_id'] ?? 0);
    $approverId = (int)($rec['current_approver_user_id'] ?? 0);
    $isApprover = $approverId > 0 && $approverId === $me;
    $returnUrl = (string)($_SERVER['REQUEST_URI'] ?? '');
    $GLOBALS['cb_signoff_form_needed'] = true;

    $btn = function (string $action, string $label, string $class, string $confirm = '') {
        return '<button type="submit" form="cb-signoff-form" name="signoff_action" value="' . e($action) . '" class="btn ' . e($class) . '"'
            . ($confirm !== '' ? ' onclick="return confirm(\'' . e($confirm) . '\');"' : '') . '>' . e($label) . '</button> ';
    };

    echo '<div class="card mt-4 mb-4 no-print cb-signoff"><div class="card-header"><strong>Sign-off</strong></div><div class="card-body">';
    // Context fields travel with whichever button is pressed.
    foreach (['signoff_form_code' => $code, 'signoff_record_id' => $formRecordId, 'signoff_incident_id' => $incidentId, 'signoff_op_id' => $opId, 'signoff_return' => $returnUrl] as $k => $val) {
        echo '<input type="hidden" form="cb-signoff-form" name="' . e($k) . '" value="' . e((string)$val) . '">';
    }

    if ($status === 'draft' || $status === 'rejected' || $status === '') {
        echo '<p class="small">Step 1 of 2: the person who prepares this form signs it. Step 2: a named approver approves and signs it.</p>';
        if (signoff_needs_creator_sign($code)) {
            if ($canSign) {
                echo '<p>' . $btn('creator_sign', 'Sign and submit ' . ics_audit_form_label($code), 'btn-success', 'Sign and submit this form? It will be locked until it is returned for changes.') . '</p>';
            } else {
                echo '<p class="small">' . ($code === 'ICS214' ? 'Only the owner of this activity log can sign it.' : 'You do not have permission to sign this form.') . '</p>';
            }
        } else {
            echo '<p class="small">Use the Submit button above to sign it. You will then be asked to name an approver.</p>';
        }
    } elseif ($status === 'submitted') {
        echo '<p><strong>Signed by:</strong> ' . e((string)($creator['signer_name'] ?? ($rec['approver_name'] ? '' : 'unknown'))) . ' (' . e((string)($creator['signer_position_title'] ?? '')) . ')'
           . (!empty($creator['signed_at']) ? ' on ' . e((string)$creator['signed_at']) : '') . '</p>';
        $canNameApprover = $canSign || $canEdit || ($creatorId > 0 && $creatorId === $me);
        if ($approverId > 0) {
            echo '<p><strong>Awaiting approval by:</strong> ' . e((string)($rec['approver_name'] ?: $rec['approver_username'])) . '</p>';
        } else {
            echo '<p class="priority-high"><strong>No approver named yet.</strong> Choose who approves this form.</p>';
        }
        if ($isApprover || $isTsc) {
            echo '<p>' . $btn('approve', 'Approve and sign', 'btn-success', 'Approve and digitally sign this form?') . '</p>';
        }
        if ($canNameApprover) {
            $cands = signoff_approver_candidates($incidentId, signoff_self_approval_allowed($incidentId, $creatorId) ? 0 : $creatorId);
            $suggest = ['user_id' => 0, 'position' => '', 'choices' => []];
            if ($approverId <= 0) { $suggest = signoff_resolve_approver($incidentId, $code, $creatorId, (int)$opId); }
            echo '<div style="display:flex;flex-wrap:wrap;gap:8px;align-items:center;margin:8px 0"><label for="signoff_approver">' . ($approverId > 0 ? 'Change approver' : 'Approver') . '</label>'
               . '<select form="cb-signoff-form" id="signoff_approver" name="signoff_approver_id"><option value="">-- Select approver --</option>';
            foreach ($cands as $c) {
                echo '<option value="' . (int)$c['id'] . '"' . (((int)$c['id'] === $approverId || ($approverId <= 0 && (int)$c['id'] === (int)$suggest['user_id'])) ? ' selected' : '') . '>' . e($c['name'] . ($c['roles'] ? ' (' . $c['roles'] . ')' : (!empty($c['is_tsc_admin']) ? ' (TSC)' : ''))) . '</option>';
            }
            echo '</select> ';
            if ($approverId <= 0 && (int)$suggest['user_id'] > 0) { echo '<span class="small">Suggested from the chain of command (' . e($suggest['position']) . ').</span> '; }
            elseif ($approverId <= 0 && $suggest['choices']) { echo '<span class="small">More than one person holds ' . e($suggest['position']) . '; choose one.</span> '; }
            echo $btn('request', $approverId > 0 ? 'Update approver' : 'Send for approval', 'btn-primary') . '</div>';
        }
        if ($isApprover || $isTsc || $canNameApprover) {
            echo '<div style="margin-top:8px"><label for="signoff_reason">Return for changes / reopen (reason required)</label><br>'
               . '<input form="cb-signoff-form" type="text" id="signoff_reason" name="signoff_reason" maxlength="300" style="width:100%;max-width:560px" onkeydown="if(event.keyCode===13){event.preventDefault();}"> '
               . $btn('return', 'Return for changes', 'btn-outline-secondary') . '</div>';
        }
    } elseif ($status === 'approved') {
        $ap = $state['approval'];
        echo '<p><strong>Signed by:</strong> ' . e((string)($creator['signer_name'] ?? '')) . (!empty($creator['signer_position_title']) ? ' (' . e((string)$creator['signer_position_title']) . ')' : '')
           . (!empty($creator['signed_at']) ? ' on ' . e((string)$creator['signed_at']) : '') . '</p>';
        echo '<p><strong>Approved by:</strong> ' . e((string)($ap['signer_name'] ?? ($rec['approver_name'] ?? ''))) . (!empty($ap['signer_position_title']) ? ' (' . e((string)$ap['signer_position_title']) . ')' : '')
           . (!empty($ap['signed_at']) ? ' on ' . e((string)$ap['signed_at']) : '') . '</p>';
        if ($canReopenApproved) {
            echo '<div style="margin-top:8px"><label for="signoff_reason">Reopen for changes (reason required)</label><br>'
               . '<input form="cb-signoff-form" type="text" id="signoff_reason" name="signoff_reason" maxlength="300" style="width:100%;max-width:560px" onkeydown="if(event.keyCode===13){event.preventDefault();}"> '
               . $btn('return', 'Reopen form', 'btn-outline-secondary', 'Reopen this approved form? Both signatures will be cleared.') . '</div>';
        }
    }
    echo '</div></div>';
}
