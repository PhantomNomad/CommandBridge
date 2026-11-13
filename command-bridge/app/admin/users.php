<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../includes/external_checkin_sync.php';
require_csrf_for_post();

require_once dirname(__DIR__) . '/includes/layout.php';
require_once dirname(__DIR__) . '/includes/mail.php';
require_once dirname(__DIR__) . '/includes/login_throttle.php';
require_login();
require_user_management_access();

$pdo = db();
$incident = active_incident();
$incidentId = (int)($incident['id'] ?? 0);
$roles = get_roles();
$companionLocations = [];
if ($incidentId > 0) {
    $locationStmt = $pdo->prepare('SELECT id, location_name, location_type, lifecycle_status FROM external_checkin_locations WHERE incident_id = ? AND lifecycle_status = "active" ORDER BY location_name');
    $locationStmt->execute([$incidentId]);
    $companionLocations = $locationStmt->fetchAll();
}
$operationalPeriods = [];
if ($incidentId > 0) {
    $opStmt = $pdo->prepare("
        SELECT id, period_number, period_name, op_start, op_end, status
        FROM operational_periods
        WHERE incident_id = ? AND deleted_at IS NULL
        ORDER BY period_number DESC, op_start DESC
    ");
    $opStmt->execute([$incidentId]);
    $operationalPeriods = $opStmt->fetchAll();
}
$selectedOperationalPeriodId = (int)($_GET['operational_period_id'] ?? 0);
if ($selectedOperationalPeriodId === 0 && !empty($operationalPeriods)) {
    foreach ($operationalPeriods as $op) {
        if (($op['status'] ?? '') === 'current') {
            $selectedOperationalPeriodId = (int)$op['id'];
            break;
        }
    }
}


if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_user'])) {
    $username = trim($_POST['username'] ?? '');
    $realName = trim($_POST['real_name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $cellPhone = trim($_POST['cell_phone'] ?? '');
    $authProvider = ($_POST['auth_provider'] ?? 'local') === 'entra' ? 'entra' : 'local';
    $entraObjectId = trim($_POST['entra_object_id'] ?? '');
    $entraUpn = trim($_POST['entra_user_principal_name'] ?? '');
    $roleId = (int)($_POST['role_id'] ?? 0);
    $checkInNow = !empty($_POST['check_in_now']);
    $emailPin = !empty($_POST['email_pin']);
    $companionLocationIds = array_values(array_unique(array_filter(array_map('intval', $_POST['companion_location_ids'] ?? []))));
    $companionAction = (string)($_POST['companion_access_action'] ?? 'enable');
    if (!in_array($companionAction, ['enable', 'revoke'], true)) {
        $companionAction = 'enable';
    }

    $initialRoleCode = '';
    foreach ($roles as $role) {
        if ((int)$role['id'] === $roleId) {
            $initialRoleCode = strtoupper((string)$role['code']);
            break;
        }
    }
    $isInitialCompanionRole = in_array($initialRoleCode, companion_checkin_role_codes(), true);
    if ($isInitialCompanionRole && $incidentId > 0 && $companionAction === 'enable' && !$companionLocationIds) {
        flash('error', 'Select at least one authorized Companion Check-In location, or choose Revoke Companion Check-In access.');
        header('Location: ' . app_url('/admin/users.php'));
        exit;
    }

    // Entra CIR/SAM users also need the single stored PIN for Companion Check-In.
    $pin = ($authProvider === 'entra' && !$isInitialCompanionRole) ? null : generate_pin();

    if ($authProvider === 'entra') {
        if ($entraObjectId === '') {
            flash('error', 'Microsoft Entra users must be looked up and validated before they can be created.');
            header('Location: ' . app_url('/admin/users.php'));
            exit;
        }

        $lookup = entra_lookup_user($username);
        if (empty($lookup['ok'])) {
            flash('error', $lookup['message'] ?? 'Microsoft Entra user lookup failed.');
            header('Location: ' . app_url('/admin/users.php'));
            exit;
        }

        $entraUser = $lookup['user'] ?? [];
        $lookupObjectId = (string)($entraUser['id'] ?? '');
        if ($lookupObjectId === '' || strcasecmp($lookupObjectId, $entraObjectId) !== 0) {
            flash('error', 'Microsoft Entra validation changed or is incomplete. Please click Lookup again before creating the user.');
            header('Location: ' . app_url('/admin/users.php'));
            exit;
        }

        $entraUpn = (string)($entraUser['userPrincipalName'] ?? $entraUpn);
        $username = $entraUpn !== '' ? $entraUpn : $username;
        $realName = (string)($entraUser['displayName'] ?? $realName);
        $email = (string)(($entraUser['mail'] ?? '') ?: ($entraUser['userPrincipalName'] ?? $email));
        $cellPhone = (string)(($entraUser['mobilePhone'] ?? '') ?: (($entraUser['businessPhones'][0] ?? '') ?: $cellPhone));
        $emailPin = false;
    }

    try {
        $stmt = $pdo->prepare("
            INSERT INTO users (username, real_name, email, pin_encrypted, auth_provider, entra_object_id, entra_user_principal_name, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
        ");
        $stmt->execute([
            $username,
            $realName,
            $email ?: null,
            $pin === null ? null : encrypt_pin($pin),
            $authProvider,
            $authProvider === 'entra' ? $entraObjectId : null,
            $authProvider === 'entra' ? ($entraUpn ?: $username) : null
        ]);
    } catch (PDOException $e) {
        security_log($e, 'create_user');
        if (stripos($e->getMessage(), 'auth_provider') !== false || stripos($e->getMessage(), 'entra_object_id') !== false) {
            flash('error', 'User was not created. The database is missing the Microsoft Entra user columns. Please run update.sh (see docs/UPDATING.md), then try again.');
        } else {
            flash('error', 'User was not created. Please check that the username is unique and try again.');
        }
        header('Location: ' . app_url('/admin/users.php'));
        exit;
    }
    $userId = (int)$pdo->lastInsertId();

    save_contact_value($userId, 'email', $email);
    save_contact_value($userId, 'cell_phone', $cellPhone);

    $operationalPeriodAssignmentAdded = false;
    $operationalPeriodAssignmentId = 0;
    if ($incidentId > 0 && $roleId > 0) {
        sync_user_roles($incidentId, $userId, [$roleId]);

        if (companion_checkin_user_is_eligible($incidentId, $userId)) {
            companion_checkin_set_user_locations($incidentId, $userId, $companionLocationIds, (int)(current_user()['id'] ?? 0) ?: null);
            if ($companionAction === 'enable') {
                companion_checkin_enable_user_access($incidentId, $userId, (int)(current_user()['id'] ?? 0) ?: null);
            } else {
                companion_checkin_revoke_user_access($incidentId, $userId, (int)(current_user()['id'] ?? 0) ?: null);
            }
            external_checkin_enqueue_companion_user_snapshot($incidentId, $userId);
        }

        // Keep the selected/current operational period's Position Assignments in sync
        // when a new user is given an initial role. If no OP exists yet, the normal
        // OP creation seeding process will add the incident role later.
        $currentOp = current_operational_period($incidentId);
        if ($currentOp) {
            try {
                $operationalPeriodAssignmentId = (int)$currentOp['id'];
                $operationalPeriodAssignmentAdded = ensure_user_role_operational_period_assignment(
                    $incidentId,
                    $operationalPeriodAssignmentId,
                    $userId,
                    $roleId,
                    (int)(current_user()['id'] ?? 0) ?: null
                );
                if ($operationalPeriodAssignmentAdded) {
                    assign_review_required_forms_to_positions($incidentId, $operationalPeriodAssignmentId);
                }
            } catch (Throwable $e) {
                security_log($e, 'create_user_op_assignment_sync');
            }
        }
    }

    if ($checkInNow && $incidentId > 0) {
        try {
            sync_user_checkin_records($incidentId, $userId, true);
        } catch (RuntimeException $e) {
            security_log($e, 'create_user_checkin_sync');
            $msg = $e->getMessage();
            if (stripos($msg, 'active Operational Period') !== false) {
                flash('warning', 'User was created, but they were not checked in because there is no active Operational Period. Create or activate an Operational Period, then check the user in from the user/roles page.');
            } else {
                flash('warning', 'User was created, but automatic check-in could not be completed. Please try checking the user in manually.');
            }
        } catch (Throwable $e) {
            security_log($e, 'create_user_checkin_sync');
            flash('warning', 'User was created, but automatic check-in could not be completed. Please try checking the user in manually.');
        }
    }

    $auditMessage = 'Created user from admin/users.php';
    if ($operationalPeriodAssignmentAdded && $operationalPeriodAssignmentId > 0) {
        $auditMessage .= '; added initial position to operational period ' . $operationalPeriodAssignmentId;
    }
    audit_log((int)(current_user()['id'] ?? 0), 'create_user', 'user', $userId, $auditMessage);
    if ($incidentId > 0) {
        // Every person gets their own ICS 214 activity log from the moment their login is created.
        require_once dirname(__DIR__) . '/includes/ics214_log.php';
        ics214_start_log_for_new_user($incidentId, (int)$userId, (int)(current_user()['id'] ?? 0));
    }

    $mailNote = '';
    if ($emailPin) {
        if ($email === '') {
            $mailNote = ' PIN email was not sent; check the email address.';
        } elseif (!function_exists('send_new_user_pin_email')) {
            error_log('PIN email was not sent because send_new_user_pin_email() is not configured.');
            $mailNote = ' PIN email was not sent; server email delivery is not configured.';
        } elseif (send_new_user_pin_email($email, $realName, $username, $pin)) {
            $mailNote = ' PIN email sent.';
        } else {
            $mailNote = ' PIN email was not sent; check the email address and server mail configuration.';
        }
    }

    if ($authProvider === 'entra') {
        $message = "Microsoft Entra user created for {$realName}.";
        if ($isInitialCompanionRole) {
            $message .= " Generated PIN for Companion Check-In: {$pin}.";
        }
    } else {
        $message = "User created. Generated PIN for {$realName}: {$pin}" . $mailNote;
        if ($isInitialCompanionRole) {
            $message .= ' This same PIN is used for Companion Check-In.';
        }
    }
    flash('success', $message);
    header('Location: ' . app_url('/admin/users.php'));
    exit;
}


if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['assign_existing_user_to_op'])) {
    $targetUserId = (int)($_POST['target_user_id'] ?? 0);
    $targetOperationalPeriodId = (int)($_POST['target_operational_period_id'] ?? 0);
    $roleIds = array_values(array_unique(array_filter(array_map('intval', $_POST['op_role_ids'] ?? []))));
    $assignmentStatus = $_POST['assignment_status'] ?? 'assigned';
    $allowedStatuses = ['assigned','vacant','incoming','outgoing','relieved'];
    if (!in_array($assignmentStatus, $allowedStatuses, true)) {
        $assignmentStatus = 'assigned';
    }

    $targetUserStmt = $pdo->prepare("SELECT id, real_name FROM users WHERE id = ? AND deleted_at IS NULL LIMIT 1");
    $targetUserStmt->execute([$targetUserId]);
    $targetUser = $targetUserStmt->fetch();

    $opStmt = $pdo->prepare("SELECT id FROM operational_periods WHERE id = ? AND incident_id = ? AND deleted_at IS NULL LIMIT 1");
    $opStmt->execute([$targetOperationalPeriodId, $incidentId]);
    $targetOp = $opStmt->fetch();

    if (!$targetUser || !$targetOp || !$roleIds) {
        flash('error', 'Select an existing user, operational period, and at least one position.');
        header('Location: ' . app_url('/admin/users.php'));
        exit;
    }

    $roleStmt = $pdo->prepare("SELECT id, role_code, role_name FROM roles WHERE id = ? LIMIT 1");
    $findExisting = $pdo->prepare("
        SELECT id
        FROM operational_period_position_assignments
        WHERE incident_id = ?
          AND operational_period_id = ?
          AND position_code = ?
          AND user_id = ?
        LIMIT 1
    ");
    $updateExisting = $pdo->prepare("
        UPDATE operational_period_position_assignments
        SET position_title = ?,
            assigned_name = ?,
            assignment_status = ?,
            starts_at = COALESCE(starts_at, NOW()),
            ends_at = NULL,
            deleted_at = NULL,
            updated_by_user_id = ?,
            updated_at = NOW()
        WHERE id = ?
    ");
    $insertAssignment = $pdo->prepare("
        INSERT INTO operational_period_position_assignments
            (incident_id, operational_period_id, position_code, position_title, user_id, assigned_name, assignment_status, starts_at, created_by_user_id, updated_by_user_id, created_at, updated_at)
        VALUES
            (?, ?, ?, ?, ?, ?, ?, NOW(), ?, ?, NOW(), NOW())
    ");

    $assignedCodes = [];
    foreach ($roleIds as $roleId) {
        $roleStmt->execute([$roleId]);
        $role = $roleStmt->fetch();
        if (!$role) {
            continue;
        }
        $positionCode = (string)$role['role_code'];
        $positionTitle = (string)$role['role_name'];
        $assignedName = (string)$targetUser['real_name'];

        $findExisting->execute([$incidentId, $targetOperationalPeriodId, $positionCode, $targetUserId]);
        $existingId = (int)(($findExisting->fetch())['id'] ?? 0);
        if ($existingId > 0) {
            $updateExisting->execute([$positionTitle, $assignedName, $assignmentStatus, (int)(current_user()['id'] ?? 0), $existingId]);
        } else {
            $insertAssignment->execute([$incidentId, $targetOperationalPeriodId, $positionCode, $positionTitle, $targetUserId, $assignedName, $assignmentStatus, (int)(current_user()['id'] ?? 0), (int)(current_user()['id'] ?? 0)]);
        }
        $assignedCodes[] = $positionCode;
    }

    audit_log((int)(current_user()['id'] ?? 0), 'assign_user_to_operational_period', 'user', $targetUserId, 'Assigned user to OP ' . $targetOperationalPeriodId . ' positions: ' . implode(', ', $assignedCodes));
    flash('success', 'User assigned to the selected operational period position(s). Their existing PIN/password was unchanged.');
    header('Location: ' . app_url('/admin/users.php?operational_period_id=' . $targetOperationalPeriodId));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['unlock_user_id'])) {
    $unlockId = (int)$_POST['unlock_user_id'];
    $unlockStmt = $pdo->prepare("SELECT id, username, is_sysadmin FROM users WHERE id = ? AND deleted_at IS NULL LIMIT 1");
    $unlockStmt->execute([$unlockId]);
    $unlockUser = $unlockStmt->fetch();

    if (!$unlockUser) {
        flash('error', 'User was not found.');
    } elseif (!empty($unlockUser['is_sysadmin']) && empty(current_user()['is_sysadmin'])) {
        flash('error', 'Only a SysAdmin can unlock an administrator account.');
    } else {
        login_throttle_unlock($pdo, $unlockId);
        audit_log((int)(current_user()['id'] ?? 0), 'unlock_user', 'user', $unlockId, 'Account unlocked by administrator');
        flash('success', 'Account unlocked for ' . $unlockUser['username'] . '. Their existing PIN/password still works.');
    }
    header('Location: ' . app_url('/admin/users.php'));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reset_pin_user_id'])) {
    $userId = (int)$_POST['reset_pin_user_id'];
    $userStmt = $pdo->prepare("SELECT username, real_name, email FROM users WHERE id = ? LIMIT 1");
    $userStmt->execute([$userId]);
    $resetUser = $userStmt->fetch();

    if (!$resetUser) {
        flash('error', 'User was not found. The PIN was not reset.');
        header('Location: ' . app_url('/admin/users.php'));
        exit;
    }

    $pin = generate_pin();
    // A reset also clears any lockout and makes the user choose their own PIN at next sign-in.
    $stmt = $pdo->prepare("UPDATE users SET pin_encrypted = ?, pin_last_reset_at = NOW(), must_change_pin = 1, failed_login_count = 0, lockout_level = 0, locked_until = NULL WHERE id = ?");
    $stmt->execute([encrypt_pin($pin), $userId]);
    if ($incidentId > 0) {
        external_checkin_enqueue_companion_user_snapshot($incidentId, $userId);
    }

    $emailStatus = '';
    $email = trim((string)($resetUser['email'] ?? ''));
    if ($email !== '') {
        $emailStatus = send_reset_user_pin_email(
            $email,
            (string)($resetUser['real_name'] ?? ''),
            (string)($resetUser['username'] ?? ''),
            $pin
        ) ? ' The new PIN was emailed to the user.' : ' The PIN was reset, but the email could not be sent.';
    }

    touch_user_credentials($userId);
    flash('success', "PIN reset completed and any lockout cleared. New temporary PIN: {$pin}. The user will be asked to choose their own PIN at next sign-in.{$emailStatus}");
    audit_log((int)(current_user()['id'] ?? 0), 'reset_pin', 'user', $userId, 'Admin PIN reset; lockout cleared; PIN change required at next sign-in' . ($email !== '' ? '; PIN email requested' : ''));
    header('Location: ' . app_url('/admin/users.php'));
    exit;
}


$userSql = "
    SELECT
      u.id,
      u.username,
      u.real_name,
      u.email,
      u.is_sysadmin,
      u.locked_until,
      GROUP_CONCAT(DISTINCT r.role_code ORDER BY r.role_code SEPARATOR ', ') AS role_codes,
      GROUP_CONCAT(DISTINCT CONCAT(oppa.position_code, ' - ', oppa.position_title) ORDER BY oppa.position_code SEPARATOR '; ') AS op_position_codes,
      GROUP_CONCAT(DISTINCT CONCAT('OP ', op.period_number, ': ', oppa.position_code) ORDER BY op.period_number, oppa.position_code SEPARATOR '; ') AS op_summary,
      i.incident_name AS incident_name,
      MAX(CASE WHEN uc.contact_type = 'cell_phone' THEN uc.contact_value END) AS cell_phone
    FROM users u
    LEFT JOIN incident_positions ip
      ON ip.user_id = u.id
     AND ip.deleted_at IS NULL
     AND (ip.assigned_end IS NULL OR ip.assigned_end >= NOW())
    LEFT JOIN roles r ON r.id = ip.role_id
    LEFT JOIN incidents i ON i.id = ip.incident_id
    LEFT JOIN operational_period_position_assignments oppa
      ON oppa.user_id = u.id
     AND oppa.incident_id = ?
     AND oppa.deleted_at IS NULL
";
$userParams = [$incidentId];
if ($selectedOperationalPeriodId > 0) {
    $userSql .= " AND oppa.operational_period_id = ? ";
    $userParams[] = $selectedOperationalPeriodId;
}
$userSql .= "
    LEFT JOIN operational_periods op ON op.id = oppa.operational_period_id
    LEFT JOIN user_contacts uc ON uc.user_id = u.id AND uc.deleted_at IS NULL
    WHERE u.deleted_at IS NULL
";
if ($selectedOperationalPeriodId > 0) {
    $userSql .= " AND oppa.id IS NOT NULL ";
}
$userSql .= "
    GROUP BY u.id, u.username, u.real_name, u.email, u.is_sysadmin, u.locked_until, i.incident_name
    ORDER BY u.id DESC
";
$userStmt = $pdo->prepare($userSql);
$userStmt->execute($userParams);
$users = $userStmt->fetchAll();


render_header('Users / Roles');
?>
<div class="card">
  <h2>Users and Entra Login</h2>
  <p class="small">To allow Microsoft Entra sign-in, create the user first, then open Edit and set Login Type to Microsoft Entra. Enter the user's Entra Object ID when available, or their User Principal Name, and assign their ICS role/position. Role assignment still happens in this app; Entra only confirms who the user is.</p>
</div>

<div class="card">
  <h2>Add User</h2>
  <p>Create additional incident users here. Use the initial role drop-down for a first assignment, then use Edit to add multiple roles if needed.</p>
  <form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="create_user" value="1">
    <div class="grid">
      <div>
        <label>Login Type</label>
        <select name="auth_provider" id="auth_provider">
          <option value="local">Local / PIN</option>
          <option value="entra">Microsoft Entra</option>
        </select>
      </div>
      <div>
        <label>Username</label>
        <input name="username" id="username" required>
        <button type="button" id="entra_lookup_btn" disabled style="margin-top:8px;">Lookup</button>
        <p class="small" id="entra_lookup_status"></p>
        <input type="hidden" name="entra_object_id" id="entra_object_id">
        <input type="hidden" name="entra_user_principal_name" id="entra_user_principal_name">
      </div>
      <div><label>Full Name</label><input name="real_name" id="real_name" required></div>
      <div><label>Email</label><input name="email" id="email" type="email"></div>
      <div><label>Cell Phone</label><input name="cell_phone" id="cell_phone"></div>
      <div>
        <label>Initial Position</label>
        <select name="role_id" id="initial_role_id">
          <option value="">-- Unassigned --</option>
          <?php foreach ($roles as $role): ?>
            <option value="<?= e((string)$role['id']) ?>" data-companion-role="<?= in_array(strtoupper((string)$role['code']), companion_checkin_role_codes(), true) ? '1' : '0' ?>"><?= e(($role['group_name'] ?? 'Other') . ' / ' . $role['code'] . ' - ' . $role['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="checkbox-cell">
        <label class="checkbox-row"><input type="checkbox" name="check_in_now" value="1" checked> Check In Now</label>
        <label class="checkbox-row"><input type="checkbox" name="email_pin" value="1"> Email username and PIN to user</label>
        <p class="small">Adds the user to ICS 211 and ICS 205A using the selected role.</p>
      </div>
    </div>
    <div id="companion_checkin_create_access" class="card" style="display:none; margin-top:16px;">
      <h3>Companion Check-In Access (CIR / SAM)</h3>
      <p class="small">Choose the locations where this CIR/SAM user may check in resources. The user's single CommandBridge PIN will also be used for Companion Check-In.</p>
      <div class="grid">
        <div>
          <label>Authorized Check-In Locations</label>
          <select name="companion_location_ids[]" id="companion_location_ids" multiple size="6">
            <?php foreach ($companionLocations as $location): ?>
              <option value="<?= e((string)$location['id']) ?>"><?= e($location['location_name'] . ' (' . $location['location_type'] . ')') ?></option>
            <?php endforeach; ?>
          </select>
          <p class="small">Hold Ctrl or Command to select more than one active location.</p>
        </div>
        <div>
          <label>Access Action</label>
          <select name="companion_access_action" id="companion_access_action">
            <option value="enable">Enable access at selected locations</option>
            <option value="revoke">Revoke Companion Check-In access</option>
          </select>
        </div>
      </div>
    </div>
    <div style="margin-top:16px;"><button type="submit" id="create_user_btn">Create User</button></div>
  </form>
</div>

<div class="card">
  <h2>Assign Existing User to Operational Period</h2>
  <p>Use this to bring a person back for a later operational period without creating a duplicate account. Their existing PIN/password is unchanged. Select one or more positions if the person is filling multiple roles.</p>
  <form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="assign_existing_user_to_op" value="1">
    <div class="grid">
      <div>
        <label>User</label>
        <select name="target_user_id" required>
          <option value="">-- Select User --</option>
          <?php
          $allUsersForAssign = $pdo->query("SELECT id, real_name, username FROM users WHERE deleted_at IS NULL ORDER BY real_name, username")->fetchAll();
          foreach ($allUsersForAssign as $assignUser):
          ?>
            <option value="<?= e((string)$assignUser['id']) ?>"><?= e($assignUser['real_name'] . ' (' . $assignUser['username'] . ')') ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label>Operational Period</label>
        <select name="target_operational_period_id" required>
          <option value="">-- Select OP --</option>
          <?php foreach ($operationalPeriods as $op): ?>
            <option value="<?= e((string)$op['id']) ?>"<?= (int)$op['id'] === $selectedOperationalPeriodId ? ' selected' : '' ?>>
              <?= e('OP ' . $op['period_number'] . ' - ' . (($op['period_name'] ?? '') ?: date('Y-m-d H:i', strtotime((string)$op['op_start'])))) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label>Position(s) for this OP</label>
        <select name="op_role_ids[]" multiple size="8" required>
          <?php foreach ($roles as $role): ?>
            <option value="<?= e((string)$role['id']) ?>"><?= e($role['code'] . ' - ' . $role['name']) ?></option>
          <?php endforeach; ?>
        </select>
        <p class="small">Hold Ctrl or Command to select multiple positions. Example: DOCL, RESL, and Food Unit.</p>
      </div>
      <div>
        <label>Assignment Status</label>
        <select name="assignment_status">
          <option value="assigned">Assigned</option>
          <option value="incoming">Incoming</option>
          <option value="outgoing">Outgoing</option>
          <option value="relieved">Relieved</option>
          <option value="vacant">Vacant</option>
        </select>
        <p class="small">This creates operational-period-specific position assignments only. It does not reset or change the user’s PIN/password.</p>
      </div>
    </div>
    <div style="margin-top:16px;"><button type="submit">Assign Existing User to OP</button></div>
  </form>
</div>

<div class="card">
  <h2>User and Role Management</h2>
  <form method="get" style="margin-bottom:12px;">
    <label>Filter by Operational Period</label>
    <select name="operational_period_id" onchange="this.form.submit()">
      <option value="0">All users / all operational periods</option>
      <?php foreach ($operationalPeriods as $op): ?>
        <option value="<?= e((string)$op['id']) ?>"<?= (int)$op['id'] === $selectedOperationalPeriodId ? ' selected' : '' ?>>
          <?= e('OP ' . $op['period_number'] . ' - ' . (($op['period_name'] ?? '') ?: date('Y-m-d H:i', strtotime((string)$op['op_start'])))) ?>
        </option>
      <?php endforeach; ?>
    </select>
    <noscript><button type="submit">Apply Filter</button></noscript>
  </form>
  <?php $lockedCount = login_throttle_count_locked($pdo); ?>
  <?php if ($lockedCount > 0): ?>
    <div class="flash error"><?= e((string)$lockedCount) ?> account<?= $lockedCount === 1 ? ' is' : 's are' ?> currently locked after repeated failed sign-ins. Use <strong>Unlock</strong> to restore access immediately, or <strong>Reset PIN</strong> to issue a new temporary PIN. Locks also expire on their own.</div>
  <?php endif; ?>
  <table>
    <tr>
      <th>ID</th><th>Username</th><th>Name</th><th>Current Roles</th><th>OP Assignment(s)</th><th>Incident</th><th>Contact</th><th>Actions</th>
    </tr>
    <?php foreach ($users as $u): ?>
      <tr>
        <td><?= e((string)$u['id']) ?></td>
        <?php $lockedUntil = login_throttle_user_locked_until($u); ?>
        <td><?= e($u['username']) ?><?php if ($lockedUntil !== null): ?><br><span class="small" style="color:#b00020;font-weight:bold;">LOCKED until <?= e(date('g:i a', $lockedUntil)) ?></span><?php endif; ?></td>
        <td><?= e($u['real_name']) ?></td>
        <td><?= e($u['role_codes'] ?: ($u['is_sysadmin'] ? 'SysAdmin' : '')) ?></td>
        <td><?= e($selectedOperationalPeriodId > 0 ? ($u['op_position_codes'] ?? '') : ($u['op_summary'] ?? '')) ?></td>
        <td><?= e($u['incident_name']) ?></td>
        <td><?= e(trim(($u['email'] ?? '') . ' ' . ($u['cell_phone'] ?? ''))) ?></td>
        <td class="actions">
          <a class="btn btn-secondary" href="<?= e(app_url('/admin/user_edit.php?id=' . (int)$u['id'])) ?>">Edit</a>
          <?php if ($lockedUntil !== null && (empty($u['is_sysadmin']) || !empty(current_user()['is_sysadmin']))): ?>
          <form method="post">
    <?= csrf_field() ?>
            <input type="hidden" name="unlock_user_id" value="<?= e((string)$u['id']) ?>">
            <button type="submit">Unlock</button>
          </form>
          <?php endif; ?>
          <?php if (empty($u['is_sysadmin'])): ?>
          <form method="post">
    <?= csrf_field() ?>
            <input type="hidden" name="reset_pin_user_id" value="<?= e((string)$u['id']) ?>">
            <button type="submit">Reset PIN</button>
          </form>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
  </table>
</div>

<script>
(function () {
  const provider = document.getElementById('auth_provider');
  const username = document.getElementById('username');
  const lookupButton = document.getElementById('entra_lookup_btn');
  const createButton = document.getElementById('create_user_btn');
  const status = document.getElementById('entra_lookup_status');
  const csrf = document.querySelector('input[name="csrf_token"]');
  const realName = document.getElementById('real_name') || document.querySelector('input[name="real_name"]');
  const email = document.getElementById('email') || document.querySelector('input[name="email"]');
  const cell = document.getElementById('cell_phone') || document.querySelector('input[name="cell_phone"]');
  const oid = document.getElementById('entra_object_id');
  const upn = document.getElementById('entra_user_principal_name');
  const initialRole = document.getElementById('initial_role_id');
  const companionAccess = document.getElementById('companion_checkin_create_access');
  const companionLocations = document.getElementById('companion_location_ids');
  const companionAction = document.getElementById('companion_access_action');

  function isEntra() {
    return provider && provider.value === 'entra';
  }

  function updateCompanionCheckinAccess() {
    const selected = initialRole && initialRole.options[initialRole.selectedIndex];
    const isCompanionRole = !!(selected && selected.dataset.companionRole === '1');
    if (companionAccess) companionAccess.style.display = isCompanionRole ? '' : 'none';
    if (companionLocations) {
      const needsLocation = isCompanionRole && (!companionAction || companionAction.value === 'enable');
      companionLocations.required = needsLocation;
    }
  }

  function clearValidatedEntraUser() {
    if (oid) oid.value = '';
    if (upn) upn.value = '';
    if (status && isEntra()) status.textContent = 'Click Lookup to validate the Microsoft Entra user before creating.';
  }

  function updateButtons() {
    const hasUsername = username && username.value.trim() !== '';
    if (lookupButton) lookupButton.disabled = !(isEntra() && hasUsername);
    if (createButton) createButton.disabled = !!(isEntra() && (!oid || oid.value.trim() === ''));
  }

  if (provider) {
    provider.addEventListener('change', function () {
      clearValidatedEntraUser();
      updateButtons();
    });
  }

  if (username) {
    username.addEventListener('input', function () {
      clearValidatedEntraUser();
      updateButtons();
    });
  }

  if (initialRole) initialRole.addEventListener('change', updateCompanionCheckinAccess);
  if (companionAction) companionAction.addEventListener('change', updateCompanionCheckinAccess);

  if (lookupButton) {
    lookupButton.addEventListener('click', async function () {
      if (!isEntra() || !username.value.trim()) return;

      status.textContent = 'Looking up Microsoft Entra user...';
      lookupButton.disabled = true;
      if (createButton) createButton.disabled = true;

      try {
        const form = new FormData();
        form.append('csrf_token', csrf ? csrf.value : '');
        form.append('username', username.value.trim());

        const response = await fetch('../api/entra/lookup_user.php', {
          method: 'POST',
          credentials: 'same-origin',
          headers: {'X-Requested-With': 'XMLHttpRequest'},
          body: form
        });

        const data = await response.json();
        if (!data.ok) throw new Error(data.message || 'Lookup failed.');

        const u = data.user || {};
        if (!u.id) throw new Error('Microsoft Entra returned the user, but no Object ID was provided.');

        if (u.userPrincipalName) username.value = u.userPrincipalName;
        if (u.displayName && realName) realName.value = u.displayName;
        if ((u.mail || u.userPrincipalName) && email) email.value = u.mail || u.userPrincipalName;
        if (u.phone && cell) cell.value = u.phone;
        if (oid) oid.value = u.id;
        if (upn) upn.value = u.userPrincipalName || '';

        status.textContent = 'User validated in Microsoft Entra. You can now create the user.';
      } catch (e) {
        if (oid) oid.value = '';
        if (upn) upn.value = '';
        status.textContent = e.message || 'Lookup failed.';
      } finally {
        updateButtons();
      }
    });
  }

  updateButtons();
  updateCompanionCheckinAccess();
})();
</script>

<?php render_footer(); ?>
