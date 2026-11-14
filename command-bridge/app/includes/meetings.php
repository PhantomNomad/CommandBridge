<?php
declare(strict_types=1);
/*
 * Meeting helpers (ICS 230 schedule).
 *
 * A meeting counts as held only when someone marks it "completed" on the ICS 230. When a meeting is still
 * "scheduled" or "tentative" an hour after its start time, the chair, the Incident Commander (or Unified Command)
 * and the Planning Section Chief get a reminder to mark it completed or cancelled. The reminder disappears from
 * the dashboard as soon as the meeting's status changes.
 */

const CB_MEETING_REMINDER_AFTER_MINUTES = 60;
const CB_MEETING_REMINDER_ROLES = ['IC', 'UC', 'PSC'];

/** People to remind about one meeting: its chair plus the IC/UC and PSC holders (active users only). */
function cb_meeting_reminder_recipients(int $incidentId, array $meeting): array
{
    $ids = [];
    $chair = (int)($meeting['chair_user_id'] ?? 0);
    if ($chair > 0) { $ids[] = $chair; }
    try {
        $st = db()->prepare("SELECT DISTINCT u.id FROM incident_positions ip
            INNER JOIN roles r ON r.id = ip.role_id INNER JOIN users u ON u.id = ip.user_id
            WHERE ip.incident_id = ? AND r.role_code IN ('" . implode("','", CB_MEETING_REMINDER_ROLES) . "')
              AND ip.deleted_at IS NULL AND (ip.assigned_end IS NULL OR ip.assigned_end >= NOW())
              AND u.is_active = 1 AND u.deleted_at IS NULL");
        $st->execute([$incidentId]);
        foreach ($st->fetchAll() as $r) { $ids[] = (int)$r['id']; }
    } catch (Throwable $e) {
        security_log($e, 'meeting_reminder_recipients');
    }
    return array_values(array_unique(array_filter($ids)));
}

/** Meeting statuses that mean the meeting is still to happen (and can still get a reminder). */
const CB_MEETING_OPEN_STATUSES = ['scheduled', 'tentative'];

/** Loads one meeting of an incident (not deleted), or null. */
function cb_meeting_load(int $incidentId, int $meetingId): ?array
{
    if ($incidentId <= 0 || $meetingId <= 0) { return null; }
    $st = db()->prepare("SELECT m.*, mt.name AS meeting_type_name, op.period_number
        FROM operational_period_meetings m
        LEFT JOIN meeting_types mt ON mt.id = m.meeting_type_id
        LEFT JOIN operational_periods op ON op.id = m.operational_period_id
        WHERE m.id = ? AND m.incident_id = ? AND m.deleted_at IS NULL LIMIT 1");
    $st->execute([$meetingId, $incidentId]);
    $row = $st->fetch();
    return $row ?: null;
}

/**
 * Who may record whether a meeting was held (completed / cancelled): anyone who can edit the ICS 230, the
 * people who get the reminder (chair, IC/UC, PSC) and a SysAdmin. This works even after the ICS 230 is
 * signed, because it records what happened rather than changing the plan.
 */
function cb_meeting_user_can_set_status(int $incidentId, array $meeting): bool
{
    $user = current_user();
    if (!$user) { return false; }
    if (!empty($user['is_sysadmin'])) { return true; }
    $perms = get_current_user_form_permissions('ICS230', $incidentId);
    if (!empty($perms['can_edit'])) { return true; }
    return in_array((int)$user['id'], cb_meeting_reminder_recipients($incidentId, $meeting), true);
}

/** Marks a meeting completed or cancelled. Returns false if the status is not allowed. */
function cb_meeting_set_status(int $incidentId, array $meeting, string $status, string $note = ''): bool
{
    if (!in_array($status, ['completed', 'cancelled'], true)) { return false; }
    $userId = (int)(current_user()['id'] ?? 0);
    $note = trim($note);
    $notes = (string)($meeting['notes'] ?? '');
    if ($note !== '') {
        $notes = trim($notes . "\n" . ($status === 'completed' ? 'Held' : 'Cancelled') . ': ' . $note);
    }
    $st = db()->prepare("UPDATE operational_period_meetings SET status = ?, notes = ?, updated_by_user_id = ?, updated_at = NOW()
        WHERE id = ? AND incident_id = ? AND deleted_at IS NULL");
    $st->execute([$status, $notes !== '' ? $notes : null, $userId ?: null, (int)$meeting['id'], $incidentId]);
    audit_log($userId ?: null, 'meeting_' . $status, 'operational_period_meetings', (int)$meeting['id'],
        'Meeting "' . (string)$meeting['title'] . '" (' . (string)$meeting['scheduled_at'] . ') marked ' . $status
        . ($note !== '' ? ': ' . $note : ''));
    return true;
}

/** Link to the page that records whether a meeting was held. */
function cb_meeting_status_url(int $meetingId): string
{
    return app_url('/forms/ics230/meeting_status.php') . '?' . http_build_query(['meeting_id' => $meetingId]);
}

/**
 * Sends "mark the meeting" reminders for meetings that started more than an hour ago (within the last day)
 * and are still scheduled/tentative. Each person is reminded once per meeting. Returns how many were created.
 * Cheap enough to call on every dashboard load.
 */
function cb_meeting_reminders_run(int $incidentId): int
{
    if ($incidentId <= 0) { return 0; }
    $pdo = db();
    $created = 0;
    try {
        $st = $pdo->prepare("SELECT m.id, m.operational_period_id, m.title, m.scheduled_at, m.chair_user_id, m.status
            FROM operational_period_meetings m
            WHERE m.incident_id = ? AND m.deleted_at IS NULL AND LOWER(m.status) IN ('scheduled', 'tentative')
              AND m.scheduled_at <= DATE_SUB(NOW(), INTERVAL " . (int)CB_MEETING_REMINDER_AFTER_MINUTES . " MINUTE)
              AND m.scheduled_at >= DATE_SUB(NOW(), INTERVAL 1 DAY)
            ORDER BY m.scheduled_at ASC LIMIT 50");
        $st->execute([$incidentId]);
        $meetings = $st->fetchAll();
        if (!$meetings) { return 0; }

        $exists = $pdo->prepare("SELECT 1 FROM notifications WHERE incident_id = ? AND user_id = ? AND notification_type = 'meeting_status_reminder'
            AND related_table = 'operational_period_meetings' AND related_id = ? LIMIT 1");
        $insert = $pdo->prepare("INSERT INTO notifications (incident_id, user_id, role_code, form_record_id, notification_type, title, subject, body,
                link_url, related_table, related_id, urgency, is_read, created_at)
            VALUES (?, ?, NULL, NULL, 'meeting_status_reminder', ?, ?, ?, ?, 'operational_period_meetings', ?, 'routine', 0, NOW())");
        foreach ($meetings as $m) {
            $mid = (int)$m['id'];
            $title = 'Was this meeting held? ' . mb_substr(trim((string)$m['title']), 0, 180);
            $body = trim((string)$m['title']) . ' was scheduled for ' . date('Y-m-d H:i', (int)strtotime((string)$m['scheduled_at']))
                . ' and is still marked ' . strtolower((string)$m['status']) . '. Open this reminder to mark it completed (held) or cancelled.'
                . ' Only completed meetings are shown as "Meeting held" on everyone\'s ICS 214.';
            $link = cb_meeting_status_url($mid);
            foreach (cb_meeting_reminder_recipients($incidentId, $m) as $uid) {
                $exists->execute([$incidentId, $uid, $mid]);
                if ($exists->fetchColumn()) { continue; }
                $insert->execute([$incidentId, $uid, $title, $title, $body, $link, $mid]);
                $created++;
            }
        }
    } catch (Throwable $e) {
        security_log($e, 'meeting_reminders');
    }
    return $created;
}
