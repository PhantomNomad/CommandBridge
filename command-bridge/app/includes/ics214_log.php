<?php
declare(strict_types=1);

/**
 * Per-person ICS 214 Activity Logs.
 *
 * Every person has their own ICS 214 for each operational period: a form_records row (form_type ICS214) whose
 * form_214_headers row carries the owner in user_id. The first one is created when their login is created; later ones
 * are created the first time the person (or someone allowed to add to it) uses it in a new operational period.
 *
 * Entries are never edited or deleted. Mistakes are corrected with a new entry. Each entry records who added it.
 *
 * Who may do what (ics214_access):
 *   owner                      view, add entries, edit the header, sign and submit
 *   IC / UC, SysAdmin view, add entries
 *   the owner's supervisor     view, add entries (holder of the position the owner's position reports to)
 *   DOCL                       view, ask the owner to update it ("Request update")
 * A log is open for entries only while it is a draft (or returned). Once signed it is locked; once the supervisor
 * approves it, it stays locked unless it is returned for changes.
 *
 * Automatic entries (ics214_rows): the owner's own actions and account events about the owner (from audit_log), and
 * meetings marked "completed" in the operational period.
 */

require_once __DIR__ . '/ics214_activity.php';

const ICS214_COMMAND_ROLES = ['IC', 'UC'];

/** @return string[] upper-case position codes the user holds in the incident */
function ics214_role_codes(int $incidentId, int $userId): array
{
    if ($incidentId <= 0 || $userId <= 0) { return []; }
    try {
        return array_values(array_unique(array_map('strtoupper', get_user_role_codes($incidentId, $userId))));
    } catch (Throwable $e) {
        return [];
    }
}

/** Users holding the position(s) the owner's position(s) report to, excluding the owner. @return int[] */
function ics214_supervisor_ids(int $incidentId, int $ownerId, int $opId): array
{
    if (!function_exists('signoff_reports_to_map')) { require_once __DIR__ . '/signoff.php'; }
    $map = signoff_reports_to_map();
    $ids = [];
    foreach (ics214_role_codes($incidentId, $ownerId) as $code) {
        $boss = $map[$code] ?? '';
        if ($boss !== '') {
            foreach (signoff_position_holders($incidentId, $boss, $opId) as $id) { $ids[$id] = true; }
        }
    }
    unset($ids[$ownerId]);
    return array_map('intval', array_keys($ids));
}

/**
 * What the signed-in user may do with $ownerId's log.
 * @return array{view:bool, add:bool, edit_header:bool, request_update:bool, list:bool, as:string}
 */
function ics214_access(int $incidentId, int $ownerId, int $opId): array
{
    $none = ['view' => false, 'add' => false, 'edit_header' => false, 'request_update' => false, 'list' => false, 'as' => ''];
    $me = current_user();
    $myId = (int)($me['id'] ?? 0);
    if ($myId <= 0 || $incidentId <= 0 || $ownerId <= 0) { return $none; }

    $myRoles = ics214_role_codes($incidentId, $myId);
    $isSysadmin = !empty($me['is_sysadmin']);
    $isCommand = (bool)array_intersect($myRoles, ICS214_COMMAND_ROLES);
    $isDocl = in_array('DOCL', $myRoles, true);
    $canList = $isSysadmin || $isCommand || $isDocl || ics214_supervises_anyone($incidentId, $myId, $opId);

    if ($ownerId === $myId) {
        $perms = get_current_user_form_permissions('ICS214', $incidentId);
        $own = !empty($perms['can_view']) || $isSysadmin;
        return ['view' => $own, 'add' => $own, 'edit_header' => $own, 'request_update' => false, 'list' => $canList, 'as' => 'owner'];
    }
    if ($isSysadmin || $isCommand) {
        return ['view' => true, 'add' => true, 'edit_header' => false, 'request_update' => true, 'list' => true, 'as' => $isSysadmin ? 'sysadmin' : 'command'];
    }
    if (in_array($myId, ics214_supervisor_ids($incidentId, $ownerId, $opId), true)) {
        return ['view' => true, 'add' => true, 'edit_header' => false, 'request_update' => true, 'list' => true, 'as' => 'supervisor'];
    }
    if ($isDocl) {
        return ['view' => true, 'add' => false, 'edit_header' => false, 'request_update' => true, 'list' => true, 'as' => 'docl'];
    }
    return array_merge($none, ['list' => $canList]);
}

