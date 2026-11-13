<?php
declare(strict_types=1);

/**
 * Automatic ICS 214 activity entries, built from the audit trail (audit_log).
 *
 * Every form save/submission, account change (new login, PIN/password reset, own PIN/password change, unlock,
 * position changes), sign-in/sign-out and settings change is already recorded in audit_log with who, what and when.
 * The ICS 214 page and PDF show those entries for the operational period as plain sentences. Nothing here ever
 * contains a PIN or password: the audit trail only records that a change happened.
 */

if (!function_exists('ics214_audit_category')) {
    /** @return string form|account|signin|system */
    function ics214_audit_category(string $action, string $entityType): string
    {
        $action = strtolower($action);
        $entity = strtoupper($entityType);
        if (in_array($action, ['login', 'logout', 'login_failed'], true)) { return 'signin'; }
        if ($entity === 'USER' || in_array($action, ['account_locked', 'unlock_user', 'reset_pin', 'create_user'], true)
            || str_starts_with($action, 'change_own_') || str_contains($action, 'companion_checkin')) { return 'account'; }
        if (str_starts_with($entity, 'ICS') || $entity === 'FORM_RECORD' || in_array($entity, ['FINANCE', 'RESOURCES', 'EXTERNAL_CHECKIN'], true)
            || str_contains($action, 'form') || in_array($action, ['operational_period_assignments_updated', 'create_operational_period', 'operational_period_updated'], true)
            || $entity === 'OPERATIONAL_PERIOD') { return 'form'; }
        return 'system';
    }
}

if (!function_exists('ics214_audit_sentence')) {
    function ics214_audit_sentence(array $r, string $actor, string $target): string
    {
        $action = strtolower((string)$r['action_type']);
        $entity = (string)$r['entity_type'];
        $detail = trim((string)($r['action_detail'] ?? ''));
        $redundant = ['Successful login', 'User changed their own PIN', 'User changed their own password', 'Signed out', 'Created user from admin/users.php'];
        $detail = trim(str_replace('Updated user and role assignments', '', $detail));
        $suffix = ($detail !== '' && !in_array($detail, $redundant, true)) ? ' - ' . $detail : '';
        $t = $target !== '' ? $target : 'a user';

        switch ($action) {
            case 'login': return "$actor signed in" . ($detail !== '' && preg_match('/from (\S+)$/', $detail, $m) ? ' from ' . $m[1] : '');
            case 'logout': return "$actor signed out";
            case 'login_failed': return $detail !== '' ? $detail . ($target !== '' ? " (account: $target)" : '') : 'Failed sign-in attempt';
            case 'account_locked': return "Account for $t was locked" . $suffix;
            case 'unlock_user': return "$actor unlocked the account for $t";
            case 'create_user': return "$actor created a new login for $t" . $suffix;
            case 'reset_pin': return "$actor reset the PIN for $t (the new PIN is not recorded; the user must choose their own at next sign-in)";
            case 'change_own_pin': return "$actor changed their own PIN (the new PIN is not recorded)";
            case 'change_own_password': return "$actor changed their own password (the new password is not recorded)";
            case 'update_user': return "$actor updated the account for $t" . $suffix;
            case 'assign_user_to_operational_period': return "$actor assigned $t to an operational period" . $suffix;
            case 'enable_companion_checkin_access': return "$actor enabled Companion Check-In access for $t";
            case 'revoke_companion_checkin_access': return "$actor revoked Companion Check-In access for $t";
            case 'ics214_log_started':
            case 'ics214_entry_added':
            case 'ics214_update_requested':
                return $detail !== '' ? "$actor " . lcfirst($detail) : "$actor: " . str_replace('_', ' ', $action);
        }

        $isForm = str_starts_with(strtoupper($entity), 'ICS') || in_array(strtoupper($entity), ['FINANCE', 'RESOURCES', 'EXTERNAL_CHECKIN'], true);
        if ($isForm && function_exists('ics_audit_form_label')) {
            $label = ics_audit_form_label($entity);
            // The recorded detail is already a full sentence ("Saved ICS 209 (record #21); ...").
            if (in_array($action, ['submit', 'approve', 'update', 'delete'], true) && $detail !== '') { return "$actor: $detail"; }
            switch ($action) {
                case 'submit': return "$actor submitted $label";
                case 'approve': return "$actor approved $label";
                case 'update': return "$actor saved changes to $label";
                case 'delete': return "$actor deleted an entry on $label";
                case 'upload_attachment': return "$actor uploaded an attachment to $label" . $suffix;
                case 'delete_attachment': return "$actor deleted an attachment from $label" . $suffix;
            }
        }
        return $actor . ': ' . str_replace('_', ' ', $action) . ($entity !== '' && strtolower($entity) !== 'system' ? ' (' . str_replace('_', ' ', $entity) . ')' : '') . $suffix;
    }
}

