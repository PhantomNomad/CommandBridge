<?php
declare(strict_types=1);
/**
 * Shared sign-off actions for ICS forms (see includes/signoff.php):
 *   request      - the form's signer / an editor names (or changes) the approver
 *   approve      - the named approver (or a SysAdmin) approves and digitally signs
 *   revise       - reopen a signed or approved form as a new revision (reason required; includes/form_lock.php).
 *                  "return" (the approver sending it back) is the same action.
 *   creator_sign - sign and submit forms that have no submit button of their own (ICS 213, 214, 215A, 218)
 */
require_once __DIR__ . '/../includes/security.php';
require_csrf_for_post();
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/signoff.php';
require_once __DIR__ . '/../includes/form_lock.php';
require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    echo 'Method not allowed';
    exit;
}

$pdo = db();
$user = current_user() ?: [];
$me = (int)($user['id'] ?? 0);
$isSysadmin = signoff_is_sysadmin();
$action = (string)($_POST['signoff_action'] ?? '');
$recordId = (int)($_POST['signoff_record_id'] ?? 0);
$returnUrl = (string)($_POST['signoff_return'] ?? '');
// Only ever go back to a path on this site.
if ($returnUrl === '' || $returnUrl[0] !== '/' || strpos($returnUrl, '//') === 0 || strpos($returnUrl, "\n") !== false || strpos($returnUrl, "\r") !== false) {
    $returnUrl = app_url('/dashboard/index.php');
}
$back = static function () use (&$returnUrl): void { header('Location: ' . $returnUrl); exit; };

