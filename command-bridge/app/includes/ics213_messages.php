<?php
declare(strict_types=1);
/*
 * ICS 213 General Message: addressing and notifications.
 *  - "To" can be picked from the positions filled in the incident. Everyone holding that position gets a dashboard
 *    notification (one each, so each person's read/unread is their own).
 *  - When the message is answered, the sender is notified.
 *  - Opening the message marks your own notification for it as read.
 * Columns form_213_messages.to_role_code and from_user_id come from migration 0007.
 */

/** Positions currently filled in the incident, for the "To" list: [role_code => ['title' => ..., 'names' => [...]]]. */
function ics213_filled_positions(int $incidentId): array
{
    if ($incidentId <= 0) { return []; }
    try {
        $st = db()->prepare("SELECT r.role_code, r.role_name, COALESCE(NULLIF(u.real_name, ''), u.username) AS person
            FROM incident_positions ip
            INNER JOIN roles r ON r.id = ip.role_id
            INNER JOIN users u ON u.id = ip.user_id
            WHERE ip.incident_id = ? AND ip.deleted_at IS NULL AND (ip.assigned_end IS NULL OR ip.assigned_end >= NOW())
              AND u.is_active = 1 AND u.deleted_at IS NULL AND r.role_code <> 'SYSADMIN'
            ORDER BY r.role_name, person");
        $st->execute([$incidentId]);
        $out = [];
        foreach ($st->fetchAll() as $r) {
            $code = strtoupper((string)$r['role_code']);
            $out[$code]['title'] = (string)$r['role_name'];
            $out[$code]['names'][] = (string)$r['person'];
        }
        return $out;
    } catch (Throwable $e) { return []; }
}

function ics213_message_url(int $opId, int $messageId): string
{
    return app_url('/forms/ics213/index.php') . '?' . http_build_query(['operational_period_id' => $opId, 'message_id' => $messageId]);
}

/** One dashboard notification per person. */
function ics213_notify_users(int $incidentId, array $userIds, int $messageId, string $title, string $body, string $link, string $type): void
{
    $me = (int)(current_user()['id'] ?? 0);
    $st = db()->prepare("INSERT INTO notifications (incident_id, user_id, role_code, form_record_id, notification_type, title, subject, body, link_url,
            related_table, related_id, urgency, is_read, created_at)
        VALUES (?, ?, NULL, NULL, ?, ?, ?, ?, ?, 'form_213_messages', ?, 'high', 0, NOW())");
    foreach (array_unique(array_map('intval', $userIds)) as $uid) {
        if ($uid <= 0 || $uid === $me) { continue; }
        try { $st->execute([$incidentId, $uid, $type, $title, $title, $body, $link, $messageId]); }
        catch (Throwable $e) { security_log($e, 'ics213_notify'); }
    }
}

/** New message: tell everyone holding the "To" position. */
function ics213_notify_new_message(int $incidentId, int $opId, int $messageId, string $toRoleCode, string $fromName, string $subject, string $message): void
{
    if ($toRoleCode === '') { return; }
    require_once __DIR__ . '/signoff.php';
    $holders = signoff_position_holders($incidentId, strtoupper($toRoleCode));
    $title = 'ICS 213 message for you' . ($subject !== '' ? ': ' . mb_substr($subject, 0, 120) : '');
    $body = 'From ' . ($fromName !== '' ? $fromName : 'someone on the incident') . ': ' . mb_substr(preg_replace('/\s+/', ' ', $message) ?? '', 0, 300);
    ics213_notify_users($incidentId, $holders, $messageId, $title, $body, ics213_message_url($opId, $messageId), 'ics213_message');
}

/** First reply: tell the sender. */
function ics213_notify_reply(int $incidentId, int $opId, array $msg, string $replyBy, string $reply): void
{
    $sender = (int)($msg['from_user_id'] ?? 0);
    if ($sender <= 0) { return; }
    $title = 'Reply to your ICS 213' . (!empty($msg['subject']) ? ': ' . mb_substr((string)$msg['subject'], 0, 120) : '');
    $body = ($replyBy !== '' ? $replyBy : 'The addressee') . ' replied: ' . mb_substr(preg_replace('/\s+/', ' ', $reply) ?? '', 0, 300);
    ics213_notify_users($incidentId, [$sender], (int)$msg['id'], $title, $body, ics213_message_url($opId, (int)$msg['id']), 'ics213_reply');
}

/** Opening a message clears the signed-in person's own notifications about it. */
function ics213_mark_read(int $messageId): void
{
    $me = (int)(current_user()['id'] ?? 0);
    if ($messageId <= 0 || $me <= 0) { return; }
    try {
        db()->prepare("UPDATE notifications SET is_read = 1, read_at = COALESCE(read_at, NOW())
            WHERE related_table = 'form_213_messages' AND related_id = ? AND user_id = ? AND is_read = 0")->execute([$messageId, $me]);
    } catch (Throwable $e) { /* not important */ }
}