if (!function_exists('ics214_audit_rows')) {
    /**
     * @param array<string,mixed>|null $op operational period row (op_start / op_end) bounding the log
     * @param int|null $ownerId only this person's own actions and account events about them (per-person ICS 214)
     * @return array<int, array{time: string, text: string, source: string, cat: string}>
     */
    function ics214_audit_rows(PDO $pdo, int $incidentId, ?array $op = null, ?int $ownerId = null): array
    {
        $params = [$incidentId];
        $where = '(a.incident_id = ? OR a.incident_id IS NULL)';
        if ($ownerId !== null) {
            if ($ownerId <= 0) { return []; }
            $where .= " AND (a.user_id = ? OR (a.entity_type = 'user' AND a.entity_id = ?))";
            $params[] = $ownerId;
            $params[] = $ownerId;
        }
        $start = trim((string)($op['op_start'] ?? ''));
        $end = trim((string)($op['op_end'] ?? ''));
        if ($start !== '') { $where .= ' AND a.created_at >= ?'; $params[] = $start; }
        if ($end !== '') {
            // The latest operational period has no upper bound so late entries are not lost.
            $isLatest = true;
            try {
                $st = $pdo->prepare('SELECT COUNT(*) FROM operational_periods WHERE incident_id = ? AND deleted_at IS NULL AND op_start > ?');
                $st->execute([$incidentId, $start !== '' ? $start : '1970-01-01']);
                $isLatest = ((int)$st->fetchColumn()) === 0;
            } catch (Throwable $e) {}
            if (!$isLatest) { $where .= ' AND a.created_at <= ?'; $params[] = $end; }
        }

        try {
            $st = $pdo->prepare("SELECT a.id, a.user_id, a.entity_type, a.entity_id, a.action_type, a.action_detail, a.created_at,
                    COALESCE(NULLIF(u.real_name, ''), u.username) AS actor_name
                FROM audit_log a LEFT JOIN users u ON u.id = a.user_id
                WHERE $where ORDER BY a.created_at ASC, a.id ASC LIMIT 5000");
            $st->execute($params);
            $rows = $st->fetchAll();
        } catch (Throwable $e) {
            return [];
        }

        // Names of the accounts that were the subject of account events.
        $targetIds = [];
        foreach ($rows as $r) { if (strtolower((string)$r['entity_type']) === 'user' && (int)$r['entity_id'] > 0) { $targetIds[(int)$r['entity_id']] = true; } }
        $targets = [];
        if ($targetIds) {
            try {
                $in = implode(',', array_fill(0, count($targetIds), '?'));
                $st = $pdo->prepare("SELECT id, COALESCE(NULLIF(real_name, ''), username) AS n FROM users WHERE id IN ($in)");
                $st->execute(array_keys($targetIds));
                foreach ($st->fetchAll() as $u) { $targets[(int)$u['id']] = (string)$u['n']; }
            } catch (Throwable $e) {}
        }

        $labels = ['form' => 'Form', 'account' => 'Account', 'signin' => 'Sign-in', 'system' => 'System'];
        $out = [];
        foreach ($rows as $r) {
            $actor = trim((string)($r['actor_name'] ?? '')) !== '' ? (string)$r['actor_name'] : 'System';
            $target = strtolower((string)$r['entity_type']) === 'user' ? ($targets[(int)$r['entity_id']] ?? '') : '';
            $cat = ics214_audit_category((string)$r['action_type'], (string)$r['entity_type']);
            $ts = strtotime((string)$r['created_at']);
            $out[] = [
                'time' => $ts ? date('Y-m-d H:i', $ts) : (string)$r['created_at'],
                'text' => ics214_audit_sentence($r, $actor, $target),
                'source' => $labels[$cat],
                'cat' => $cat,
            ];
        }
        return $out;
    }
}