try {
    $state = signoff_state($recordId);
    $rec = $state['record'];
    if (!$rec) { throw new RuntimeException('That form could not be found.'); }
    $incidentId = (int)$rec['incident_id'];
    $code = signoff_normalize((string)$rec['form_type']);
    if ($action === 'revise' || $action === 'return') {
        // One way to change a locked form, for every form (the ICS 202 and 204 included). See includes/form_lock.php.
        $newRev = cb_form_revise($recordId, (string)($_POST['signoff_reason'] ?? ''));
        flash('success', ics_audit_form_label($code) . ' reopened as revision ' . $newRev . '. Make the changes, then sign it again.');
        $back();
    }
    if (!signoff_form_supported($code)) { throw new RuntimeException('This form does not use the shared sign-off.'); }
    $status = strtolower((string)$rec['status']);
    $perms = get_current_user_form_permissions($code, $incidentId);
    $canSign = $isSysadmin || !empty($perms['can_submit_final']);
    $canEdit = $isSysadmin || !empty($perms['can_edit']) || !empty($perms['can_create']);
    $canReopenApproved = $canSign;
    $isPersonalLog = $code === 'ICS214';
    if ($isPersonalLog) {
        // ICS 214 logs are personal: only the owner signs; once approved only a SysAdmin can reopen it.
        require_once __DIR__ . '/../includes/ics214_log.php';
        $logOwnerId = ics214_owner_of_record($recordId);
        $canSign = $logOwnerId > 0 && $logOwnerId === $me;
        $canEdit = $canSign || $isSysadmin;
        $canReopenApproved = $isSysadmin;
    }
    $creator = $state['creator'];
    $creatorId = (int)($creator['signer_user_id'] ?? 0);
    $approverId = (int)($rec['current_approver_user_id'] ?? 0);
    $label = ics_audit_form_label($code);
    $link = $isPersonalLog
        ? app_url('/forms/ics214/index.php') . '?' . http_build_query(['record_id' => $recordId])
        : app_url('/forms/' . strtolower($code) . '/index.php') . '?' . http_build_query(['incident_id' => $incidentId, 'op_id' => (int)$rec['operational_period_id']]);

    $notify = static function (int $userId, string $type, string $title, string $body, string $urgency = 'routine') use ($pdo, $incidentId, $recordId, $link): void {
        if ($userId <= 0) { return; }
        try {
            $pdo->prepare("INSERT INTO notifications
                (incident_id, user_id, role_code, form_record_id, notification_type, title, subject, body, link_url, related_table, related_id, urgency, is_read, created_at)
                VALUES (?, ?, NULL, ?, ?, ?, ?, ?, ?, 'form_records', ?, ?, 0, NOW())")
                ->execute([$incidentId, $userId, $recordId, $type, $title, $title, $body, $link, $recordId, $urgency]);
        } catch (Throwable $e) { security_log($e, 'signoff_notify'); }
    };
    $actorName = (string)(($user['real_name'] ?? '') ?: ($user['username'] ?? ''));

    if ($action === 'creator_sign') {
        if (!signoff_needs_creator_sign($code)) { throw new RuntimeException('Use the form\'s own Submit button for this form.'); }
        if ($status === 'submitted' || $status === 'approved') { throw new RuntimeException('This form is already signed.'); }
        if ($isPersonalLog && !$canSign) { throw new RuntimeException('Only the owner of an ICS 214 activity log can sign it.'); }
        submit_form_record($recordId, $code, $incidentId, $me);   // throws if the user may not sign (also picks the approver)
        flash('success', $label . ' signed and submitted. Check the approver below.');

    } elseif ($action === 'request') {
        if ($status !== 'submitted') { throw new RuntimeException('Only a signed (submitted) form can be sent for approval.'); }
        if (!($canSign || $canEdit || ($creatorId > 0 && $creatorId === $me))) { throw new RuntimeException('You cannot name an approver for this form.'); }
        $newApprover = (int)($_POST['signoff_approver_id'] ?? 0);
        $ok = false;
        $selfOk = signoff_self_approval_allowed($incidentId, $creatorId);
        foreach (signoff_approver_candidates($incidentId, $selfOk ? 0 : $creatorId) as $c) { if ((int)$c['id'] === $newApprover) { $ok = true; $approverName = (string)$c['name']; break; } }
        if (!$ok) { throw new RuntimeException('Choose an approver who is assigned in this incident (and is not the person who signed the form).'); }
        $pdo->prepare('UPDATE form_records SET current_approver_user_id = ?, updated_at = NOW() WHERE id = ?')->execute([$newApprover, $recordId]);
        audit_log($me, 'request_approval', $code, $recordId, 'Named ' . $approverName . ' as approver of ' . $label . ' (record #' . $recordId . ')');
        $notify($newApprover, 'form_approval_requested', $label . ' needs your approval',
            ($creator['signer_name'] ?? $actorName) . ' signed ' . $label . ' and named you as the approver.', 'high');
        flash('success', 'Sent to ' . $approverName . ' for approval.');

    } elseif ($action === 'approve') {
        if ($status !== 'submitted') { throw new RuntimeException('This form is not waiting for approval.'); }
        if ($approverId <= 0) { throw new RuntimeException('No approver has been named for this form yet.'); }
        if (!($me === $approverId || $isSysadmin)) { throw new RuntimeException('Only the named approver can approve this form.'); }
        $selfSigned = $creatorId > 0 && $creatorId === $me;
        if ($selfSigned && !$isSysadmin && !signoff_self_approval_allowed($incidentId, $me)) { throw new RuntimeException('The person who signed the form cannot also approve it.'); }
        $roles = get_user_role_codes($incidentId, $me);
        $position = $roles ? (string)$roles[0] : ($isSysadmin ? 'SYSADMIN' : 'AUTHORIZED');
        $pdo->beginTransaction();
        $pdo->prepare("DELETE FROM form_signatures WHERE form_record_id = ? AND signature_type = 'approved_by'")->execute([$recordId]);
        $pdo->prepare("INSERT INTO form_signatures (form_record_id, signer_user_id, signer_name, signer_position_title, signature_type, signature_method, signed_at, created_at)
            VALUES (?, ?, ?, ?, 'approved_by', 'system_attestation', NOW(), NOW())")->execute([$recordId, $me, $actorName, $position]);
        $pdo->prepare("UPDATE form_records SET status = 'approved', approved_at = NOW(), current_approver_user_id = ?, updated_at = NOW() WHERE id = ?")->execute([$me, $recordId]);
        // Fill the approval block of the ICS 209 forms when it is still empty.
        if (in_array($code, ['ICS209', 'ICS209WF'], true)) {
            $cols = table_columns('form_209');
            if (in_array('approved_by_name', $cols, true) && in_array('approved_datetime', $cols, true)) {
                $pdo->prepare("UPDATE form_209 SET approved_by_name = IF(COALESCE(approved_by_name, '') = '', ?, approved_by_name),
                    approved_datetime = IF(COALESCE(approved_datetime, '') = '', ?, approved_datetime) WHERE record_id = ?")->execute([$actorName, date('Y-m-d H:i'), $recordId]);
            }
        }
        $pdo->commit();
        audit_log($me, 'approve', $code, $recordId, 'Approved and digitally signed ' . $label . ' (record #' . $recordId . ') as ' . $position . ($selfSigned ? ' (self-approval: same person signed and approved)' : ''));
        if ($creatorId > 0 && $creatorId !== $me) {
            $notify($creatorId, 'form_approved', $label . ' approved', $actorName . ' approved ' . $label . '.');
        }
        flash('success', $label . ' approved and signed.');

    } else {
        throw new RuntimeException('Unknown sign-off action.');
    }
} catch (Throwable $e) {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    flash('error', $e->getMessage());
}
$back();
