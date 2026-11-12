<?php
declare(strict_types=1);
require_once __DIR__ . '/security.php';


require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

function app_url(string $path = ''): string {
    $path = ltrim($path, '/');

    if (APP_BASE_URL === '/' || APP_BASE_URL === '') {
        return '/' . $path;
    }

    return rtrim(APP_BASE_URL, '/') . '/' . $path;
}

function e(?string $value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function h($value): string {
    return e($value === null ? '' : (string)$value);
}

function flash(string $type, string $message): void {
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

function get_flashes(): array {
    $flashes = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $flashes;
}

function generate_pin(): string {
    return str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
}

function encrypt_pin(string $pin): string {
    return password_hash($pin, PASSWORD_DEFAULT);
}

function verify_pin(string $pin, string $hash): bool {
    return password_verify($pin, $hash);
}

/**
 * Call after any PIN/password change. Records the moment (UTC) so every OTHER signed-in session of that account ends
 * (see auth_session_still_valid()); the session of the person making their own change stays signed in.
 */
function touch_user_credentials(int $userId): void {
    try {
        db()->prepare('UPDATE users SET credentials_changed_at = UTC_TIMESTAMP() WHERE id = ?')->execute([$userId]);
    } catch (Throwable $e) { /* column not installed yet: older behaviour (sessions are not ended) */ }
    if ($userId === (int)(current_user()['id'] ?? 0)) { $_SESSION['auth_at'] = time(); }
}

/** Tell every TSC administrator that an account was locked after repeated failed sign-ins. */
function notify_tsc_account_locked(array $lockedUser, string $ip, string $remaining): void {
    try {
        $incidentId = (int)(active_incident()['id'] ?? 0);
        if ($incidentId <= 0) { return; }
        $name = trim((string)($lockedUser['real_name'] ?? '')) ?: (string)($lockedUser['username'] ?? 'a user');
        $title = 'Account locked: ' . $name;
        $body = $name . ' was locked out for ' . $remaining . ' after repeated failed sign-ins from ' . $ip . '. Unlock or reset the PIN from User Management if this is the real user.';
        $admins = db()->query('SELECT id FROM users WHERE is_tsc_admin = 1 AND is_active = 1 AND deleted_at IS NULL')->fetchAll();
        $ins = db()->prepare("INSERT INTO notifications
            (incident_id, user_id, role_code, form_record_id, notification_type, title, subject, body, link_url, related_table, related_id, urgency, is_read, created_at)
            VALUES (?, ?, NULL, NULL, 'account_locked', ?, ?, ?, ?, 'users', ?, 'high', 0, NOW())");
        foreach ($admins as $a) {
            $ins->execute([$incidentId, (int)$a['id'], $title, $title, $body, app_url('/admin/users.php'), (int)$lockedUser['id']]);
        }
    } catch (Throwable $e) { security_log($e, 'notify_tsc_account_locked'); }
}

function audit_log(?int $userId, string $action, string $entityType = '', ?int $entityId = null, string $details = ''): void {
    // Remember what this request already recorded so the automatic form hook does not log it twice.
    $GLOBALS['ics_audit_seen'][strtoupper($entityType ?: 'system')] = true;
    try {
        $incidentId = current_user()['incident_id'] ?? active_incident()['id'] ?? null;
        $stmt = db()->prepare("
            INSERT INTO audit_log (incident_id, user_id, entity_type, entity_id, action_type, action_detail, created_at)
            VALUES (?, ?, ?, ?, ?, ?, NOW())
        ");
        $stmt->execute([$incidentId, $userId, $entityType ?: 'system', $entityId ?? 0, $action, $details]);
    } catch (Throwable $e) { security_log($e); }
}


function resource_kind_type_catalog(): array {
    static $catalog = null;
    if ($catalog !== null) { return $catalog; }

    $fallbackPath = dirname(__DIR__) . '/assets/data/kind_type_catalog.json';
    $fallback = ['version' => 'fallback', 'categories' => []];

    try {
        $stmt = db()->query("
            SELECT category_name, kind_name, primary_unit, alternate_units, type_label, type_level
            FROM resource_kind_type_catalog
            WHERE is_active = 1
            ORDER BY sort_order, category_name, kind_name, type_level, type_label
        ");
        $rows = $stmt->fetchAll();
        if ($rows) {
            $cats = [];
            foreach ($rows as $row) {
                $catName = (string)($row['category_name'] ?: 'General');
                $kindName = (string)($row['kind_name'] ?: '');
                if ($kindName === '') { continue; }
                if (!isset($cats[$catName])) {
                    $cats[$catName] = ['category_id' => strtolower(preg_replace('/[^a-z0-9]+/i', '_', $catName)), 'category_name' => $catName, 'kinds' => []];
                }
                if (!isset($cats[$catName]['kinds'][$kindName])) {
                    $alts = [];
                    if (!empty($row['alternate_units'])) {
                        $decoded = json_decode((string)$row['alternate_units'], true);
                        $alts = is_array($decoded) ? $decoded : array_filter(array_map('trim', explode(',', (string)$row['alternate_units'])));
                    }
                    $cats[$catName]['kinds'][$kindName] = [
                        'kind_id' => strtolower(preg_replace('/[^a-z0-9]+/i', '_', $kindName)),
                        'kind_name' => $kindName,
                        'primary_unit' => (string)($row['primary_unit'] ?: ''),
                        'alternate_units' => $alts,
                        'types' => []
                    ];
                }
                if (!empty($row['type_label'])) {
                    $cats[$catName]['kinds'][$kindName]['types'][] = [
                        'type_level' => $row['type_level'] !== null ? (int)$row['type_level'] : null,
                        'label' => (string)$row['type_label']
                    ];
                }
            }
            foreach ($cats as &$cat) { $cat['kinds'] = array_values($cat['kinds']); }
            $catalog = ['version' => 'database', 'categories' => array_values($cats)];
            return $catalog;
        }
    } catch (Throwable $e) { security_log($e); }

    if (is_file($fallbackPath)) {
        $decoded = json_decode((string)file_get_contents($fallbackPath), true);
        if (is_array($decoded) && isset($decoded['categories'])) {
            $catalog = $decoded;
            return $catalog;
        }
    }

    $catalog = $fallback;
    return $catalog;
}


function get_roles(): array
{
    $hasActiveFlag = in_array('is_active', table_columns('roles'), true);
    $activeFilter = $hasActiveFlag ? ' WHERE r.is_active = 1' : '';

    try {
        $stmt = db()->query("
            SELECT
                r.id,
                r.role_code AS code,
                COALESCE(pc.full_title, r.role_name) AS name,
                COALESCE(pc.group_name, 'Other') AS group_name,
                COALESCE(pc.sort_order, 9999) AS sort_order
            FROM roles r
            LEFT JOIN incident_position_catalog pc
                ON pc.acronym = r.role_code
               AND pc.is_active = 1
            {$activeFilter}
            ORDER BY
                COALESCE(pc.group_sort, 9999),
                COALESCE(pc.sort_order, 9999),
                name
        ");
        return $stmt->fetchAll();
    } catch (Throwable $e) {
        $stmt = db()->query("
            SELECT id, role_code AS code, role_name AS name, 'Other' AS group_name, id AS sort_order
            FROM roles
            {$activeFilter}
            ORDER BY role_name
        ");
        return $stmt->fetchAll();
    }
}

function get_incidents(): array {
    $stmt = db()->query("SELECT id, incident_name AS name, incident_number, status FROM incidents WHERE deleted_at IS NULL ORDER BY id DESC");
    return $stmt->fetchAll();
}

function active_incident(): ?array {
    $stmt = db()->query("
        SELECT id, incident_name AS name, incident_name, incident_number, incident_type, status, started_at, ended_at
        FROM incidents
        WHERE deleted_at IS NULL
        ORDER BY id DESC
        LIMIT 1
    ");
    $row = $stmt->fetch();
    return $row ?: null;
}

function get_or_create_form_record(int $incidentId, string $formType, string $formNumber, ?int $userId = null, ?int $operationalPeriodId = null): int {
    $normalizedType = normalize_ics_form_type($formType);
    if (($operationalPeriodId === null || $operationalPeriodId <= 0) && $normalizedType !== 'ICS201') {
        $currentOp = current_operational_period($incidentId);
        $operationalPeriodId = (int)($currentOp['id'] ?? 0) ?: null;
        if ($operationalPeriodId === null || $operationalPeriodId <= 0) {
            throw new RuntimeException('Cannot create ICS form record without an active Operational Period.');
        }
    }

    $typeWhere = "(REPLACE(REPLACE(UPPER(form_type), ' ', ''), '-', '') = ?
                  OR CONCAT('ICS', REPLACE(REPLACE(UPPER(form_type), ' ', ''), '-', '')) = ?)";

    if ($operationalPeriodId !== null && $operationalPeriodId > 0) {
        $stmt = db()->prepare("
            SELECT id FROM form_records
            WHERE incident_id = ? AND $typeWhere AND operational_period_id = ? AND is_deleted = 0
            ORDER BY review_required DESC, updated_at DESC, id DESC
            LIMIT 1
        ");
        $stmt->execute([$incidentId, $normalizedType, $normalizedType, $operationalPeriodId]);
    } else {
        $stmt = db()->prepare("
            SELECT id FROM form_records
            WHERE incident_id = ? AND $typeWhere AND is_deleted = 0
            ORDER BY updated_at DESC, id DESC
            LIMIT 1
        ");
        $stmt->execute([$incidentId, $normalizedType, $normalizedType]);
    }
    $row = $stmt->fetch();
    if ($row) {
        return (int)$row['id'];
    }

    $stmt = db()->prepare("
        INSERT INTO form_records
        (incident_id, operational_period_id, form_type, form_number, status, prepared_by_user_id, prepared_by_name, prepared_at, created_at, updated_at)
        VALUES (?, ?, ?, ?, 'draft', ?, NULL, NOW(), NOW(), NOW())
    ");
    $stmt->execute([$incidentId, $operationalPeriodId, $normalizedType, $formNumber, $userId]);
    return (int)db()->lastInsertId();
}

function get_primary_contact_value(int $userId, string $type): string {
    $stmt = db()->prepare("
        SELECT contact_value
        FROM user_contacts
        WHERE user_id = ? AND contact_type = ? AND deleted_at IS NULL
        ORDER BY is_primary DESC, id ASC
        LIMIT 1
    ");
    $stmt->execute([$userId, $type]);
    return (string)($stmt->fetch()['contact_value'] ?? '');
}

function save_contact_value(int $userId, string $type, string $value): void {
    $value = trim($value);
    $pdo = db();

    $stmt = $pdo->prepare("
        SELECT id
        FROM user_contacts
        WHERE user_id = ? AND contact_type = ? AND deleted_at IS NULL
        ORDER BY is_primary DESC, id ASC
        LIMIT 1
    ");
    $stmt->execute([$userId, $type]);
    $existingId = (int)($stmt->fetch()['id'] ?? 0);

    if ($value === '') {
        if ($existingId > 0) {
            $stmt = $pdo->prepare("UPDATE user_contacts SET deleted_at = NOW(), updated_at = NOW() WHERE id = ?");
            $stmt->execute([$existingId]);
        }
        return;
    }

    if ($existingId > 0) {
        $stmt = $pdo->prepare("UPDATE user_contacts SET contact_value = ?, is_primary = 1, updated_at = NOW() WHERE id = ?");
        $stmt->execute([$value, $existingId]);
    } else {
        $stmt = $pdo->prepare("
            INSERT INTO user_contacts (user_id, contact_type, contact_value, is_primary, created_at, updated_at)
            VALUES (?, ?, ?, 1, NOW(), NOW())
        ");
        $stmt->execute([$userId, $type, $value]);
    }
}

function get_user_role_codes(int $incidentId, int $userId): array {
    $stmt = db()->prepare("
        SELECT r.role_code
        FROM incident_positions ip
        INNER JOIN roles r ON r.id = ip.role_id
        WHERE ip.incident_id = ? AND ip.user_id = ?
          AND ip.deleted_at IS NULL
          AND (ip.assigned_end IS NULL OR ip.assigned_end >= NOW())
        ORDER BY r.role_code
    ");
    $stmt->execute([$incidentId, $userId]);
    return array_values(array_filter(array_map(fn($row) => (string)$row['role_code'], $stmt->fetchAll())));
}

function sync_user_roles(int $incidentId, int $userId, array $roleIds): void {
    $pdo = db();
    $roleIds = array_values(array_unique(array_filter(array_map('intval', $roleIds))));

    $stmt = $pdo->prepare("
        UPDATE incident_positions
        SET deleted_at = NOW(), assigned_end = NOW(), updated_at = NOW()
        WHERE incident_id = ? AND user_id = ? AND deleted_at IS NULL
    ");
    $stmt->execute([$incidentId, $userId]);

    if (!$roleIds) {
        return;
    }

    $insert = $pdo->prepare("
        INSERT INTO incident_positions (incident_id, role_id, user_id, assigned_start, created_at, updated_at)
        VALUES (?, ?, ?, NOW(), NOW(), NOW())
    ");
    foreach ($roleIds as $roleId) {
        $insert->execute([$incidentId, $roleId, $userId]);
    }
}

function ensure_211_header(int $incidentId, ?int $preparedByUserId = null): int {
    $formRecordId = get_or_create_form_record($incidentId, 'ICS211', '211', $preparedByUserId);
    $stmt = db()->prepare("SELECT id FROM form_211_headers WHERE form_record_id = ? LIMIT 1");
    $stmt->execute([$formRecordId]);
    $headerId = (int)(($stmt->fetch())['id'] ?? 0);

    if ($headerId === 0) {
        $stmt = db()->prepare("
            INSERT INTO form_211_headers (form_record_id, check_in_location, prepared_by_position_title)
            VALUES (?, ?, ?)
        ");
        $stmt->execute([$formRecordId, 'Incident Check-In', 'CHECKIN']);
        $headerId = (int)db()->lastInsertId();
    }

    return $headerId;
}

function ensure_205a_header(int $incidentId, ?int $preparedByUserId = null): int {
    $formRecordId = get_or_create_form_record($incidentId, 'ICS205A', '205A', $preparedByUserId);
    $stmt = db()->prepare("SELECT id FROM form_205a_headers WHERE form_record_id = ? LIMIT 1");
    $stmt->execute([$formRecordId]);
    $headerId = (int)(($stmt->fetch())['id'] ?? 0);

    if ($headerId === 0) {
        $stmt = db()->prepare("
            INSERT INTO form_205a_headers (form_record_id, sensitivity_notice, notes)
            VALUES (?, 1, NULL)
        ");
        $stmt->execute([$formRecordId]);
        $headerId = (int)db()->lastInsertId();
    }

    return $headerId;
}

function sync_user_checkin_records(int $incidentId, int $userId, bool $include205A = true): void {
    $pdo = db();

    $userStmt = $pdo->prepare("SELECT id, real_name, email FROM users WHERE id = ? LIMIT 1");
    $userStmt->execute([$userId]);
    $user = $userStmt->fetch();
    if (!$user) {
        return;
    }

    $roleCodes = get_user_role_codes($incidentId, $userId);
    $positionList = implode(', ', $roleCodes);
    $email = (string)($user['email'] ?? '');
    $phone = get_primary_contact_value($userId, 'cell_phone');
    $contactParts = array_values(array_filter([$email, $phone], fn($v) => trim((string)$v) !== ''));
    $contact = implode(' ', $contactParts);
    $homeAgency = defined('APP_ORG_NAME') ? APP_ORG_NAME : 'Organization';

    $headerId = ensure_211_header($incidentId, $userId);
    $stmt = $pdo->prepare("SELECT id FROM form_211_entries WHERE user_id = ? AND deleted_at IS NULL ORDER BY id DESC LIMIT 1");
    $stmt->execute([$userId]);
    $existingId = (int)(($stmt->fetch())['id'] ?? 0);

    if ($existingId > 0) {
        $stmt = $pdo->prepare("
            UPDATE form_211_entries
            SET person_name = ?, incident_assigned_position = ?, home_agency = ?, contact_information = ?,
                incident_assignment = ?, updated_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([
            $user['real_name'],
            $positionList ?: null,
            $homeAgency,
            $contact ?: null,
            $positionList ?: null,
            $existingId
        ]);
    } else {
        $stmt = $pdo->prepare("
            INSERT INTO form_211_entries
            (header_id, user_id, person_name, incident_assigned_position, home_agency, contact_information, arrival_date, incident_assignment, checkin_time, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, NOW(), ?, NOW(), NOW(), NOW())
        ");
        $stmt->execute([
            $headerId,
            $userId,
            $user['real_name'],
            $positionList ?: null,
            $homeAgency,
            $contact ?: null,
            $positionList ?: null
        ]);
    }

    if (!$include205A) {
        return;
    }

    $header205aId = ensure_205a_header($incidentId, $userId);

    $stmt = $pdo->prepare("UPDATE form_205a_entries SET deleted_at = NOW(), updated_at = NOW() WHERE person_name = ? AND deleted_at IS NULL");
    $stmt->execute([$user['real_name']]);

    if (!$roleCodes) {
        return;
    }

    $insert205a = $pdo->prepare("
        INSERT INTO form_205a_entries
        (header_id, incident_assigned_position, person_name, contact_methods, display_order, created_at, updated_at)
        VALUES (?, ?, ?, ?, ?, NOW(), NOW())
    ");

    $contactMethods = implode(' | ', $contactParts);
    $displayOrder = 1;
    foreach ($roleCodes as $roleCode) {
        $insert205a->execute([
            $header205aId,
            $roleCode,
            $user['real_name'],
            $contactMethods ?: null,
            $displayOrder++
        ]);
    }
}


/**
 * CIR/SAM Companion Check-In access is incident-specific. The user's
 * CommandBridge PIN is the only PIN stored; authorization and permitted
 * locations remain separate from the PIN credential.
 */
function companion_checkin_role_codes(): array {
    return ['CIR', 'SAM'];
}

function companion_checkin_user_is_eligible(int $incidentId, int $userId): bool {
    if ($incidentId <= 0 || $userId <= 0) {
        return false;
    }

    $stmt = db()->prepare(
        'SELECT 1
           FROM users u
           INNER JOIN incident_positions ip
             ON ip.user_id = u.id
            AND ip.incident_id = ?
            AND ip.deleted_at IS NULL
            AND (ip.assigned_end IS NULL OR ip.assigned_end >= NOW())
           INNER JOIN roles r ON r.id = ip.role_id
          WHERE u.id = ?
            AND u.deleted_at IS NULL
            AND u.is_active = 1
            AND r.is_active = 1
            AND r.role_code IN ("CIR", "SAM")
          LIMIT 1'
    );
    $stmt->execute([$incidentId, $userId]);
    return (bool)$stmt->fetchColumn();
}

function companion_checkin_user_locations(int $incidentId, int $userId): array {
    if ($incidentId <= 0 || $userId <= 0) {
        return [];
    }

    $stmt = db()->prepare(
        'SELECT a.location_id
           FROM external_checkin_user_locations a
           INNER JOIN external_checkin_locations l
             ON l.id = a.location_id
            AND l.incident_id = a.incident_id
          WHERE a.incident_id = ?
            AND a.user_id = ?
            AND a.revoked_at IS NULL
            AND l.lifecycle_status = "active"
          ORDER BY a.location_id'
    );
    $stmt->execute([$incidentId, $userId]);
    return array_map('intval', array_column($stmt->fetchAll(), 'location_id'));
}

function companion_checkin_user_access(int $incidentId, int $userId): ?array {
    if ($incidentId <= 0 || $userId <= 0) {
        return null;
    }

    $stmt = db()->prepare(
        'SELECT u.id, u.real_name, u.is_active, u.pin_encrypted, u.pin_last_reset_at,
                a.id AS access_id, a.access_revoked_at,
                GROUP_CONCAT(DISTINCT r.role_code ORDER BY r.role_code SEPARATOR ",") AS role_codes
           FROM users u
           INNER JOIN incident_positions ip
             ON ip.user_id = u.id
            AND ip.incident_id = ?
            AND ip.deleted_at IS NULL
            AND (ip.assigned_end IS NULL OR ip.assigned_end >= NOW())
           INNER JOIN roles r
             ON r.id = ip.role_id
            AND r.is_active = 1
            AND r.role_code IN ("CIR", "SAM")
           LEFT JOIN external_checkin_user_access a
             ON a.incident_id = ip.incident_id
            AND a.user_id = u.id
          WHERE u.id = ?
            AND u.deleted_at IS NULL
          GROUP BY u.id, u.real_name, u.is_active, u.pin_encrypted, u.pin_last_reset_at, a.id, a.access_revoked_at
          LIMIT 1'
    );
    $stmt->execute([$incidentId, $userId]);
    $row = $stmt->fetch();
    if (!$row) {
        return null;
    }

    $row['location_ids'] = companion_checkin_user_locations($incidentId, $userId);
    $row['role_codes'] = array_values(array_filter(explode(',', (string)($row['role_codes'] ?? ''))));
    return $row;
}

function companion_checkin_user_has_access(int $incidentId, int $userId): bool {
    $access = companion_checkin_user_access($incidentId, $userId);
    return $access !== null
        && !empty($access['is_active'])
        && !empty($access['access_id'])
        && empty($access['access_revoked_at'])
        && trim((string)($access['pin_encrypted'] ?? '')) !== ''
        && !empty($access['location_ids']);
}

function companion_checkin_set_user_locations(int $incidentId, int $userId, array $locationIds, ?int $updatedByUserId = null): void {
    if ($incidentId <= 0 || $userId <= 0) {
        throw new InvalidArgumentException('An incident and user are required for Companion Check-In location assignments.');
    }

    $locationIds = array_values(array_unique(array_filter(array_map('intval', $locationIds), fn(int $id): bool => $id > 0)));
    $pdo = db();

    if ($locationIds) {
        $placeholders = implode(',', array_fill(0, count($locationIds), '?'));
        $locationStmt = $pdo->prepare(
            'SELECT id
               FROM external_checkin_locations
              WHERE incident_id = ?
                AND lifecycle_status = "active" 
                AND id IN (' . $placeholders . ')'
        );
        $locationStmt->execute(array_merge([$incidentId], $locationIds));
        $validIds = array_map('intval', array_column($locationStmt->fetchAll(), 'id'));
        sort($validIds);
        $requestedIds = $locationIds;
        sort($requestedIds);
        if ($validIds !== $requestedIds) {
            throw new RuntimeException('One or more selected Companion Check-In locations are invalid for this incident.');
        }
    }

    $revoke = $pdo->prepare(
        'UPDATE external_checkin_user_locations
            SET revoked_at = NOW(), updated_by = ?, updated_at = NOW()
          WHERE incident_id = ?
            AND user_id = ?
            AND revoked_at IS NULL'
    );
    $revoke->execute([$updatedByUserId, $incidentId, $userId]);

    if (!$locationIds) {
        return;
    }

    $insert = $pdo->prepare(
        'INSERT INTO external_checkin_user_locations
            (incident_id, user_id, location_id, assigned_by, updated_by, assigned_at, updated_at)
         VALUES (?, ?, ?, ?, ?, NOW(), NOW())'
    );
    foreach ($locationIds as $locationId) {
        $insert->execute([$incidentId, $userId, $locationId, $updatedByUserId, $updatedByUserId]);
    }
}

function companion_checkin_enable_user_access(int $incidentId, int $userId, ?int $updatedByUserId = null): void {
    if (!companion_checkin_user_is_eligible($incidentId, $userId)) {
        throw new RuntimeException('Companion Check-In access requires an active CIR or SAM position.');
    }

    $stmt = db()->prepare(
        'INSERT INTO external_checkin_user_access
            (incident_id, user_id, access_revoked_at, created_by, updated_by, created_at, updated_at)
         VALUES (?, ?, NULL, ?, ?, NOW(), NOW())
         ON DUPLICATE KEY UPDATE
            access_revoked_at = NULL,
            updated_by = VALUES(updated_by),
            updated_at = NOW()'
    );
    $stmt->execute([$incidentId, $userId, $updatedByUserId, $updatedByUserId]);
}

function companion_checkin_revoke_user_access(int $incidentId, int $userId, ?int $updatedByUserId = null): void {
    db()->prepare(
        'UPDATE external_checkin_user_access
            SET access_revoked_at = NOW(), updated_by = ?, updated_at = NOW()
          WHERE incident_id = ? AND user_id = ?'
    )->execute([$updatedByUserId, $incidentId, $userId]);
}

function companion_checkin_sync_user_ics211(int $incidentId, int $userId): void {
    if (!companion_checkin_user_is_eligible($incidentId, $userId)) {
        return;
    }
    sync_user_checkin_records($incidentId, $userId, false);
}

function get_user_with_roles(int $userId, ?int $incidentId = null): ?array {
    $pdo = db();
    $incidentId = $incidentId ?: (int)(active_incident()['id'] ?? 0);
    $stmt = $pdo->prepare("
        SELECT id, username, real_name, email, is_tsc_admin, is_active,
               auth_provider, entra_object_id, entra_user_principal_name
        FROM users
        WHERE id = ? AND deleted_at IS NULL
        LIMIT 1
    ");
    $stmt->execute([$userId]);
    $user = $stmt->fetch();
    if (!$user) {
        return null;
    }
    $user['cell_phone'] = get_primary_contact_value($userId, 'cell_phone');
    $user['role_ids'] = [];
    if ($incidentId > 0) {
        $stmt = $pdo->prepare("
            SELECT role_id
            FROM incident_positions
            WHERE incident_id = ? AND user_id = ? AND deleted_at IS NULL
              AND (assigned_end IS NULL OR assigned_end >= NOW())
        ");
        $stmt->execute([$incidentId, $userId]);
        $user['role_ids'] = array_map('intval', array_column($stmt->fetchAll(), 'role_id'));
    }
    return $user;
}


function active_incident_id(): int {
    return (int)(active_incident()['id'] ?? 0);
}

function format_datetime_display(?string $value): string {
    if ($value === null || trim($value) === '') {
        return '';
    }

    $ts = strtotime($value);
    if ($ts === false) {
        return (string)$value;
    }

    return date('M j, Y H:i', $ts);
}

function format_datetime_local_input(?string $value): string {
    if ($value === null || trim($value) === '') {
        return '';
    }

    $ts = strtotime($value);
    if ($ts === false) {
        return '';
    }

    return date('Y-m-d H:i', $ts);
}

function get_operational_periods(int $incidentId): array {
    if ($incidentId <= 0) {
        return [];
    }

    $stmt = db()->prepare("
        SELECT id, incident_id, period_number, period_name, op_start, op_end, status
        FROM operational_periods
        WHERE incident_id = ?
          AND deleted_at IS NULL
        ORDER BY op_start ASC, id ASC
    ");
    $stmt->execute([$incidentId]);
    return $stmt->fetchAll();
}

function get_operational_period_by_id(int $incidentId, int $operationalPeriodId): ?array {
    if ($incidentId <= 0 || $operationalPeriodId <= 0) {
        return null;
    }

    $stmt = db()->prepare("
        SELECT id, incident_id, period_number, period_name, op_start, op_end, status
        FROM operational_periods
        WHERE incident_id = ?
          AND id = ?
          AND deleted_at IS NULL
        LIMIT 1
    ");
    $stmt->execute([$incidentId, $operationalPeriodId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function set_selected_operational_period_id(?int $operationalPeriodId): void {
    if ($operationalPeriodId === null || $operationalPeriodId <= 0) {
        unset($_SESSION['selected_operational_period_id']);
        return;
    }

    $_SESSION['selected_operational_period_id'] = $operationalPeriodId;
}

function sync_selected_operational_period_from_request(?int $incidentId = null): ?int {
    $incidentId = $incidentId ?: active_incident_id();
    if ($incidentId <= 0) {
        return null;
    }

    $requested = $_GET['op_id'] ?? $_POST['op_id'] ?? null;
    if ($requested === null || $requested === '') {
        return isset($_SESSION['selected_operational_period_id']) ? (int)$_SESSION['selected_operational_period_id'] : null;
    }

    $requestedId = (int)$requested;
    if ($requestedId <= 0) {
        unset($_SESSION['selected_operational_period_id']);
        return null;
    }

    $period = get_operational_period_by_id($incidentId, $requestedId);
    if ($period) {
        $_SESSION['selected_operational_period_id'] = (int)$period['id'];
        return (int)$period['id'];
    }

    return isset($_SESSION['selected_operational_period_id']) ? (int)$_SESSION['selected_operational_period_id'] : null;
}

function current_operational_period(?int $incidentId = null): ?array {
    $incidentId = $incidentId ?: active_incident_id();
    if ($incidentId <= 0) {
        return null;
    }

    $selectedId = sync_selected_operational_period_from_request($incidentId);
    if ($selectedId !== null && $selectedId > 0) {
        $selected = get_operational_period_by_id($incidentId, $selectedId);
        if ($selected) {
            return $selected;
        }
    }

    try {
        // Prefer an explicitly active/open operational period for the incident.
        // This avoids relying on server time when a newly-created/default OP has
        // a start/end range that does not contain NOW() due to setup defaults or
        // timezone differences.
        $stmt = db()->prepare("
            SELECT id, incident_id, period_number, period_name, op_start, op_end, status
            FROM operational_periods
            WHERE incident_id = ?
              AND deleted_at IS NULL
              AND LOWER(COALESCE(status,'')) IN ('active','open')
            ORDER BY op_start DESC, id DESC
            LIMIT 1
        ");
        $stmt->execute([$incidentId]);
        $active = $stmt->fetch();
        if ($active) {
            $_SESSION['selected_operational_period_id'] = (int)$active['id'];
            return $active;
        }
    } catch (Throwable $e) {}

    $periods = get_operational_periods($incidentId);
    if (!$periods) {
        return null;
    }

    $now = time();
    $fallbackPeriod = null;
    foreach ($periods as $period) {
        $status = strtolower(trim((string)($period['status'] ?? '')));
        $startTs = strtotime((string)$period['op_start']);
        $endTs = strtotime((string)$period['op_end']);

        if ($fallbackPeriod === null && in_array($status, ['draft', 'planned'], true)) {
            $fallbackPeriod = $period;
        }

        if (in_array($status, ['active', 'open', 'draft', 'planned'], true)
            && $startTs !== false && $endTs !== false && $startTs <= $now && $endTs >= $now) {
            $_SESSION['selected_operational_period_id'] = (int)$period['id'];
            return $period;
        }
    }

    if ($fallbackPeriod !== null) {
        $_SESSION['selected_operational_period_id'] = (int)$fallbackPeriod['id'];
        return $fallbackPeriod;
    }

    unset($_SESSION['selected_operational_period_id']);
    return null;
}

function has_active_operational_period(?int $incidentId = null): bool {
    return current_operational_period($incidentId) !== null;
}

function require_active_operational_period(?int $incidentId = null): array {
    $incidentId = $incidentId ?: active_incident_id();
    $op = $incidentId > 0 ? current_operational_period($incidentId) : null;
    if (!$op) {
        render_header('No Active Operational Period');
        echo '<div class="card">';
        echo '<h2>No Active Operational Period</h2>';
        echo '<p>The previous Operational Period has ended and no new Operational Period has been created.</p>';
        echo '<p>ICS forms are tied to an active Operational Period. Please create or start a new Operational Period before creating or editing forms.</p>';
        echo '<p><a class="btn btn-primary" href="' . e(app_url('/operational_periods/new.php')) . '">Create New Operational Period</a> ';
        echo '<a class="btn" href="' . e(app_url('/dashboard/')) . '">Return to Dashboard</a></p>';
        echo '</div>';
        render_footer();
        exit;
    }
    return $op;
}

function operational_period_label(array $period): string {
    $parts = [];
    if (!empty($period['period_number'])) {
        $parts[] = 'OP ' . (int)$period['period_number'];
    }
    if (!empty($period['period_name'])) {
        $parts[] = (string)$period['period_name'];
    }

    $label = $parts ? implode(' - ', $parts) . ' | ' : '';
    $label .= format_datetime_display((string)($period['op_start'] ?? '')) . ' to ' . format_datetime_display((string)($period['op_end'] ?? ''));

    return $label;
}

function get_meeting_types_catalog(): array {
    try {
        $stmt = db()->query("
            SELECT id, code, name, description
            FROM meeting_types
            WHERE is_active = 1
            ORDER BY sort_order ASC, name ASC, id ASC
        ");
        $rows = $stmt->fetchAll();
        if ($rows) {
            return $rows;
        }
    } catch (Throwable $e) { security_log($e); }

    return [
        ['id' => 0, 'code' => 'BRIEFING', 'name' => 'Briefing', 'description' => null],
        ['id' => 0, 'code' => 'TACTICAL', 'name' => 'Tactics Meeting', 'description' => null],
        ['id' => 0, 'code' => 'PLANNING', 'name' => 'Planning Meeting', 'description' => null],
        ['id' => 0, 'code' => 'COMMAND', 'name' => 'Command and General Staff Meeting', 'description' => null],
    ];
}

function find_meeting_type_id_by_code(string $code): ?int {
    $stmt = db()->prepare("
        SELECT id
        FROM meeting_types
        WHERE code = ?
          AND is_active = 1
        LIMIT 1
    ");
    $stmt->execute([strtoupper(trim($code))]);
    $row = $stmt->fetch();
    return $row ? (int)$row['id'] : null;
}


function current_datetime_local_value(): string {
    return date('Y-m-d H:i');
}

function normalize_ics_form_type(string $formType): string {
    $type = strtoupper(str_replace([' ', '-'], '', trim($formType)));
    if ($type !== '' && preg_match('/^\d{3}[A-Z]?$/', $type)) {
        $type = 'ICS' . $type;
    }
    return $type;
}

function get_form_record(int $incidentId, string $formType, ?int $operationalPeriodId = null): ?array {
    $normalizedType = normalize_ics_form_type($formType);

    // ICS 201 is incident-level. Other ICS forms should default to the selected/current OP
    // so prior OP records do not appear as current OP work.
    if (($operationalPeriodId === null || $operationalPeriodId <= 0) && $normalizedType !== 'ICS201') {
        $currentOp = current_operational_period($incidentId);
        $operationalPeriodId = (int)($currentOp['id'] ?? 0);
    }

    $typeWhere = "(REPLACE(REPLACE(UPPER(form_type), ' ', ''), '-', '') = ?
                  OR CONCAT('ICS', REPLACE(REPLACE(UPPER(form_type), ' ', ''), '-', '')) = ?)";
    if ($operationalPeriodId !== null && $operationalPeriodId > 0) {
        $stmt = db()->prepare("
            SELECT *
            FROM form_records
            WHERE incident_id = ? AND $typeWhere AND operational_period_id = ? AND is_deleted = 0
            ORDER BY review_required DESC, updated_at DESC, id DESC
            LIMIT 1
        ");
        $stmt->execute([$incidentId, $normalizedType, $normalizedType, $operationalPeriodId]);
    } else {
        $stmt = db()->prepare("
            SELECT *
            FROM form_records
            WHERE incident_id = ? AND $typeWhere AND is_deleted = 0
            ORDER BY updated_at DESC, id DESC
            LIMIT 1
        ");
        $stmt->execute([$incidentId, $normalizedType, $normalizedType]);
    }
    $row = $stmt->fetch();
    return $row ?: null;
}

function current_user_has_any_role_codes(array $roleCodes, ?int $incidentId = null): bool {
    $user = current_user();
    if (!$user) {
        return false;
    }

    if (!empty($user['is_tsc_admin'])) {
        return true;
    }

    $incidentId = $incidentId ?: (int)($user['incident_id'] ?? 0) ?: active_incident_id();
    $userId = (int)($user['id'] ?? 0);
    if ($incidentId <= 0 || $userId <= 0 || !$roleCodes) {
        return false;
    }

    $placeholders = implode(',', array_fill(0, count($roleCodes), '?'));
    $params = array_merge([$incidentId, $userId], array_values($roleCodes));

    $stmt = db()->prepare("
        SELECT COUNT(*) AS c
        FROM incident_positions ip
        INNER JOIN roles r ON r.id = ip.role_id
        WHERE ip.incident_id = ?
          AND ip.user_id = ?
          AND ip.deleted_at IS NULL
          AND (ip.assigned_end IS NULL OR ip.assigned_end >= NOW())
          AND r.role_code IN ($placeholders)
    ");
    $stmt->execute($params);
    $row = $stmt->fetch();

    return ((int)($row['c'] ?? 0)) > 0;
}

function find_assigned_user_by_role_codes(int $incidentId, array $roleCodes): ?array {
    if ($incidentId <= 0 || !$roleCodes) {
        return null;
    }

    $pdo = db();
    $placeholders = implode(',', array_fill(0, count($roleCodes), '?'));
    $orderCases = [];
    foreach (array_values($roleCodes) as $idx => $code) {
        $orderCases[] = "WHEN ? THEN " . ($idx + 1);
    }

    $sql = "
        SELECT
            u.id,
            u.real_name,
            u.username,
            u.email,
            r.role_code,
            r.role_name
        FROM incident_positions ip
        INNER JOIN roles r ON r.id = ip.role_id
        INNER JOIN users u ON u.id = ip.user_id
        WHERE ip.incident_id = ?
          AND ip.deleted_at IS NULL
          AND (ip.assigned_end IS NULL OR ip.assigned_end >= NOW())
          AND r.role_code IN ($placeholders)
          AND u.deleted_at IS NULL
        ORDER BY CASE r.role_code " . implode(' ', $orderCases) . " ELSE 999 END, ip.id ASC
        LIMIT 1
    ";

    $params = array_merge([$incidentId], array_values($roleCodes), array_values($roleCodes));
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch();

    return $row ?: null;
}

function resolve_form_preparer(int $incidentId, string $formType): array {
    $formType = strtoupper($formType);

    if ($formType === 'ICS205A' || $formType === '205A') {
        $assigned = find_assigned_user_by_role_codes($incidentId, ['COML', 'LSC']);
        if ($assigned) {
            return [
                'user_id' => (int)$assigned['id'],
                'name' => (string)($assigned['real_name'] ?: $assigned['username']),
                'position_title' => ((string)$assigned['role_code'] === 'COML') ? 'CUL' : 'LSC',
                'role_code' => (string)$assigned['role_code'],
            ];
        }

        return [
            'user_id' => null,
            'name' => 'TBD',
            'position_title' => 'CUL',
            'role_code' => null,
        ];
    }

    if ($formType === 'ICS201' || $formType === '201') {
        $assigned = find_assigned_user_by_role_codes($incidentId, ['IC', 'PSC', 'DOCL']);
        if ($assigned) {
            return [
                'user_id' => (int)$assigned['id'],
                'name' => (string)($assigned['real_name'] ?: $assigned['username']),
                'position_title' => (string)$assigned['role_code'],
                'role_code' => (string)$assigned['role_code'],
            ];
        }

        return [
            'user_id' => null,
            'name' => 'TBD',
            'position_title' => 'IC',
            'role_code' => null,
        ];
    }

    if ($formType === 'ICS230' || $formType === '230' || $formType === 'ICS209' || $formType === '209' || $formType === 'ICS209WF') {
        $assigned = find_assigned_user_by_role_codes($incidentId, ['SITL', 'PSC', 'DOCL', 'IC']);
        if ($assigned) {
            $roleCode = (string)$assigned['role_code'];
            return [
                'user_id' => (int)$assigned['id'],
                'name' => (string)($assigned['real_name'] ?: $assigned['username']),
                'position_title' => $roleCode,
                'role_code' => $roleCode,
            ];
        }

        return [
            'user_id' => null,
            'name' => 'TBD',
            'position_title' => 'SITL',
            'role_code' => null,
        ];
    }

    if ($formType === 'ICS230' || $formType === '230' || $formType === 'ICS209' || $formType === '209' || $formType === 'ICS209WF') {
        if (in_array('SITL', $roleCodes, true)) {
            return 'SITL';
        }
        if (in_array('PSC', $roleCodes, true)) {
            return 'PSC';
        }
        if (in_array('DOCL', $roleCodes, true)) {
            return 'DOCL';
        }
        if (in_array('IC', $roleCodes, true)) {
            return 'IC';
        }
        return null;
    }

    if ($formType === 'ICS211' || $formType === '211' || $formType === 'ICS207' || $formType === '207') {
        $assigned = find_assigned_user_by_role_codes($incidentId, ['DOCL', 'IC']);
        if ($assigned) {
            return [
                'user_id' => (int)$assigned['id'],
                'name' => (string)($assigned['real_name'] ?: $assigned['username']),
                'position_title' => (string)$assigned['role_code'],
                'role_code' => (string)$assigned['role_code'],
            ];
        }

        return [
            'user_id' => null,
            'name' => 'TBD',
            'position_title' => 'DOCL',
            'role_code' => null,
        ];
    }

    return [
        'user_id' => null,
        'name' => 'TBD',
        'position_title' => '',
        'role_code' => null,
    ];
}

function get_submitter_role_for_form(int $incidentId, int $userId, string $formType): ?string {
    $user = current_user();
    $formType = strtoupper($formType);
    $normalizedFormType = normalize_ics_form_type($formType);

    // The TSC/Admin setup account is the application break-glass/master account.
    // It must be able to test, edit, submit, and sign every form even when it is
    // not assigned to the normal ICS preparer/signing position for that form.
    if (!empty($user['is_tsc_admin']) && (int)($user['id'] ?? 0) === $userId) {
        return 'TSC';
    }

    // Honour the form permissions matrix for final submit/sign decisions.
    // Older logic below is retained as a fallback/default signing-position rule
    // for deployments that have not populated the matrix for every form yet.
    $perms = get_current_user_form_permissions($normalizedFormType, $incidentId);
    $hasMatrixSubmitPermission = !empty($perms['can_submit_final']);
    $roleCodes = get_user_role_codes($incidentId, $userId);
    if ($hasMatrixSubmitPermission) {
        if ($roleCodes) {
            return (string)$roleCodes[0];
        }
        $sessionRole = strtoupper((string)($user['role_code'] ?? ''));
        return $sessionRole !== '' ? $sessionRole : 'AUTHORIZED';
    }

    if ($formType === 'ICS205A' || $formType === '205A') {
        if (in_array('COML', $roleCodes, true)) {
            return 'CUL';
        }
        $assignedComl = find_assigned_user_by_role_codes($incidentId, ['COML']);
        if (!$assignedComl && in_array('LSC', $roleCodes, true)) {
            return 'LSC';
        }
        return null;
    }

    if ($formType === 'ICS201' || $formType === '201') {
        if (in_array('IC', $roleCodes, true)) {
            return 'IC';
        }
        if (in_array('PSC', $roleCodes, true)) {
            return 'PSC';
        }
        if (in_array('DOCL', $roleCodes, true)) {
            return 'DOCL';
        }
        return null;
    }

    if ($formType === 'ICS230' || $formType === '230' || $formType === 'ICS209' || $formType === '209' || $formType === 'ICS209WF') {
        if (in_array('SITL', $roleCodes, true)) {
            return 'SITL';
        }
        if (in_array('PSC', $roleCodes, true)) {
            return 'PSC';
        }
        if (in_array('DOCL', $roleCodes, true)) {
            return 'DOCL';
        }
        if (in_array('IC', $roleCodes, true)) {
            return 'IC';
        }
        return null;
    }

    if ($formType === 'ICS211' || $formType === '211' || $formType === 'ICS207' || $formType === '207') {
        if (in_array('DOCL', $roleCodes, true)) {
            return 'DOCL';
        }
        if (in_array('IC', $roleCodes, true)) {
            return 'IC';
        }
        return null;
    }

    return null;
}


function get_form_permission_defaults(string $formCode): array {
    $code = strtoupper(trim($formCode));
    return [
        'form_code' => $code,
        'can_view' => false,
        'can_create' => false,
        'can_edit' => false,
        'can_submit_final' => false,
        'can_manage_permissions' => false,
        'effective_non_editor_mode' => 'read_only',
        'source' => 'default',
    ];
}

function get_current_user_form_permissions(string $formCode, ?int $incidentId = null): array {
    $perms = get_form_permission_defaults($formCode);
    $user = current_user();

    if (!$user) {
        return $perms;
    }

    if (!empty($user['is_tsc_admin'])) {
        $perms['can_view'] = true;
        $perms['can_create'] = true;
        $perms['can_edit'] = true;
        $perms['can_submit_final'] = true;
        $perms['can_manage_permissions'] = true;
        $perms['effective_non_editor_mode'] = 'read_only';
        $perms['source'] = 'tsc_admin';
        return $perms;
    }

    $incidentId = $incidentId ?: (int)($user['incident_id'] ?? 0) ?: active_incident_id();
    $userId = (int)($user['id'] ?? 0);
    if ($incidentId <= 0 || $userId <= 0) {
        return $perms;
    }

    try {
        $roleCodes = get_user_role_codes($incidentId, $userId);
        $roleCodes[] = 'ALL_AUTHENTICATED';
        $roleCodes = array_values(array_unique(array_filter(array_map('strtoupper', $roleCodes))));

        if (!$roleCodes) {
            return $perms;
        }

        $placeholders = implode(',', array_fill(0, count($roleCodes), '?'));
        $params = array_merge([strtoupper($formCode)], $roleCodes);

        $stmt = db()->prepare("
            SELECT
                role_code,
                can_view,
                can_create,
                can_edit,
                can_submit_final,
                can_manage_permissions,
                effective_non_editor_mode
            FROM vw_form_permissions_effective
            WHERE form_code = ?
              AND role_code IN ($placeholders)
        ");
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        foreach ($rows as $row) {
            $perms['can_view'] = $perms['can_view'] || ((int)($row['can_view'] ?? 0) === 1);
            $perms['can_create'] = $perms['can_create'] || ((int)($row['can_create'] ?? 0) === 1);
            $perms['can_edit'] = $perms['can_edit'] || ((int)($row['can_edit'] ?? 0) === 1);
            $perms['can_submit_final'] = $perms['can_submit_final'] || ((int)($row['can_submit_final'] ?? 0) === 1);
            $perms['can_manage_permissions'] = $perms['can_manage_permissions'] || ((int)($row['can_manage_permissions'] ?? 0) === 1);
        }

        foreach ($rows as $row) {
            if (((int)($row['can_edit'] ?? 0) !== 1) && !empty($row['effective_non_editor_mode'])) {
                $perms['effective_non_editor_mode'] = (string)$row['effective_non_editor_mode'];
                break;
            }
        }

        if ($rows) {
            $perms['source'] = 'matrix';
        }
    } catch (Throwable $e) {
        // Fail closed to prevent 500s if the permissions view is unavailable.
        return $perms;
    }

    return $perms;
}

function current_user_can_view_form(string $formCode, ?int $incidentId = null): bool {
    return !empty(get_current_user_form_permissions($formCode, $incidentId)['can_view']);
}

function current_user_can_edit_form(string $formCode, ?int $incidentId = null): bool {
    return !empty(get_current_user_form_permissions($formCode, $incidentId)['can_edit']);
}

function current_user_can_manage_form_permissions(string $formCode, ?int $incidentId = null): bool {
    return !empty(get_current_user_form_permissions($formCode, $incidentId)['can_manage_permissions']);
}

function current_user_can_submit_form(string $formType, ?int $incidentId = null): bool {
    return !empty(get_current_user_form_permissions($formType, $incidentId)['can_submit_final']);
}



function get_manageable_form_codes(): array {
    return ['ICS201', 'ICS205A', 'ICS207', 'ICS211', 'ICS230', 'ICS213RR', 'RESOURCES_UNIT', 'FINANCE_ADMIN'];
}

function current_user_can_access_permissions_admin(): bool {
    $user = current_user();
    if (!$user) {
        return false;
    }
    if (!empty($user['is_tsc_admin'])) {
        return true;
    }
    foreach (get_manageable_form_codes() as $formCode) {
        if (current_user_can_manage_form_permissions($formCode)) {
            return true;
        }
    }
    return false;
}

function require_permissions_admin_access(): void {
    require_login();
    if (!current_user_can_access_permissions_admin()) {
        http_response_code(403);
        echo 'Forbidden';
        exit;
    }
}

function get_form_catalog(): array {
    static $catalog = null;
    if ($catalog !== null) {
        return $catalog;
    }

    try {
        $stmt = db()->query("
            SELECT
                UPPER(f.form_code) AS form_code,
                COALESCE(NULLIF(TRIM(fd.form_number), ''), UPPER(f.form_code)) AS form_number,
                COALESCE(NULLIF(TRIM(fd.form_name), ''), NULLIF(TRIM(f.form_title), ''), UPPER(f.form_code)) AS form_name,
                f.form_title
            FROM forms f
            LEFT JOIN form_definitions fd
                ON LOWER(fd.form_type) = LOWER(f.form_code)
            WHERE f.is_active = 1
            ORDER BY
                CASE
                    WHEN fd.form_number REGEXP '^[0-9]+[A-Za-z]?$' THEN 0
                    ELSE 1
                END,
                CAST(CASE
                    WHEN fd.form_number REGEXP '^[0-9]+' THEN fd.form_number
                    ELSE '999999'
                END AS UNSIGNED),
                fd.form_number,
                f.form_title,
                f.form_code
        ");

        $catalog = [];
        foreach ($stmt->fetchAll() as $row) {
            $code = strtoupper(trim((string)($row['form_code'] ?? '')));
            if ($code === '') {
                continue;
            }

            $formNumber = trim((string)($row['form_number'] ?? ''));
            $formName = trim((string)($row['form_name'] ?? ''));
            $formTitle = trim((string)($row['form_title'] ?? ''));

            if ($formNumber !== '' && $formName !== '') {
                $label = 'ICS ' . $formNumber . ' - ' . $formName;
            } elseif ($formTitle !== '') {
                $label = $formTitle;
            } else {
                $label = $code;
            }

            $catalog[$code] = $label;
        }

        if ($catalog) {
            $catalog += [
                'ICS201' => 'ICS 201 - Incident Briefing',
                'ICS205A' => 'ICS 205A - Communications List',
                'ICS207' => 'ICS 207 - Incident Organization Chart',
                'ICS211' => 'ICS 211 - Incident Check-In List',
                'ICS218' => 'ICS 218 - Support Vehicle/Equipment Inventory',
                'ICS230' => 'ICS 230 - Daily Meeting Schedule',
                'ICS213RR' => 'ICS 213RR - Resource Request',
                'RESOURCES_UNIT' => 'Resources Unit',
                'FINANCE_ADMIN' => 'Finance/Admin Queue',
            ];
            ksort($catalog);
            return $catalog;
        }
    } catch (Throwable $e) { security_log($e); }

    $catalog = [
        'ICS201' => 'ICS 201 - Incident Briefing',
        'ICS205A' => 'ICS 205A - Communications List',
        'ICS207' => 'ICS 207 - Incident Organization Chart',
        'ICS211' => 'ICS 211 - Incident Check-In List',
        'ICS218' => 'ICS 218 - Support Vehicle/Equipment Inventory',
        'ICS230' => 'ICS 230 - Daily Meeting Schedule',
                'ICS213RR' => 'ICS 213RR - Resource Request',
        'RESOURCES_UNIT' => 'Resources Unit',
        'FINANCE_ADMIN' => 'Finance/Admin Queue',
    ];

    return $catalog;
}

function get_form_matrix_rows(string $formCode): array {
    $stmt = db()->prepare("
        SELECT
            fp.permission_id,
            fp.form_code,
            fp.role_code,
            fp.can_view,
            fp.can_create,
            fp.can_edit,
            fp.can_submit_final,
            fp.can_manage_permissions,
            fp.non_editor_mode_override,
            fp.is_active,
            f.default_non_editor_mode,
            f.form_title
        FROM form_permissions fp
        INNER JOIN forms f ON f.form_code = fp.form_code
        WHERE fp.form_code = ?
        ORDER BY fp.role_code
    ");
    $stmt->execute([strtoupper(trim($formCode))]);
    return $stmt->fetchAll();
}

function get_form_default_non_editor_mode(string $formCode): string {
    $stmt = db()->prepare("SELECT default_non_editor_mode FROM forms WHERE form_code = ? LIMIT 1");
    $stmt->execute([strtoupper(trim($formCode))]);
    return (string)($stmt->fetch()['default_non_editor_mode'] ?? 'read_only');
}

function update_form_default_non_editor_mode(string $formCode, string $mode): void {
    $allowed = ['read_only', 'print_preview', 'hidden'];
    if (!in_array($mode, $allowed, true)) {
        $mode = 'read_only';
    }
    $stmt = db()->prepare("UPDATE forms SET default_non_editor_mode = ?, updated_at = NOW() WHERE form_code = ?");
    $stmt->execute([$mode, strtoupper(trim($formCode))]);
}

function upsert_form_permission_row(string $formCode, string $roleCode, array $data, array $actor): void {
    $pdo = db();
    $formCode = strtoupper(trim($formCode));
    $roleCode = strtoupper(trim($roleCode));
    $allowedModes = ['read_only', 'print_preview', 'hidden'];
    $mode = $data['non_editor_mode_override'] ?? null;
    if ($mode !== null && $mode !== '' && !in_array($mode, $allowedModes, true)) {
        $mode = null;
    }
    if ($mode === '') {
        $mode = null;
    }

    $stmt = $pdo->prepare("SELECT * FROM form_permissions WHERE form_code = ? AND role_code = ? LIMIT 1");
    $stmt->execute([$formCode, $roleCode]);
    $existing = $stmt->fetch();

    $new = [
        'can_view' => !empty($data['can_view']) ? 1 : 0,
        'can_create' => !empty($data['can_create']) ? 1 : 0,
        'can_edit' => !empty($data['can_edit']) ? 1 : 0,
        'can_submit_final' => !empty($data['can_submit_final']) ? 1 : 0,
        'can_manage_permissions' => !empty($data['can_manage_permissions']) ? 1 : 0,
        'non_editor_mode_override' => $mode,
    ];

    if ($existing) {
        $upd = $pdo->prepare("
            UPDATE form_permissions
            SET can_view = ?, can_create = ?, can_edit = ?, can_submit_final = ?, can_manage_permissions = ?,
                non_editor_mode_override = ?, is_active = 1, updated_at = NOW()
            WHERE permission_id = ?
        ");
        $upd->execute([
            $new['can_view'], $new['can_create'], $new['can_edit'], $new['can_submit_final'],
            $new['can_manage_permissions'], $new['non_editor_mode_override'], $existing['permission_id']
        ]);

        $audit = $pdo->prepare("
            INSERT INTO form_permission_audit
            (form_code, role_code, changed_by_user_id, changed_by_email, action_type,
             old_can_view, old_can_create, old_can_edit, old_can_submit_final, old_can_manage_permissions, old_non_editor_mode_override,
             new_can_view, new_can_create, new_can_edit, new_can_submit_final, new_can_manage_permissions, new_non_editor_mode_override, changed_at)
            VALUES (?, ?, ?, ?, 'UPDATE', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ");
        $audit->execute([
            $formCode, $roleCode, (int)($actor['id'] ?? 0) ?: null, (string)($actor['email'] ?? '') ?: null,
            (int)$existing['can_view'], (int)$existing['can_create'], (int)$existing['can_edit'], (int)$existing['can_submit_final'], (int)$existing['can_manage_permissions'], $existing['non_editor_mode_override'],
            $new['can_view'], $new['can_create'], $new['can_edit'], $new['can_submit_final'], $new['can_manage_permissions'], $new['non_editor_mode_override']
        ]);
    } else {
        $ins = $pdo->prepare("
            INSERT INTO form_permissions
            (form_code, role_code, can_view, can_create, can_edit, can_submit_final, can_manage_permissions, non_editor_mode_override, is_active, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, NOW(), NOW())
        ");
        $ins->execute([
            $formCode, $roleCode, $new['can_view'], $new['can_create'], $new['can_edit'],
            $new['can_submit_final'], $new['can_manage_permissions'], $new['non_editor_mode_override']
        ]);

        $audit = $pdo->prepare("
            INSERT INTO form_permission_audit
            (form_code, role_code, changed_by_user_id, changed_by_email, action_type,
             new_can_view, new_can_create, new_can_edit, new_can_submit_final, new_can_manage_permissions, new_non_editor_mode_override, changed_at)
            VALUES (?, ?, ?, ?, 'INSERT', ?, ?, ?, ?, ?, ?, NOW())
        ");
        $audit->execute([
            $formCode, $roleCode, (int)($actor['id'] ?? 0) ?: null, (string)($actor['email'] ?? '') ?: null,
            $new['can_view'], $new['can_create'], $new['can_edit'], $new['can_submit_final'], $new['can_manage_permissions'], $new['non_editor_mode_override']
        ]);
    }
}

function apply_form_preparer_defaults(int $formRecordId, array $preparer): void {
    $stmt = db()->prepare("
        UPDATE form_records
        SET prepared_by_user_id = ?, prepared_by_name = ?, prepared_by_position_title = ?, updated_at = NOW()
        WHERE id = ?
    ");
    $stmt->execute([
        $preparer['user_id'] ?? null,
        $preparer['name'] ?? null,
        $preparer['position_title'] ?? null,
        $formRecordId
    ]);
}

function submit_form_record(int $formRecordId, string $formType, int $incidentId, int $signerUserId): void {
    $pdo = db();
    $preparer = resolve_form_preparer($incidentId, $formType);
    $signerRole = get_submitter_role_for_form($incidentId, $signerUserId, $formType);

    if ($signerRole === null) {
        throw new RuntimeException('You are not authorized to sign this form.');
    }

    $signerStmt = $pdo->prepare("SELECT id, real_name, username FROM users WHERE id = ? LIMIT 1");
    $signerStmt->execute([$signerUserId]);
    $signer = $signerStmt->fetch();
    if (!$signer) {
        throw new RuntimeException('Unable to load signer record.');
    }

    apply_form_preparer_defaults($formRecordId, $preparer);

    $stmt = $pdo->prepare("
        UPDATE form_records
        SET status = 'submitted',
            prepared_at = COALESCE(prepared_at, NOW()),
            submitted_at = NOW(),
            updated_at = NOW()
        WHERE id = ?
    ");
    $stmt->execute([$formRecordId]);

    $pdo->prepare("DELETE FROM form_signatures WHERE form_record_id = ? AND signature_type = 'prepared_by'")
        ->execute([$formRecordId]);

    $stmt = $pdo->prepare("
        INSERT INTO form_signatures
        (form_record_id, signer_user_id, signer_name, signer_position_title, signature_type, signature_method, signed_at, created_at)
        VALUES (?, ?, ?, ?, 'prepared_by', 'system_attestation', NOW(), NOW())
    ");
    $stmt->execute([
        $formRecordId,
        $signerUserId,
        (string)($signer['real_name'] ?: $signer['username']),
        $signerRole
    ]);

    // Pick the approver from the chain of command right away.
    try {
        require_once __DIR__ . '/signoff.php';
        signoff_auto_assign($formRecordId);
    } catch (Throwable $e) { /* the panel suggests one anyway */ }

    // Permanent record of who submitted what, and when (shown automatically on the ICS 214 activity log).
    try {
        $code = normalize_ics_form_type($formType);
        $opRow = db()->prepare('SELECT operational_period_id FROM form_records WHERE id = ?');
        $opRow->execute([$formRecordId]);
        $opId = (int)($opRow->fetchColumn() ?: 0);
        $period = $opId > 0 ? get_operational_period_by_id($incidentId, $opId) : null;
        audit_log($signerUserId, 'submit', $code, $formRecordId,
            'Submitted and digitally signed ' . ics_audit_form_label($code) . ' (record #' . $formRecordId . ') as ' . $signerRole
            . ($period ? '; operational period: ' . operational_period_label($period) : ''));
    } catch (Throwable $e) { security_log($e); }
}

/** "ICS209" -> "ICS 209", "ICS213RR_V2" -> "ICS 213RR v2", "FINANCE" -> "Finance". */
function ics_audit_form_label(string $code): string {
    $code = strtoupper(trim($code));
    if (preg_match('/^ICS(\d+[A-Z]*)(?:_?V2)?$/', $code, $m)) {
        return 'ICS ' . $m[1] . (str_ends_with($code, 'V2') ? ' v2' : '');
    }
    return ucwords(strtolower(str_replace('_', ' ', $code)));
}

/**
 * Automatic audit trail for form pages: when a form page handles a POST that ends in a redirect or a success message
 * (and no error message), record "saved / submitted / deleted" with the user, form, operational period and time.
 * Forms that already call audit_log() themselves (ICS 201 / 202 / 203 / submit_form_record) are not logged twice.
 * Registered from require_login(); nothing about the submitted values is stored.
 */
function ics_audit_register_form_post_hook(): void {
    static $registered = false;
    if ($registered || ($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { return; }
    $script = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? ''));
    if (!preg_match('#/forms/([a-z0-9_]+)/([a-z0-9_]+)\.php$#i', $script, $m)) { return; }
    if (in_array(strtolower($m[2]), ['pdf', 'print', 'blank', 'file', 'download', 'help'], true)) { return; }
    $registered = true;

    $dir = strtolower($m[1]);
    $action = '';
    foreach (['action', 'form_action', 'submit_action', 'workflow_action'] as $key) {
        if (isset($_POST[$key]) && is_scalar($_POST[$key]) && trim((string)$_POST[$key]) !== '') { $action = (string)$_POST[$key]; break; }
    }
    $action = substr((string)preg_replace('/[^A-Za-z0-9_\-]/', '', $action), 0, 40);
    $flashStart = is_array($_SESSION['flash'] ?? null) ? count($_SESSION['flash']) : 0;
    $userId = (int)(current_user()['id'] ?? 0);

    register_shutdown_function(function () use ($dir, $action, $flashStart, $userId) {
        try {
            // Forms that mark themselves submitted without submit_form_record(): pick the approver now.
            $incAuto = (int)(active_incident()['id'] ?? 0);
            $baseAuto = preg_replace('/_V2$/', '', strtoupper($dir));
            if ($incAuto > 0 && str_starts_with($baseAuto, 'ICS')) {
                $recAuto = get_form_record($incAuto, $baseAuto);
                if ($recAuto && strtolower((string)($recAuto['status'] ?? '')) === 'submitted' && empty($recAuto['current_approver_user_id'])) {
                    require_once __DIR__ . '/signoff.php';
                    signoff_auto_assign((int)$recAuto['id']);
                }
            }
        } catch (Throwable $e) { /* never break a page */ }
        try {
            $code = strtoupper($dir);
            if (!empty($GLOBALS['ics_audit_seen'][$code])) { return; }

            $redirected = false;
            foreach (headers_list() as $h) { if (stripos($h, 'Location:') === 0) { $redirected = true; break; } }
            $flashes = is_array($_SESSION['flash'] ?? null) ? $_SESSION['flash'] : [];
            $newFlashes = count($flashes) >= $flashStart ? array_slice($flashes, $flashStart) : [];
            $hasError = false; $hasSuccess = false;
            foreach ($newFlashes as $f) {
                $t = strtolower((string)($f['type'] ?? ''));
                if (in_array($t, ['error', 'danger'], true)) { $hasError = true; }
                if ($t === 'success') { $hasSuccess = true; }
            }
            if ($hasError || (!$redirected && !$hasSuccess)) { return; }

            $incident = active_incident();
            $incidentId = (int)($incident['id'] ?? 0);
            $recordId = 0; $status = '';
            if ($incidentId > 0 && str_starts_with($code, 'ICS')) {
                $base = preg_replace('/_V2$/', '', $code);
                $rec = get_form_record($incidentId, $base);
                $recordId = (int)($rec['id'] ?? 0);
                $status = (string)($rec['status'] ?? '');
            }
            $period = $incidentId > 0 ? current_operational_period($incidentId) : null;

            $lower = strtolower($action);
            $verb = preg_match('/delete|remove|cancel|void/', $lower) ? 'delete' : (preg_match('/submit|approve|sign|finali/', $lower) ? 'submit' : 'update');
            $label = ics_audit_form_label($code);
            $text = ($verb === 'delete' ? 'Deleted an entry on ' : ($verb === 'submit' ? 'Submitted ' : 'Saved ')) . $label;
            if ($recordId > 0) { $text .= ' (record #' . $recordId . ')'; }
            if ($action !== '') { $text .= '; action: ' . $action; }
            if ($period) { $text .= '; operational period: ' . operational_period_label($period); }
            if ($status !== '') { $text .= '; status: ' . $status; }
            audit_log($userId ?: null, $verb, $code, $recordId, $text);
        } catch (Throwable $e) { /* the audit hook must never break a page */ }
    });
}

function get_form_signature(int $formRecordId, string $signatureType = 'prepared_by'): ?array {
    $stmt = db()->prepare("
        SELECT signer_name, signer_position_title, signed_at
        FROM form_signatures
        WHERE form_record_id = ? AND signature_type = ?
        ORDER BY id DESC
        LIMIT 1
    ");
    $stmt->execute([$formRecordId, $signatureType]);
    $row = $stmt->fetch();
    return $row ?: null;
}



function current_user_can_edit_ics201(?int $incidentId = null): bool {
    return current_user_can_edit_form('ICS201', $incidentId);
}

function storage_root_path(): string {
    return dirname(__DIR__) . '/storage';
}

function ensure_directory(string $path): void {
    if (!is_dir($path) && !mkdir($path, 0775, true) && !is_dir($path)) {
        throw new RuntimeException('Failed to create storage directory.');
    }
}

function get_ics201_upload_dir(int $incidentId): string {
    $path = storage_root_path() . '/uploads/incidents/' . $incidentId . '/ics201';
    ensure_directory($path);
    return $path;
}

function get_ics201_record(int $incidentId): ?array {
    $stmt = db()->prepare("SELECT * FROM form_201_incident_briefing WHERE incident_id = ? ORDER BY id DESC LIMIT 1");
    $stmt->execute([$incidentId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function ensure_ics201_record(int $incidentId, int $userId): array {
    $record = get_ics201_record($incidentId);
    if ($record) {
        return $record;
    }

    $stmt = db()->prepare("
        INSERT INTO form_201_incident_briefing
        (incident_id, prepared_by_user_id, prepared_by_name, prepared_by_position, status, created_at, updated_at)
        VALUES (?, ?, '', '', 'draft', NOW(), NOW())
    ");
    $stmt->execute([$incidentId, $userId]);
    $record = get_ics201_record($incidentId);
    if (!$record) {
        throw new RuntimeException('Unable to create ICS 201 record.');
    }
    return $record;
}

function get_ics201_actions(int $form201Id, ?string $phase = null): array {
    if ($phase !== null) {
        $stmt = db()->prepare("
            SELECT *
            FROM form_201_actions
            WHERE ics201_id = ? AND action_phase = ?
            ORDER BY row_order ASC, id ASC
        ");
        $stmt->execute([$form201Id, $phase]);
        return $stmt->fetchAll();
    }

    $stmt = db()->prepare("
        SELECT *
        FROM form_201_actions
        WHERE ics201_id = ?
        ORDER BY action_phase ASC, row_order ASC, id ASC
    ");
    $stmt->execute([$form201Id]);
    return $stmt->fetchAll();
}

function get_ics201_resources(int $form201Id): array {
    $stmt = db()->prepare("
        SELECT *
        FROM form_201_resources
        WHERE ics201_id = ?
        ORDER BY row_order ASC, id ASC
    ");
    $stmt->execute([$form201Id]);
    return $stmt->fetchAll();
}

function replace_ics201_actions(int $form201Id, string $phase, array $rows): void {
    $phase = strtolower($phase);
    if (!in_array($phase, ['current', 'planned'], true)) {
        throw new RuntimeException('Invalid ICS 201 action phase.');
    }

    $pdo = db();
    $pdo->prepare("DELETE FROM form_201_actions WHERE ics201_id = ? AND action_phase = ?")
        ->execute([$form201Id, $phase]);

    $stmt = $pdo->prepare("
        INSERT INTO form_201_actions
        (ics201_id, action_phase, row_order, action_time, action_text, created_at, updated_at)
        VALUES (?, ?, ?, ?, ?, NOW(), NOW())
    ");

    $order = 1;
    foreach ($rows as $row) {
        $time = trim((string)($row['action_time'] ?? ''));
        $text = trim((string)($row['action_text'] ?? ''));
        if ($time === '' && $text === '') {
            continue;
        }
        $stmt->execute([$form201Id, $phase, $order, $time, $text]);
        $order++;
    }
}

function replace_ics201_resources(int $form201Id, array $rows): void {
    $pdo = db();
    $pdo->prepare("DELETE FROM form_201_resources WHERE ics201_id = ?")
        ->execute([$form201Id]);

    $stmt = $pdo->prepare("
        INSERT INTO form_201_resources
        (ics201_id, row_order, resources_ordered, resource_identification, eta, on_scene, location_assignment, created_at, updated_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
    ");

    $order = 1;
    foreach ($rows as $row) {
        $resourcesOrdered = trim((string)($row['resources_ordered'] ?? ''));
        $resourceIdentification = trim((string)($row['resource_identification'] ?? ''));
        $eta = trim((string)($row['eta'] ?? ''));
        $onScene = trim((string)($row['on_scene'] ?? ''));
        $locationAssignment = trim((string)($row['location_assignment'] ?? ''));
        if ($resourcesOrdered === '' && $resourceIdentification === '' && $eta === '' && $onScene === '' && $locationAssignment === '') {
            continue;
        }
        $stmt->execute([
            $form201Id,
            $order,
            $resourcesOrdered,
            $resourceIdentification,
            $eta,
            $onScene,
            $locationAssignment,
        ]);
        $order++;
    }
}

function get_ics201_attachments(int $form201Id): array {
    $stmt = db()->prepare("
        SELECT
            a.id,
            a.ics201_id,
            a.original_name AS original_filename,
            a.stored_name AS stored_filename,
            a.mime_type,
            a.file_ext,
            a.file_size AS file_size_bytes,
            a.uploaded_by_user_id,
            a.created_at,
            b.incident_id
        FROM form_201_attachments a
        INNER JOIN form_201_incident_briefing b ON b.id = a.ics201_id
        WHERE a.ics201_id = ?
        ORDER BY a.created_at ASC, a.id ASC
    ");
    $stmt->execute([$form201Id]);
    return $stmt->fetchAll();
}

function get_ics201_attachment(int $attachmentId): ?array {
    $stmt = db()->prepare("
        SELECT
            a.id,
            a.ics201_id,
            a.original_name AS original_filename,
            a.stored_name AS stored_filename,
            a.mime_type,
            a.file_ext,
            a.file_size AS file_size_bytes,
            a.uploaded_by_user_id,
            a.created_at,
            b.incident_id
        FROM form_201_attachments a
        INNER JOIN form_201_incident_briefing b ON b.id = a.ics201_id
        WHERE a.id = ?
        LIMIT 1
    ");
    $stmt->execute([$attachmentId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function ics201_attachment_absolute_path(array $attachment): string {
    return get_ics201_upload_dir((int)$attachment['incident_id']) . '/' . $attachment['stored_filename'];
}

function ics201_attachment_url(int $attachmentId): string {
    return app_url('/forms/ics201/file.php?id=' . $attachmentId);
}

function ics201_allowed_extensions_map(): array {
    return [
        'bmp' => ['image/bmp', 'image/x-ms-bmp', 'application/octet-stream'],
        'jpg' => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'png' => ['image/png'],
        'pdf' => ['application/pdf'],
        'txt' => ['text/plain', 'application/octet-stream'],
    ];
}

function normalize_uploaded_extension(string $filename): string {
    return strtolower(pathinfo($filename, PATHINFO_EXTENSION));
}

function upload_error_message(int $code): string {
    return match ($code) {
        UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'The uploaded file is too large.',
        UPLOAD_ERR_PARTIAL => 'The file upload was incomplete.',
        UPLOAD_ERR_NO_FILE => 'No file was uploaded.',
        UPLOAD_ERR_NO_TMP_DIR => 'The server upload temp directory is missing.',
        UPLOAD_ERR_CANT_WRITE => 'The server could not save the uploaded file.',
        UPLOAD_ERR_EXTENSION => 'A server extension blocked the upload.',
        default => 'Unknown upload error.',
    };
}

function save_ics201_uploads(int $form201Id, int $incidentId, array $files, int $uploadedByUserId): array {
    if (!isset($files['name']) || !is_array($files['name'])) {
        return [];
    }

    $saved = [];
    $allowed = ics201_allowed_extensions_map();
    $dir = get_ics201_upload_dir($incidentId);
    $pdo = db();
    $finfo = new finfo(FILEINFO_MIME_TYPE);

    $count = count($files['name']);
    for ($i = 0; $i < $count; $i++) {
        $error = (int)($files['error'][$i] ?? UPLOAD_ERR_NO_FILE);
        $originalName = (string)($files['name'][$i] ?? '');
        if ($error === UPLOAD_ERR_NO_FILE || trim($originalName) === '') {
            continue;
        }
        if ($error !== UPLOAD_ERR_OK) {
            throw new RuntimeException(upload_error_message($error) . ' File: ' . $originalName);
        }

        $tmpName = (string)($files['tmp_name'][$i] ?? '');
        $size = (int)($files['size'][$i] ?? 0);
        if ($size <= 0) {
            throw new RuntimeException('Uploaded file is empty: ' . $originalName);
        }
        if ($size > 15 * 1024 * 1024) {
            throw new RuntimeException('Uploaded file exceeds the 15 MB limit: ' . $originalName);
        }

        $ext = normalize_uploaded_extension($originalName);
        if (!isset($allowed[$ext])) {
            throw new RuntimeException('Unsupported file type: ' . $originalName);
        }

        $mime = (string)$finfo->file($tmpName);
        if (!in_array($mime, $allowed[$ext], true) && !($ext === 'txt' && str_starts_with($mime, 'text/'))) {
            throw new RuntimeException('File type verification failed for: ' . $originalName);
        }

        if (in_array($ext, ['bmp', 'jpg', 'jpeg', 'png'], true)) {
            $sizeInfo = @getimagesize($tmpName);
            if ($sizeInfo === false) {
                throw new RuntimeException('The uploaded image file is invalid: ' . $originalName);
            }
        }

        $storedFilename = sprintf(
            '201_%d_%s_%s.%s',
            $form201Id,
            bin2hex(random_bytes(6)),
            date('YmdHis'),
            $ext
        );

        $destination = $dir . '/' . $storedFilename;
        if (!move_uploaded_file($tmpName, $destination) && !rename($tmpName, $destination)) {
            throw new RuntimeException('Failed to store uploaded file: ' . $originalName);
        }

        $stmt = $pdo->prepare("
            INSERT INTO form_201_attachments
            (ics201_id, original_name, stored_name, mime_type, file_ext, file_size, uploaded_by_user_id, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
        ");
        $stmt->execute([
            $form201Id,
            $originalName,
            $storedFilename,
            $mime,
            $ext,
            $size,
            $uploadedByUserId
        ]);

        $saved[] = [
            'id' => (int)$pdo->lastInsertId(),
            'original_filename' => $originalName,
            'stored_filename' => $storedFilename,
            'file_ext' => $ext,
        ];
    }

    return $saved;
}

function delete_ics201_attachment(int $attachmentId): void {
    $attachment = get_ics201_attachment($attachmentId);
    if (!$attachment) {
        return;
    }

    $stmt = db()->prepare("DELETE FROM form_201_attachments WHERE id = ?");
    $stmt->execute([$attachmentId]);

    $path = ics201_attachment_absolute_path($attachment);
    if (is_file($path)) {
        @unlink($path);
    }
}

function extension_to_badge_label(string $ext): string {
    return strtoupper($ext);
}


function format_bytes_human(int $bytes): string {
    if ($bytes < 1024) {
        return $bytes . ' B';
    }
    if ($bytes < 1048576) {
        return round($bytes / 1024, 1) . ' KB';
    }
    if ($bytes < 1073741824) {
        return round($bytes / 1048576, 1) . ' MB';
    }
    return round($bytes / 1073741824, 1) . ' GB';
}


function extract_docx_text_preview(string $path, int $maxChars = 5000): string {
    if (!class_exists('ZipArchive')) {
        return '';
    }
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) {
        return '';
    }
    $xml = $zip->getFromName('word/document.xml') ?: '';
    $zip->close();
    if ($xml === '') {
        return '';
    }

    $text = preg_replace('/<\/w:p>/', "\n", $xml);
    $text = strip_tags((string)$text);
    $text = html_entity_decode($text, ENT_QUOTES | ENT_XML1, 'UTF-8');
    $text = preg_replace('/\s+/', ' ', $text ?? '');
    $text = trim((string)$text);
    if (mb_strlen($text) > $maxChars) {
        $text = mb_substr($text, 0, $maxChars) . '…';
    }
    return $text;
}

function extract_text_file_preview(string $path, int $maxChars = 5000): string {
    $contents = @file_get_contents($path);
    if ($contents === false) {
        return '';
    }
    $text = mb_convert_encoding($contents, 'UTF-8', 'UTF-8, ISO-8859-1, Windows-1252');
    $text = trim((string)$text);
    if (mb_strlen($text) > $maxChars) {
        $text = mb_substr($text, 0, $maxChars) . '…';
    }
    return $text;
}

function ics201_print_preview_html(array $attachment): string {
    $ext = strtolower((string)$attachment['file_ext']);
    $url = e(ics201_attachment_url((int)$attachment['id']));
    $path = ics201_attachment_absolute_path($attachment);
    $label = e((string)$attachment['original_filename']);

    if (in_array($ext, ['bmp', 'jpg', 'jpeg', 'png'], true)) {
        return '<img src="' . $url . '" alt="' . $label . '" class="attachment-image">';
    }

    if ($ext === 'pdf') {
        return '<object data="' . $url . '#toolbar=0&navpanes=0&scrollbar=0" type="application/pdf" class="attachment-pdf"><p>PDF preview not available in this browser. Attachment: ' . $label . '</p></object>';
    }

    if ($ext === 'txt') {
        $text = e(extract_text_file_preview($path));
        return '<pre class="attachment-text">' . $text . '</pre>';
    }

    if ($ext === 'docx') {
        $text = e(extract_docx_text_preview($path));
        if ($text !== '') {
            return '<pre class="attachment-text">' . $text . '</pre>';
        }
    }

    return '<p class="small">Preview is not available for this file type in the browser print view. Keep the file attached with the incident record and export it separately if needed.</p>';
}


function user_has_role_code(string $roleCode, ?int $incidentId = null, ?int $userId = null): bool {
    $user = current_user();
    $userId = $userId ?: (int)($user['id'] ?? 0);
    $incidentId = $incidentId ?: (int)($user['incident_id'] ?? 0) ?: active_incident_id();
    if ($userId <= 0 || $incidentId <= 0) { return false; }
    if (!empty($user['is_tsc_admin'])) { return true; }
    try {
        return in_array(strtoupper($roleCode), get_user_role_codes($incidentId, $userId), true);
    } catch (Throwable $e) { return false; }
}

function ics213rr_create_notification(int $incidentId, ?int $userId, ?string $roleCode, string $type, string $title, string $body, string $linkUrl, ?int $relatedId = null, ?string $urgency = null): void {
    try {
        if (($urgency === null || $urgency === '') && $relatedId) {
            try {
                $st = db()->prepare("SELECT priority_level FROM form_213rr_headers WHERE id=? LIMIT 1");
                $st->execute([$relatedId]);
                $urgency = (string)($st->fetchColumn() ?: '');
            } catch (Throwable $ignore) {}
        }

        $formRecordId = null;
        if ($relatedId) {
            try {
                $st = db()->prepare("SELECT form_record_id FROM form_213rr_headers WHERE id=? LIMIT 1");
                $st->execute([$relatedId]);
                $formRecordId = (int)($st->fetchColumn() ?: 0) ?: null;
            } catch (Throwable $ignore) {}
        }

        $urgency = $urgency ? strtolower($urgency) : 'routine';
        $stmt = db()->prepare("INSERT INTO notifications
            (incident_id, user_id, role_code, form_record_id, notification_type, title, subject, body, link_url, related_table, related_id, urgency, is_read, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'form_213rr_headers', ?, ?, 0, NOW())");
        $stmt->execute([
            $incidentId ?: null,
            $userId ?: null,
            $roleCode ? strtoupper($roleCode) : null,
            $formRecordId,
            $type,
            $title,
            $title,
            $body,
            $linkUrl,
            $relatedId,
            $urgency
        ]);
    } catch (Throwable $e) {
        error_log('ICS213RR notification insert failed: ' . $e->getMessage());
    }
}

function ics213rr_next_request_number(int $incidentId): string {
    try {
        $stmt = db()->prepare("SELECT COUNT(*) AS c FROM form_213rr_headers h INNER JOIN form_records fr ON fr.id=h.form_record_id WHERE fr.incident_id = ? AND fr.form_type='ICS213RR'");
        $stmt->execute([$incidentId]);
        $n = ((int)($stmt->fetch()['c'] ?? 0)) + 1;
    } catch (Throwable $e) { $n = random_int(1,9999); }
    return 'RR-' . date('Y') . '-' . str_pad((string)$n, 4, '0', STR_PAD_LEFT);
}

function ics213rr_log_status(int $headerId, ?string $from, string $to, string $comment = '', ?array $actor = null): void {
    try {
        // The external Check-In synchronization worker runs without a web session.
        $u = [];
        if (is_array($actor)) {
            $u = $actor;
        } elseif (function_exists('current_user')) {
            $currentUser = current_user();
            if (is_array($currentUser)) {
                $u = $currentUser;
            }
        }

        $stmt = db()->prepare("INSERT INTO form_213rr_status_history
            (header_id, from_status, to_status, changed_by_user_id, changed_by_name, comment, created_at)
            VALUES (?, ?, ?, ?, ?, ?, NOW())");
        $stmt->execute([$headerId, $from, $to, (int)($u['id'] ?? 0) ?: null, (string)($u['display_name'] ?? ''), $comment]);
    } catch (Throwable $e) { security_log($e); }
}

function current_user_can_ics213rr_action(string $action, ?int $incidentId = null): bool {
    $user = current_user();
    if (!$user) { return false; }
    if (!empty($user['is_tsc_admin'])) { return true; }
    $incidentId = $incidentId ?: (int)($user['incident_id'] ?? 0) ?: active_incident_id();
    $perms = get_current_user_form_permissions('ICS213RR', $incidentId);
    if ($action === 'view') { return true; }
    if ($action === 'create') { return true; }
    // Editing is intentionally limited to early/revision states in forms/ics213rr_v2/index.php.
    if ($action === 'edit') { return true; }
    if ($action === 'section_approve') { return !empty($perms['can_submit_final']) || user_has_role_code('IC',$incidentId) || user_has_role_code('OSC',$incidentId) || user_has_role_code('PSC',$incidentId) || user_has_role_code('LSC',$incidentId) || user_has_role_code('FSC',$incidentId); }
    if ($action === 'logistics') { return user_has_role_code('LSC',$incidentId) || user_has_role_code('SPUL',$incidentId) || user_has_role_code('PROC',$incidentId); }
    // Finance/Admin work must be completed from /forms/finance/ only.
    if ($action === 'finance') { return user_has_role_code('FSC',$incidentId) || user_has_role_code('PROC',$incidentId) || user_has_role_code('COST',$incidentId) || user_has_role_code('TIME',$incidentId) || user_has_role_code('COMP',$incidentId); }
    if ($action === 'close') { return user_has_role_code('IC',$incidentId) || user_has_role_code('DOCL',$incidentId) || user_has_role_code('LSC',$incidentId) || user_has_role_code('FSC',$incidentId); }
    return false;
}

function current_user_can_resources_view(?int $incidentId = null): bool {
    $user = current_user();
    if (!$user) { return false; }
    if (!empty($user['is_tsc_admin'])) { return true; }
    $incidentId = $incidentId ?: (int)($user['incident_id'] ?? 0) ?: active_incident_id();

    // Database-driven permission. Configure rows for form_code RESOURCES_UNIT in form_permissions.
    $perms = get_current_user_form_permissions('RESOURCES_UNIT', $incidentId);
    return !empty($perms['can_view']);
}

function current_user_can_resources_manage(?int $incidentId = null): bool {
    $user = current_user();
    if (!$user) { return false; }
    if (!empty($user['is_tsc_admin'])) { return true; }
    $incidentId = $incidentId ?: (int)($user['incident_id'] ?? 0) ?: active_incident_id();

    // Database-driven permission. RESL/TSC should normally have can_edit/can_submit_final.
    $perms = get_current_user_form_permissions('RESOURCES_UNIT', $incidentId);
    return !empty($perms['can_edit']) || !empty($perms['can_submit_final']);
}

function current_user_can_finance_view(?int $incidentId = null): bool {
    $user = current_user();
    if (!$user) { return false; }
    if (!empty($user['is_tsc_admin'])) { return true; }
    $incidentId = $incidentId ?: (int)($user['incident_id'] ?? 0) ?: active_incident_id();

    // Database-driven permission. Configure rows for form_code FINANCE_ADMIN in form_permissions.
    $perms = get_current_user_form_permissions('FINANCE_ADMIN', $incidentId);
    return !empty($perms['can_view']);
}

function current_user_can_finance_manage(?int $incidentId = null): bool {
    $user = current_user();
    if (!$user) { return false; }
    if (!empty($user['is_tsc_admin'])) { return true; }
    $incidentId = $incidentId ?: (int)($user['incident_id'] ?? 0) ?: active_incident_id();

    // Database-driven permission. FSC/Finance subordinates/TSC should normally have can_edit/can_submit_final.
    $perms = get_current_user_form_permissions('FINANCE_ADMIN', $incidentId);
    return !empty($perms['can_edit']) || !empty($perms['can_submit_final']);
}



function table_columns(string $tableName): array {
    static $cache = [];
    if (isset($cache[$tableName])) { return $cache[$tableName]; }
    try {
        $stmt = db()->query("SHOW COLUMNS FROM `" . str_replace('`', '', $tableName) . "`");
        $cache[$tableName] = array_map(fn($row) => (string)$row['Field'], $stmt->fetchAll());
    } catch (Throwable $e) {
        $cache[$tableName] = [];
    }
    return $cache[$tableName];
}

function current_operational_period_copyable_form_types(): array {
    return ['ICS202','ICS203','ICS204','ICS205','ICS205A','ICS206','ICS207','ICS208','ICS209','ICS215A'];
}


function operational_period_unsubmitted_forms(int $incidentId, int $operationalPeriodId): array {
    if ($incidentId <= 0 || $operationalPeriodId <= 0) { return []; }
    $stmt = db()->prepare("
        SELECT fr.id, fr.form_type, fr.form_number, fr.status, fr.prepared_by_user_id,
               fr.prepared_by_name, fr.prepared_by_position_title, fr.updated_at,
               COALESCE(fd.form_name, f.form_title, fr.form_type) AS form_name
        FROM form_records fr
        LEFT JOIN form_definitions fd ON UPPER(fd.form_type)=UPPER(fr.form_type)
        LEFT JOIN forms f ON UPPER(f.form_code)=UPPER(fr.form_type)
        WHERE fr.incident_id = ?
          AND fr.operational_period_id = ?
          AND fr.is_deleted = 0
          AND fr.status IN ('draft','rejected')
        ORDER BY fr.form_number ASC, fr.form_type ASC, fr.id ASC
    ");
    $stmt->execute([$incidentId, $operationalPeriodId]);
    return $stmt->fetchAll();
}


function operational_period_copied_forms_requiring_review(int $incidentId, int $operationalPeriodId): array {
    if ($incidentId <= 0 || $operationalPeriodId <= 0) { return []; }
    $columns = table_columns('form_records');
    if (!in_array('review_required', $columns, true) || !in_array('copied_from_form_record_id', $columns, true)) {
        return [];
    }
    $stmt = db()->prepare("
        SELECT fr.id, fr.form_type, fr.form_number, fr.status, fr.review_required,
               fr.copied_from_form_record_id, fr.copied_from_operational_period_id,
               fr.prepared_by_user_id, fr.prepared_by_name, fr.prepared_by_position_title,
               fr.responsible_position_code, fr.responsible_position_title, fr.assigned_to_user_id,
               fr.reviewed_by_user_id, fr.reviewed_by_name, fr.reviewed_by_position_title, fr.reviewed_at,
               fr.updated_at,
               srcop.period_number AS copied_from_period_number,
               srcop.period_name AS copied_from_period_name,
               COALESCE(fd.form_name, f.form_title, fr.form_type) AS form_name
        FROM form_records fr
        LEFT JOIN operational_periods srcop ON srcop.id = fr.copied_from_operational_period_id
        LEFT JOIN form_definitions fd ON UPPER(fd.form_type)=UPPER(fr.form_type)
        LEFT JOIN forms f ON UPPER(f.form_code)=UPPER(fr.form_type)
        WHERE fr.incident_id = ?
          AND fr.operational_period_id = ?
          AND fr.is_deleted = 0
          AND fr.review_required = 1
          AND fr.copied_from_form_record_id IS NOT NULL
          AND fr.status IN ('draft','rejected','submitted')
        ORDER BY fr.form_number ASC, fr.form_type ASC, fr.id ASC
    ");
    $stmt->execute([$incidentId, $operationalPeriodId]);
    return $stmt->fetchAll();
}

function create_copied_form_review_notifications(int $incidentId, int $operationalPeriodId): int {
    $forms = operational_period_copied_forms_requiring_review($incidentId, $operationalPeriodId);
    if (!$forms) { return 0; }
    $pdo = db();
    $created = 0;
    foreach ($forms as $form) {
        $formId = (int)$form['id'];
        $formLabel = trim('ICS ' . (string)$form['form_number'] . ' - ' . (string)($form['form_name'] ?: $form['form_type']));
        $subject = 'Review required for current operational period: ' . $formLabel;
        $body = $formLabel . ' was copied from the previous operational period and must be reviewed, updated if needed, and submitted/approved for the current operational period.';
        $slug = strtolower(str_replace('ICS', 'ics', (string)$form['form_type']));
        $link = app_url('/forms/' . $slug . '/index.php');

        $roleTargets = ['IC','PSC','PLANNING_SECTION_CHIEF','TSC','ADMIN'];
        foreach ($roleTargets as $roleCode) {
            try {
                $stmt = $pdo->prepare("
                    INSERT INTO notifications (incident_id, user_id, role_code, form_record_id, notification_type, title, subject, body, link_url, related_table, related_id, urgency, is_read, created_at)
                    VALUES (?, NULL, ?, ?, 'copied_form_review_required', ?, ?, ?, ?, 'form_records', ?, 'high', 0, NOW())
                ");
                $stmt->execute([$incidentId, $roleCode, $formId, $subject, $subject, $body, $link, $formId]);
                $created++;
            } catch (Throwable $e) { security_log($e); }
        }
        if (!empty($form['assigned_to_user_id'])) {
            try {
                $stmt = $pdo->prepare("
                    INSERT INTO notifications (incident_id, user_id, role_code, form_record_id, notification_type, title, subject, body, link_url, related_table, related_id, urgency, is_read, created_at)
                    VALUES (?, ?, NULL, ?, 'copied_form_review_required', ?, ?, ?, ?, 'form_records', ?, 'high', 0, NOW())
                ");
                $stmt->execute([$incidentId, (int)$form['assigned_to_user_id'], $formId, $subject, $subject, $body, $link, $formId]);
                $created++;
            } catch (Throwable $e) { security_log($e); }
        }
        if (!empty($form['prepared_by_user_id'])) {
            try {
                $stmt = $pdo->prepare("
                    INSERT INTO notifications (incident_id, user_id, role_code, form_record_id, notification_type, title, subject, body, link_url, related_table, related_id, urgency, is_read, created_at)
                    VALUES (?, ?, NULL, ?, 'copied_form_review_required', ?, ?, ?, ?, 'form_records', ?, 'high', 0, NOW())
                ");
                $stmt->execute([$incidentId, (int)$form['prepared_by_user_id'], $formId, $subject, $subject, $body, $link, $formId]);
                $created++;
            } catch (Throwable $e) { security_log($e); }
        }
    }
    audit_log((int)(current_user()['id'] ?? 0) ?: null, 'create_copied_form_review_notifications', 'operational_period', $operationalPeriodId, 'Created review-required notifications for copied-forward forms.');
    return $created;
}

function create_form_closeout_notifications(int $incidentId, int $operationalPeriodId): int {
    $forms = operational_period_unsubmitted_forms($incidentId, $operationalPeriodId);
    if (!$forms) { return 0; }
    $created = 0;
    $pdo = db();
    foreach ($forms as $form) {
        $formId = (int)$form['id'];
        $formLabel = trim('ICS ' . (string)$form['form_number'] . ' - ' . (string)($form['form_name'] ?: $form['form_type']));
        $subject = 'Submit required form before operational period ends: ' . $formLabel;
        $body = $formLabel . ' is still in ' . (string)$form['status'] . ' status for the current operational period. Please review and submit it before the operational period ends.';
        $slug = strtolower(str_replace('ICS', 'ics', (string)$form['form_type']));
        $link = app_url('/forms/' . $slug . '/index.php');
        $targets = [];
        if (!empty($form['prepared_by_user_id'])) { $targets[] = (int)$form['prepared_by_user_id']; }
        foreach (['IC','PSC','PLANNING_SECTION_CHIEF','TSC','ADMIN'] as $roleCode) {
            try {
                $stmt = $pdo->prepare("
                    INSERT INTO notifications (incident_id, user_id, role_code, form_record_id, notification_type, title, subject, body, link_url, related_table, related_id, urgency, is_read, created_at)
                    VALUES (?, NULL, ?, ?, 'op_closeout_submit_required', ?, ?, ?, ?, 'form_records', ?, 'high', 0, NOW())
                ");
                $stmt->execute([$incidentId, $roleCode, $formId, $subject, $subject, $body, $link, $formId]);
                $created++;
            } catch (Throwable $e) { security_log($e); }
        }
        foreach (array_unique($targets) as $userId) {
            try {
                $stmt = $pdo->prepare("
                    INSERT INTO notifications (incident_id, user_id, role_code, form_record_id, notification_type, title, subject, body, link_url, related_table, related_id, urgency, is_read, created_at)
                    VALUES (?, ?, NULL, ?, 'op_closeout_submit_required', ?, ?, ?, ?, 'form_records', ?, 'high', 0, NOW())
                ");
                $stmt->execute([$incidentId, $userId, $formId, $subject, $subject, $body, $link, $formId]);
                $created++;
            } catch (Throwable $e) { security_log($e); }
        }
    }
    audit_log((int)(current_user()['id'] ?? 0) ?: null, 'create_closeout_notifications', 'operational_period', $operationalPeriodId, 'Created submit-before-period-end notifications for ' . count($forms) . ' unsubmitted forms.');
    return $created;
}

function closeout_submit_forms_on_behalf(int $incidentId, int $operationalPeriodId, array $formRecordIds, int $userId, string $reason): int {
    $formRecordIds = array_values(array_unique(array_filter(array_map('intval', $formRecordIds))));
    if ($incidentId <= 0 || $operationalPeriodId <= 0 || !$formRecordIds) { return 0; }
    $user = current_user() ?: [];
    $submitterName = (string)($user['display_name'] ?? $user['username'] ?? 'System user');
    $submitterPosition = !empty($user['is_tsc_admin']) ? 'Admin/TSC' : (string)($user['role_code'] ?? 'Authorized user');
    $pdo = db();
    $columns = table_columns('form_records');
    $updated = 0;
    $pdo->beginTransaction();
    try {
        $placeholders = implode(',', array_fill(0, count($formRecordIds), '?'));
        $stmt = $pdo->prepare("SELECT * FROM form_records WHERE incident_id=? AND operational_period_id=? AND is_deleted=0 AND status IN ('draft','rejected') AND id IN ($placeholders) FOR UPDATE");
        $stmt->execute(array_merge([$incidentId, $operationalPeriodId], $formRecordIds));
        $forms = $stmt->fetchAll();
        foreach ($forms as $form) {
            $sets = ["status='submitted'", "submitted_at=NOW()"];
            $params = [];
            foreach ([
                'submitted_by_user_id' => $userId ?: null,
                'submitted_by_name' => $submitterName,
                'submitted_by_position_title' => $submitterPosition,
                'submitted_on_behalf_of_user_id' => $form['prepared_by_user_id'] ?? null,
                'submitted_on_behalf_of_name' => $form['prepared_by_name'] ?? null,
                'submitted_on_behalf_of_position_title' => $form['prepared_by_position_title'] ?? null,
                'submitted_on_behalf_reason' => $reason,
                'closeout_submitted' => 1,
                'closeout_submitted_at' => date('Y-m-d H:i:s'),
                'closeout_submitted_by_user_id' => $userId ?: null,
            ] as $col => $val) {
                if (in_array($col, $columns, true)) { $sets[] = "`$col`=?"; $params[] = $val; }
            }
            $params[] = (int)$form['id'];
            $upd = $pdo->prepare("UPDATE form_records SET " . implode(',', $sets) . " WHERE id=?");
            $upd->execute($params);
            try {
                $sig = $pdo->prepare("INSERT INTO form_signatures (form_record_id, signer_user_id, signer_name, signer_position_title, signature_type, signature_method, signature_hash, signed_at, created_at) VALUES (?, ?, ?, ?, 'submitted_by', 'system_attestation', ?, NOW(), NOW())");
                $sig->execute([(int)$form['id'], $userId, $submitterName, $submitterPosition, hash('sha256', (int)$form['id'] . '|' . $userId . '|' . microtime(true))]);
            } catch (Throwable $e) { security_log($e); }
            audit_log($userId ?: null, 'closeout_submit_on_behalf', 'form_record', (int)$form['id'], json_encode(['operational_period_id'=>$operationalPeriodId,'previous_status'=>$form['status'],'new_status'=>'submitted','reason'=>$reason], JSON_UNESCAPED_SLASHES));
            $updated++;
        }
        $pdo->commit();
        return $updated;
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function create_operational_period_from_previous(int $incidentId, int $periodNumber, string $periodName, string $opStart, string $opEnd, int $previousOperationalPeriodId, array $copyTypes, int $userId): int {
    $pdo = db();
    $copyTypes = array_values(array_intersect(array_map('strtoupper', $copyTypes), current_operational_period_copyable_form_types()));

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("
            INSERT INTO operational_periods (incident_id, period_number, period_name, op_start, op_end, status, created_by_user_id, updated_by_user_id, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, 'planned', ?, ?, NOW(), NOW())
        ");
        $stmt->execute([$incidentId, $periodNumber, $periodName !== '' ? $periodName : null, $opStart, $opEnd, $userId ?: null, $userId ?: null]);
        $newOpId = (int)$pdo->lastInsertId();

        if ($previousOperationalPeriodId > 0 && $copyTypes) {
            $placeholders = implode(',', array_fill(0, count($copyTypes), '?'));
            $stmt = $pdo->prepare("
                SELECT *
                FROM form_records
                WHERE incident_id = ?
                  AND (operational_period_id = ? OR operational_period_id IS NULL)
                  AND is_deleted = 0
                  AND (
                      REPLACE(REPLACE(UPPER(form_type), ' ', ''), '-', '') IN ($placeholders)
                      OR CONCAT('ICS', REPLACE(REPLACE(UPPER(form_type), ' ', ''), '-', '')) IN ($placeholders)
                      OR REPLACE(REPLACE(UPPER(form_number), ' ', ''), '-', '') IN ($placeholders)
                      OR CONCAT('ICS', REPLACE(REPLACE(UPPER(form_number), ' ', ''), '-', '')) IN ($placeholders)
                  )
                ORDER BY
                  CASE WHEN operational_period_id = ? THEN 0 ELSE 1 END,
                  updated_at DESC,
                  id DESC
            ");
            $stmt->execute(array_merge([$incidentId, $previousOperationalPeriodId], $copyTypes, $copyTypes, $copyTypes, $copyTypes, [$previousOperationalPeriodId]));
            $rows = $stmt->fetchAll();

            $columns = table_columns('form_records');
            $seenCopyTypes = [];
            foreach ($rows as $row) {
                $rowTypeNorm = strtoupper(str_replace([' ', '-'], '', (string)($row['form_type'] ?? '')));
                if ($rowTypeNorm !== '' && !str_starts_with($rowTypeNorm, 'ICS')) { $rowTypeNorm = 'ICS' . $rowTypeNorm; }
                $rowNumNorm = strtoupper(str_replace([' ', '-'], '', (string)($row['form_number'] ?? '')));
                if ($rowNumNorm !== '' && !str_starts_with($rowNumNorm, 'ICS')) { $rowNumNorm = 'ICS' . $rowNumNorm; }
                $normKey = in_array($rowTypeNorm, $copyTypes, true) ? $rowTypeNorm : $rowNumNorm;
                if ($normKey !== '' && isset($seenCopyTypes[$normKey])) { continue; }
                if ($normKey !== '') { $seenCopyTypes[$normKey] = true; }
                $insert = [
                    'incident_id' => $incidentId,
                    'operational_period_id' => $newOpId,
                    'form_type' => $row['form_type'],
                    'form_number' => $row['form_number'],
                    'status' => 'draft',
                    'prepared_by_user_id' => $userId ?: null,
                    'prepared_by_name' => null,
                    'prepared_by_position_title' => null,
                    'prepared_at' => null,
                    'submitted_at' => null,
                    'approved_at' => null,
                    'current_approver_user_id' => null,
                    'is_deleted' => 0,
                    'deleted_at' => null,
                ];
                if (in_array('copied_from_form_record_id', $columns, true)) { $insert['copied_from_form_record_id'] = (int)$row['id']; }
                if (in_array('copied_from_operational_period_id', $columns, true)) { $insert['copied_from_operational_period_id'] = $previousOperationalPeriodId; }
                if (in_array('review_required', $columns, true)) { $insert['review_required'] = 1; }

                $fieldList = array_keys(array_filter($insert, fn($v, $k) => in_array($k, $columns, true), ARRAY_FILTER_USE_BOTH));
                $sql = "INSERT INTO form_records (`" . implode('`,`', $fieldList) . "`) VALUES (" . implode(',', array_fill(0, count($fieldList), '?')) . ")";
                $ins = $pdo->prepare($sql);
                $ins->execute(array_map(fn($k) => $insert[$k], $fieldList));
            }
        }

        seed_operational_period_position_assignments($incidentId, $newOpId, $previousOperationalPeriodId > 0 ? $previousOperationalPeriodId : null);
        assign_review_required_forms_to_positions($incidentId, $newOpId);
        audit_log($userId ?: null, 'create_operational_period', 'operational_period', $newOpId, 'Created operational period ' . $periodNumber . ', copied selected forms as draft/review-required records, and seeded position assignments.');
        create_copied_form_review_notifications($incidentId, $newOpId);
        $pdo->commit();
        return $newOpId;
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}


function form_type_default_responsible_position(string $formType): array {
    $type = strtoupper(str_replace(' ', '', $formType));
    $map = [
        'ICS202' => ['PSC' => 'Planning Section Chief', 'IC' => 'Incident Commander'],
        'ICS203' => ['PSC' => 'Planning Section Chief'],
        'ICS204' => ['OSC' => 'Operations Section Chief', 'PSC' => 'Planning Section Chief'],
        'ICS205' => ['COML' => 'Communications Unit Leader', 'LSC' => 'Logistics Section Chief'],
        'ICS205A' => ['COML' => 'Communications Unit Leader', 'LSC' => 'Logistics Section Chief'],
        'ICS206' => ['MEDL' => 'Medical Unit Leader', 'LSC' => 'Logistics Section Chief'],
        'ICS207' => ['PSC' => 'Planning Section Chief'],
        'ICS208' => ['SOFR' => 'Safety Officer'],
        'ICS215' => ['OSC' => 'Operations Section Chief', 'PSC' => 'Planning Section Chief'],
        'ICS215A' => ['SOFR' => 'Safety Officer'],
        'ICS220' => ['AOBD' => 'Air Operations Branch Director', 'OSC' => 'Operations Section Chief'],
    ];
    return $map[$type] ?? ['PSC' => 'Planning Section Chief'];
}

function operational_period_assignment_table_exists(): bool {
    static $exists = null;
    if ($exists !== null) { return $exists; }
    try {
        $stmt = db()->query("SHOW TABLES LIKE 'operational_period_position_assignments'");
        $exists = (bool)$stmt->fetchColumn();
    } catch (Throwable $e) { $exists = false; }
    return $exists;
}

function operational_period_position_assignments(int $incidentId, int $operationalPeriodId): array {
    if (!operational_period_assignment_table_exists() || $incidentId <= 0 || $operationalPeriodId <= 0) { return []; }
    $stmt = db()->prepare("
        SELECT a.*, u.real_name AS user_real_name, u.username, u.email
        FROM operational_period_position_assignments a
        LEFT JOIN users u ON u.id = a.user_id
        WHERE a.incident_id = ? AND a.operational_period_id = ? AND a.deleted_at IS NULL
        ORDER BY a.position_title ASC, a.id ASC
    ");
    $stmt->execute([$incidentId, $operationalPeriodId]);
    return $stmt->fetchAll();
}

function operational_period_current_position_user_map(int $incidentId, int $operationalPeriodId): array {
    $rows = operational_period_position_assignments($incidentId, $operationalPeriodId);
    $map = [];
    foreach ($rows as $row) {
        $code = strtoupper((string)$row['position_code']);
        if ($code !== '') { $map[$code] = $row; }
    }
    return $map;
}

function ensure_user_role_operational_period_assignment(int $incidentId, int $operationalPeriodId, int $userId, int $roleId, ?int $actorUserId = null): bool {
    if (!operational_period_assignment_table_exists()
        || $incidentId <= 0
        || $operationalPeriodId <= 0
        || $userId <= 0
        || $roleId <= 0) {
        return false;
    }

    $pdo = db();
    $opStmt = $pdo->prepare("
        SELECT id
        FROM operational_periods
        WHERE id = ? AND incident_id = ? AND deleted_at IS NULL
        LIMIT 1
    ");
    $opStmt->execute([$operationalPeriodId, $incidentId]);
    if (!$opStmt->fetch()) {
        return false;
    }

    $roleStmt = $pdo->prepare("
        SELECT r.role_code, r.role_name, u.real_name, u.username
        FROM incident_positions ip
        INNER JOIN roles r ON r.id = ip.role_id
        INNER JOIN users u ON u.id = ip.user_id
        WHERE ip.incident_id = ?
          AND ip.user_id = ?
          AND ip.role_id = ?
          AND ip.deleted_at IS NULL
          AND (ip.assigned_end IS NULL OR ip.assigned_end >= NOW())
          AND u.deleted_at IS NULL
        LIMIT 1
    ");
    $roleStmt->execute([$incidentId, $userId, $roleId]);
    $role = $roleStmt->fetch();
    if (!$role) {
        return false;
    }

    $positionCode = strtoupper(trim((string)$role['role_code']));
    if ($positionCode === '') {
        return false;
    }

    $existingStmt = $pdo->prepare("
        SELECT id, user_id, assigned_name, assignment_status
        FROM operational_period_position_assignments
        WHERE incident_id = ?
          AND operational_period_id = ?
          AND position_code = ?
          AND deleted_at IS NULL
        ORDER BY id ASC
    ");
    $existingStmt->execute([$incidentId, $operationalPeriodId, $positionCode]);
    $existingRows = $existingStmt->fetchAll();

    foreach ($existingRows as $existing) {
        $existingUserId = (int)($existing['user_id'] ?? 0);
        $existingName = trim((string)($existing['assigned_name'] ?? ''));
        $existingStatus = (string)($existing['assignment_status'] ?? '');

        if ($existingUserId === $userId) {
            return false;
        }

        // Preserve an assignment already held by another user or external person.
        if ($existingUserId > 0 || $existingName !== '' || $existingStatus !== 'vacant') {
            return false;
        }
    }

    $assignedName = trim((string)($role['real_name'] ?: $role['username'] ?: ''));
    $vacantAssignmentId = (int)($existingRows[0]['id'] ?? 0);
    if ($vacantAssignmentId > 0) {
        $update = $pdo->prepare("
            UPDATE operational_period_position_assignments
            SET position_title = ?,
                user_id = ?,
                assigned_name = ?,
                assignment_status = 'assigned',
                starts_at = COALESCE(starts_at, NOW()),
                ends_at = NULL,
                updated_by_user_id = ?,
                updated_at = NOW()
            WHERE id = ?
        ");
        $update->execute([
            (string)$role['role_name'],
            $userId,
            $assignedName,
            $actorUserId ?: null,
            $vacantAssignmentId,
        ]);
        return true;
    }

    $insert = $pdo->prepare("
        INSERT INTO operational_period_position_assignments
            (incident_id, operational_period_id, position_code, position_title, user_id, assigned_name, assignment_status, starts_at, created_by_user_id, updated_by_user_id, created_at, updated_at)
        VALUES
            (?, ?, ?, ?, ?, ?, 'assigned', NOW(), ?, ?, NOW(), NOW())
    ");
    $insert->execute([
        $incidentId,
        $operationalPeriodId,
        $positionCode,
        (string)$role['role_name'],
        $userId,
        $assignedName,
        $actorUserId ?: null,
        $actorUserId ?: null,
    ]);

    return true;
}

function seed_operational_period_position_assignments(int $incidentId, int $operationalPeriodId, ?int $copyFromOperationalPeriodId = null): int {
    if (!operational_period_assignment_table_exists() || $incidentId <= 0 || $operationalPeriodId <= 0) { return 0; }
    $pdo = db();
    $created = 0;
    $existing = operational_period_current_position_user_map($incidentId, $operationalPeriodId);

    if ($copyFromOperationalPeriodId && operational_period_position_assignments($incidentId, $copyFromOperationalPeriodId)) {
        foreach (operational_period_position_assignments($incidentId, $copyFromOperationalPeriodId) as $row) {
            $code = strtoupper((string)$row['position_code']);
            if ($code === '' || isset($existing[$code])) { continue; }
            $stmt = $pdo->prepare("
                INSERT INTO operational_period_position_assignments
                  (incident_id, operational_period_id, position_code, position_title, user_id, assigned_name, agency, assignment_status, starts_at, created_by_user_id, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, 'assigned', NOW(), ?, NOW(), NOW())
            ");
            $stmt->execute([$incidentId, $operationalPeriodId, $code, (string)$row['position_title'], $row['user_id'] ?: null, (string)($row['assigned_name'] ?? ''), (string)($row['agency'] ?? ''), (int)(current_user()['id'] ?? 0) ?: null]);
            $existing[$code] = true;
            $created++;
        }
    }

    // Include roles added since the source OP was created. This also covers users
    // prepared before the next operational period exists.
    $stmt = $pdo->prepare("
        SELECT r.role_code, r.role_name, ip.user_id, u.real_name, u.username
        FROM incident_positions ip
        JOIN roles r ON r.id = ip.role_id
        LEFT JOIN users u ON u.id = ip.user_id
        WHERE ip.incident_id = ? AND ip.deleted_at IS NULL AND (ip.assigned_end IS NULL OR ip.assigned_end >= NOW())
    ");
    $stmt->execute([$incidentId]);
    foreach ($stmt->fetchAll() as $row) {
        $code = strtoupper((string)$row['role_code']);
        if ($code === '' || isset($existing[$code])) { continue; }
        $stmt2 = $pdo->prepare("
            INSERT INTO operational_period_position_assignments
              (incident_id, operational_period_id, position_code, position_title, user_id, assigned_name, assignment_status, starts_at, created_by_user_id, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, 'assigned', NOW(), ?, NOW(), NOW())
        ");
        $stmt2->execute([$incidentId, $operationalPeriodId, $code, (string)$row['role_name'], $row['user_id'] ?: null, (string)($row['real_name'] ?: $row['username'] ?: ''), (int)(current_user()['id'] ?? 0) ?: null]);
        $existing[$code] = true;
        $created++;
    }

    return $created;
}

function assign_review_required_forms_to_positions(int $incidentId, int $operationalPeriodId): int {
    if ($incidentId <= 0 || $operationalPeriodId <= 0) { return 0; }
    $columns = table_columns('form_records');
    if (!in_array('responsible_position_code', $columns, true)) { return 0; }
    $forms = operational_period_copied_forms_requiring_review($incidentId, $operationalPeriodId);
    $assignments = operational_period_current_position_user_map($incidentId, $operationalPeriodId);
    $updated = 0;
    foreach ($forms as $form) {
        $defaults = form_type_default_responsible_position((string)$form['form_type']);
        $chosenCode = array_key_first($defaults);
        foreach (array_keys($defaults) as $code) {
            if (isset($assignments[$code])) { $chosenCode = $code; break; }
        }
        $chosenTitle = $defaults[$chosenCode] ?? $chosenCode;
        $assignedUserId = null;
        if (isset($assignments[$chosenCode]) && !empty($assignments[$chosenCode]['user_id'])) {
            $assignedUserId = (int)$assignments[$chosenCode]['user_id'];
        }
        $existingCode = strtoupper((string)($form['responsible_position_code'] ?? ''));
        if ($existingCode !== '' && isset($assignments[$existingCode])) {
            $chosenCode = $existingCode;
            $chosenTitle = (string)($assignments[$existingCode]['position_title'] ?? $chosenTitle);
            $assignedUserId = !empty($assignments[$existingCode]['user_id']) ? (int)$assignments[$existingCode]['user_id'] : null;
        }
        $stmt = db()->prepare("
            UPDATE form_records
            SET responsible_position_code = ?,
                responsible_position_title = ?,
                assigned_to_user_id = ?
            WHERE id = ? AND incident_id = ? AND operational_period_id = ?
        ");
        $stmt->execute([$chosenCode, $chosenTitle, $assignedUserId, (int)$form['id'], $incidentId, $operationalPeriodId]);
        $updated += $stmt->rowCount();
    }
    return $updated;
}

function operational_period_users_for_assignment_select(): array {
    $stmt = db()->query("SELECT id, username, real_name, email FROM users WHERE deleted_at IS NULL AND is_active=1 ORDER BY real_name, username");
    return $stmt->fetchAll();
}

function copied_forms_assigned_to_current_user(int $incidentId, int $operationalPeriodId): array {
    $all = operational_period_copied_forms_requiring_review($incidentId, $operationalPeriodId);
    $userId = (int)(current_user()['id'] ?? 0);
    $roles = array_values(array_unique(array_filter(array_map('strtoupper', get_user_role_codes($incidentId, $userId)))));
    $out = [];
    foreach ($all as $f) {
        $assigned = (int)($f['assigned_to_user_id'] ?? 0);
        $pos = strtoupper((string)($f['responsible_position_code'] ?? ''));
        if (($assigned > 0 && $assigned === $userId) || ($pos !== '' && in_array($pos, $roles, true))) {
            $out[] = $f;
        }
    }
    return $out;
}

/**
 * External check-in administration is an internal, permission-controlled function.
 * Public gateway access is deliberately not granted through these helpers.
 */
function current_user_can_external_checkin_view(?int $incidentId = null): bool {
    $user = current_user();
    if (!$user) { return false; }
    if (!empty($user['is_tsc_admin'])) { return true; }
    $incidentId = $incidentId ?: (int)($user['incident_id'] ?? 0) ?: active_incident_id();
    $perms = get_current_user_form_permissions('EXTERNAL_CHECKIN', $incidentId);
    return !empty($perms['can_view']);
}

function current_user_can_external_checkin_manage(?int $incidentId = null): bool {
    $user = current_user();
    if (!$user) { return false; }
    if (!empty($user['is_tsc_admin'])) { return true; }
    $incidentId = $incidentId ?: (int)($user['incident_id'] ?? 0) ?: active_incident_id();
    $perms = get_current_user_form_permissions('EXTERNAL_CHECKIN', $incidentId);
    return !empty($perms['can_edit']) || !empty($perms['can_submit_final']);
}