/** True when anyone in the incident reports to a position the user holds. */
function ics214_supervises_anyone(int $incidentId, int $userId, int $opId): bool
{
    if (!function_exists('signoff_reports_to_map')) { require_once __DIR__ . '/signoff.php'; }
    $mine = ics214_role_codes($incidentId, $userId);
    if (!$mine) { return false; }
    foreach (signoff_reports_to_map() as $code => $boss) {
        if ($boss !== '' && in_array($boss, $mine, true) && signoff_position_holders($incidentId, $code, $opId)) { return true; }
    }
    return false;
}

/** The owner's log for the operational period (form_records row plus header fields), or null. */
function ics214_find_log(int $incidentId, int $opId, int $ownerId): ?array
{
    if ($incidentId <= 0 || $opId <= 0 || $ownerId <= 0) { return null; }
    $st = db()->prepare("SELECT fr.*, h.id AS header_id, h.user_id AS owner_user_id, h.personnel_name, h.ICS_position_title,
            h.home_agency, h.unit_name, h.incident_name AS header_incident_name, h.date_time_prepared
        FROM form_records fr
        INNER JOIN form_214_headers h ON h.form_record_id = fr.id
        WHERE fr.incident_id = ? AND fr.operational_period_id = ? AND h.user_id = ? AND fr.is_deleted = 0
          AND UPPER(REPLACE(fr.form_type, ' ', '')) = 'ICS214'
        ORDER BY fr.id DESC LIMIT 1");
    $st->execute([$incidentId, $opId, $ownerId]);
    $row = $st->fetch();
    return $row ?: null;
}

/** A log row by form record id (any owner), or null. */
function ics214_find_log_by_record(int $incidentId, int $recordId): ?array
{
    if ($incidentId <= 0 || $recordId <= 0) { return null; }
    $st = db()->prepare("SELECT fr.*, h.id AS header_id, COALESCE(h.user_id, fr.prepared_by_user_id) AS owner_user_id, h.personnel_name,
            h.ICS_position_title, h.home_agency, h.unit_name, h.incident_name AS header_incident_name, h.date_time_prepared
        FROM form_records fr
        LEFT JOIN form_214_headers h ON h.form_record_id = fr.id
        WHERE fr.id = ? AND fr.incident_id = ? AND fr.is_deleted = 0 AND UPPER(REPLACE(fr.form_type, ' ', '')) = 'ICS214'
        LIMIT 1");
    $st->execute([$recordId, $incidentId]);
    $row = $st->fetch();
    return $row ?: null;
}

/** Owner of an ICS 214 record (0 if unknown). Used by the sign-off so only the owner can sign their own log. */
function ics214_owner_of_record(int $recordId): int
{
    try {
        $st = db()->prepare("SELECT COALESCE(h.user_id, fr.prepared_by_user_id) FROM form_records fr
            LEFT JOIN form_214_headers h ON h.form_record_id = fr.id WHERE fr.id = ? LIMIT 1");
        $st->execute([$recordId]);
        return (int)$st->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}

/** Name and current position title of a user. @return array{name:string, position:string} */
function ics214_person(int $incidentId, int $userId): array
{
    $name = '';
    $position = '';
    try {
        $st = db()->prepare("SELECT COALESCE(NULLIF(real_name, ''), username) FROM users WHERE id = ?");
        $st->execute([$userId]);
        $name = (string)$st->fetchColumn();
        $st = db()->prepare("SELECT r.role_name FROM incident_positions ip INNER JOIN roles r ON r.id = ip.role_id
            WHERE ip.incident_id = ? AND ip.user_id = ? AND ip.deleted_at IS NULL AND (ip.assigned_end IS NULL OR ip.assigned_end >= NOW())
            ORDER BY ip.assigned_start DESC, ip.id DESC LIMIT 1");
        $st->execute([$incidentId, $userId]);
        $position = (string)($st->fetchColumn() ?: '');
    } catch (Throwable $e) {}
    return ['name' => $name, 'position' => $position];
}

/** Find the owner's log for the period, creating it if needed. Returns the form record id (0 if it cannot be made). */
function ics214_ensure_log(int $incidentId, int $opId, int $ownerId, int $createdBy): int
{
    $existing = ics214_find_log($incidentId, $opId, $ownerId);
    if ($existing) { return (int)$existing['id']; }
    if ($incidentId <= 0 || $opId <= 0 || $ownerId <= 0) { return 0; }

    $pdo = db();
    $person = ics214_person($incidentId, $ownerId);
    $incidentName = '';
    try {
        $st = $pdo->prepare('SELECT incident_name FROM incidents WHERE id = ?');
        $st->execute([$incidentId]);
        $incidentName = (string)$st->fetchColumn();
    } catch (Throwable $e) {}

    $ownTransaction = !$pdo->inTransaction();
    if ($ownTransaction) { $pdo->beginTransaction(); }
    try {
        $pdo->prepare("INSERT INTO form_records (incident_id, operational_period_id, form_type, form_number, status,
                prepared_by_user_id, prepared_by_name, prepared_by_position_title, created_at, updated_at)
            VALUES (?, ?, 'ICS214', '214', 'draft', ?, ?, ?, NOW(), NOW())")
            ->execute([$incidentId, $opId, $ownerId, $person['name'] ?: null, $person['position'] ?: null]);
        $recordId = (int)$pdo->lastInsertId();
        $pdo->prepare("INSERT INTO form_214_headers (form_record_id, user_id, operational_period_id, incident_name, personnel_name,
                ICS_position_title, prepared_by_name, prepared_by_position_title)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)")
            ->execute([$recordId, $ownerId, $opId, $incidentName ?: null, $person['name'] ?: null, $person['position'] ?: null,
                $person['name'] ?: null, $person['position'] ?: null]);
        if ($ownTransaction) { $pdo->commit(); }
    } catch (Throwable $e) {
        if ($ownTransaction && $pdo->inTransaction()) { $pdo->rollBack(); }
        throw $e;
    }
    audit_log($createdBy ?: null, 'ics214_log_started', 'ICS214', $recordId,
        'Started the ICS 214 activity log of ' . ($person['name'] ?: ('user #' . $ownerId)) . ' for operational period #' . $opId);
    return $recordId;
}

/** Make sure a newly created user has a log for the current operational period (called when the login is created). */
function ics214_start_log_for_new_user(int $incidentId, int $userId, int $createdBy): void
{
    try {
        $op = current_operational_period($incidentId);
        if ($op) { ics214_ensure_log($incidentId, (int)$op['id'], $userId, $createdBy); }
    } catch (Throwable $e) {
        security_log($e, 'ics214_start_log_for_new_user');
    }
}

/** True while entries may be added (draft, or returned for changes). */
function ics214_is_open(?array $log): bool
{
    $status = strtolower((string)($log['status'] ?? 'draft'));
    return $log === null || in_array($status, ['draft', 'rejected', ''], true);
}

/**
 * Append an entry. Never updates or deletes existing ones.
 * @throws InvalidArgumentException on empty text or a bad time
 */
function ics214_add_entry(int $recordId, string $time, string $text, int $authorId, string $authorName): void
{
    $text = trim($text);
    if ($text === '') { throw new InvalidArgumentException('Type what happened.'); }
    if (mb_strlen($text) > 4000) { throw new InvalidArgumentException('Keep each entry under 4,000 characters.'); }
    $time = trim($time);
    if ($time === '') {
        $time = date('Y-m-d H:i');
    } elseif (!preg_match('/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}$/', $time) || strtotime($time) === false) {
        throw new InvalidArgumentException('Enter the time as YYYY-MM-DD HH:MM, or leave it blank for now.');
    }
    $pdo = db();
    $st = $pdo->prepare('SELECT COALESCE(MAX(row_order), 0) + 1 FROM form_214_activity_log WHERE form_record_id = ?');
    $st->execute([$recordId]);
    $next = (int)$st->fetchColumn();
    $pdo->prepare("INSERT INTO form_214_activity_log (form_record_id, row_order, activity_time, activity_text, source_type, added_by_user_id, added_by_name)
        VALUES (?, ?, ?, ?, 'manual', ?, ?)")
        ->execute([$recordId, $next, date('Y-m-d H:i:s', (int)strtotime($time)), $text, $authorId ?: null, $authorName !== '' ? $authorName : null]);
    $pdo->prepare('UPDATE form_records SET updated_at = NOW() WHERE id = ?')->execute([$recordId]);
}

/** Meetings marked completed in the operational period. */
function ics214_meeting_rows(int $incidentId, int $opId): array
{
    $out = [];
    try {
        $st = db()->prepare("SELECT m.scheduled_at, m.title, mt.name AS type_name FROM operational_period_meetings m
            LEFT JOIN meeting_types mt ON mt.id = m.meeting_type_id
            WHERE m.incident_id = ? AND m.operational_period_id = ? AND m.deleted_at IS NULL AND LOWER(m.status) = 'completed'
            ORDER BY m.scheduled_at ASC");
        $st->execute([$incidentId, $opId]);
        foreach ($st->fetchAll() as $r) {
            $out[] = ['time' => date('Y-m-d H:i', (int)strtotime((string)$r['scheduled_at'])),
                'text' => 'Meeting held: ' . trim((string)($r['title'] ?: $r['type_name'] ?: 'Meeting')),
                'source' => 'Meeting', 'cat' => 'meeting', 'manual' => false, 'by' => ''];
        }
    } catch (Throwable $e) {}
    return $out;
}

/** Entries typed into the log, with who added them. */
function ics214_manual_rows(int $recordId): array
{
    if ($recordId <= 0) { return []; }
    $out = [];
    try {
        $st = db()->prepare("SELECT l.activity_time, l.activity_text, l.created_at, l.added_by_user_id, l.added_by_name,
                COALESCE(NULLIF(u.real_name, ''), u.username) AS added_by_current
            FROM form_214_activity_log l LEFT JOIN users u ON u.id = l.added_by_user_id
            WHERE l.form_record_id = ? AND l.source_type = 'manual' ORDER BY l.activity_time, l.row_order, l.id");
        $st->execute([$recordId]);
        foreach ($st->fetchAll() as $r) {
            $by = trim((string)($r['added_by_name'] ?: $r['added_by_current'] ?: ''));
            $out[] = ['time' => $r['activity_time'] ? date('Y-m-d H:i', (int)strtotime((string)$r['activity_time'])) : '',
                'text' => (string)$r['activity_text'], 'source' => 'Entry', 'cat' => 'entry', 'manual' => true,
                'by' => $by, 'added_at' => $r['created_at'] ? date('Y-m-d H:i', (int)strtotime((string)$r['created_at'])) : ''];
        }
    } catch (Throwable $e) {}
    return $out;
}

/** Everything shown on a person's log for the period, oldest first. */
function ics214_rows(int $incidentId, ?array $op, int $ownerId, int $recordId): array
{
    $rows = [];
    foreach (ics214_audit_rows(db(), $incidentId, $op, $ownerId) as $a) {
        $rows[] = ['time' => $a['time'], 'text' => $a['text'], 'source' => $a['source'], 'cat' => $a['cat'], 'manual' => false, 'by' => ''];
    }
    $rows = array_merge($rows, ics214_meeting_rows($incidentId, (int)($op['id'] ?? 0)), ics214_manual_rows($recordId));
    usort($rows, static function (array $a, array $b): int {
        if ($a['time'] === $b['time']) { return strcmp((string)$a['source'], (string)$b['source']); }
        if ($a['time'] === '') { return 1; }
        if ($b['time'] === '') { return -1; }
        return strcmp($a['time'], $b['time']);
    });
    return $rows;
}

/**
 * People whose logs the user may open from the Activity Logs list for the period.
 * @return array<int, array{user_id:int, name:string, positions:string, record_id:int, status:string, updated_at:string, entries:int}>
 */
function ics214_list_people(int $incidentId, int $opId): array
{
    $pdo = db();
    $people = [];
    try {
        $st = $pdo->prepare("SELECT u.id, COALESCE(NULLIF(u.real_name, ''), u.username) AS name,
                GROUP_CONCAT(DISTINCT r.role_code ORDER BY r.role_code SEPARATOR ', ') AS positions
            FROM incident_positions ip
            INNER JOIN users u ON u.id = ip.user_id
            INNER JOIN roles r ON r.id = ip.role_id
            WHERE ip.incident_id = ? AND ip.deleted_at IS NULL AND (ip.assigned_end IS NULL OR ip.assigned_end >= NOW())
              AND u.deleted_at IS NULL
            GROUP BY u.id, name");
        $st->execute([$incidentId]);
        foreach ($st->fetchAll() as $r) {
            $people[(int)$r['id']] = ['user_id' => (int)$r['id'], 'name' => (string)$r['name'], 'positions' => (string)$r['positions'],
                'record_id' => 0, 'status' => 'not_started', 'updated_at' => '', 'entries' => 0];
        }
        $st = $pdo->prepare("SELECT h.user_id, COALESCE(NULLIF(u.real_name, ''), u.username) AS name, fr.id, fr.status, fr.updated_at,
                (SELECT COUNT(*) FROM form_214_activity_log l WHERE l.form_record_id = fr.id AND l.source_type = 'manual') AS entries
            FROM form_records fr
            INNER JOIN form_214_headers h ON h.form_record_id = fr.id
            INNER JOIN users u ON u.id = h.user_id
            WHERE fr.incident_id = ? AND fr.operational_period_id = ? AND fr.is_deleted = 0 AND UPPER(REPLACE(fr.form_type, ' ', '')) = 'ICS214'
            ORDER BY fr.id ASC");
        $st->execute([$incidentId, $opId]);
        foreach ($st->fetchAll() as $r) {
            $uid = (int)$r['user_id'];
            $people[$uid] = ($people[$uid] ?? ['user_id' => $uid, 'name' => (string)$r['name'], 'positions' => '']) + [];
            $people[$uid]['record_id'] = (int)$r['id'];
            $people[$uid]['status'] = (string)$r['status'];
            $people[$uid]['updated_at'] = (string)$r['updated_at'];
            $people[$uid]['entries'] = (int)$r['entries'];
        }
    } catch (Throwable $e) {
        security_log($e, 'ics214_list_people');
    }
    $me = (int)(current_user()['id'] ?? 0);
    $out = array_values(array_filter($people, static fn(array $p): bool => $p['user_id'] === $me || ics214_access($incidentId, $p['user_id'], $opId)['view']));
    usort($out, static fn(array $a, array $b): int => strcasecmp($a['name'], $b['name']));
    return $out;
}

/** Page URL for a person's log. */
function ics214_url(int $ownerId = 0, int $opId = 0, int $recordId = 0): string
{
    $q = [];
    if ($recordId > 0) { $q['record_id'] = $recordId; }
    elseif ($ownerId > 0) { $q['user_id'] = $ownerId; }
    if ($opId > 0 && $recordId <= 0) { $q['op_id'] = $opId; }
    return app_url('/forms/ics214/index.php') . ($q ? '?' . http_build_query($q) : '');
}
