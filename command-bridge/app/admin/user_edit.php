<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../includes/external_checkin_sync.php';
require_csrf_for_post();

require_once dirname(__DIR__) . '/includes/layout.php';
require_once dirname(__DIR__) . '/includes/login_throttle.php';
require_login();
require_user_management_access();

$pdo = db();
$incident = active_incident();
$incidentId = (int)($incident['id'] ?? 0);
$userId = (int)($_GET['id'] ?? $_POST['user_id'] ?? 0);
$user = get_user_with_roles($userId, $incidentId);

if (!$user) {
    http_response_code(404);
    render_header('User Not Found');
    echo '<div class="card"><p>User not found.</p></div>';
    render_footer();
    exit;
}

$roles = get_roles();
$companionLocations = [];
if ($incidentId > 0) {
    $locationStmt = $pdo->prepare('SELECT id, location_name, location_type, lifecycle_status FROM external_checkin_locations WHERE incident_id = ? AND lifecycle_status = "active" ORDER BY location_name');
    $locationStmt->execute([$incidentId]);
    $companionLocations = $locationStmt->fetchAll();
}
$companionAccess = $incidentId > 0 ? companion_checkin_user_access($incidentId, $userId) : null;
$companionLocationIds = array_map('intval', (array)($companionAccess['location_ids'] ?? []));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $realName = trim($_POST['real_name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $cellPhone = trim($_POST['cell_phone'] ?? '');
    $roleIds = array_map('intval', $_POST['role_ids'] ?? []);
    $checkInNow = !empty($_POST['check_in_now']);
    $isActive = !empty($_POST['is_active']) ? 1 : 0;
    $authProvider = ($_POST['auth_provider'] ?? 'local') === 'entra' ? 'entra' : 'local';
    $entraObjectId = trim($_POST['entra_object_id'] ?? '');
    $entraUpn = trim($_POST['entra_user_principal_name'] ?? '');
    $newPassword = (string)($_POST['new_password'] ?? '');
    $newPasswordConfirm = (string)($_POST['new_password_confirm'] ?? '');
    $newPin = trim((string)($_POST['new_pin'] ?? ''));
    $newPinConfirm = trim((string)($_POST['new_pin_confirm'] ?? ''));
    $passwordHash = null;
    $pinHash = null;
    $companionLocationIds = array_values(array_unique(array_filter(array_map('intval', $_POST['companion_location_ids'] ?? []))));
    $companionAction = (string)($_POST['companion_access_action'] ?? 'save');
    if (!in_array($companionAction, ['save', 'enable', 'revoke'], true)) {
        $companionAction = 'save';
    }

    if (!empty($user['is_tsc_admin'])) {
        if ($newPassword !== '' || $newPasswordConfirm !== '') {
            if ($newPassword === '' || $newPassword !== $newPasswordConfirm) {
                flash('error', 'The new password and confirmation do not match.');
                header('Location: ' . app_url('/admin/user_edit.php?id=' . $userId));
                exit;
            }
            $passwordHash = password_hash($newPassword, PASSWORD_DEFAULT);
        }
    } elseif ($authProvider === 'local' && ($newPin !== '' || $newPinConfirm !== '')) {
        if (!preg_match('/^\d{6}$/', $newPin)) {
            flash('error', 'The new PIN must contain exactly 6 digits.');
            header('Location: ' . app_url('/admin/user_edit.php?id=' . $userId));
            exit;
        }
        if ($newPin !== $newPinConfirm) {
            flash('error', 'The new PIN and confirmation do not match.');
            header('Location: ' . app_url('/admin/user_edit.php?id=' . $userId));
            exit;
        }
        $pinHash = encrypt_pin($newPin);
    }

    // Before-state for the audit record (names and flags only; credentials are never recorded).
    $auditBefore = ['username' => (string)($user['username'] ?? ''), 'real_name' => (string)($user['real_name'] ?? ''),
        'email' => (string)($user['email'] ?? ''), 'is_active' => (int)($user['is_active'] ?? 1)];
    $rolesBefore = $incidentId > 0 ? get_user_role_codes($incidentId, $userId) : [];

    $stmt = $pdo->prepare("
        UPDATE users
        SET username = ?, real_name = ?, email = ?, is_active = ?, auth_provider = ?, entra_object_id = ?, entra_user_principal_name = ?, updated_at = NOW()
        WHERE id = ?
    ");
    $stmt->execute([$username, $realName, $email ?: null, $isActive, $authProvider, $entraObjectId ?: null, $entraUpn ?: null, $userId]);

    // A credential set by an administrator is temporary: it also clears any
    // lockout and the user must choose their own at next sign-in (unless the
    // administrator is changing their own).
    $forceChange = $userId === (int)(current_user()['id'] ?? 0) ? 0 : 1;
    if ($passwordHash !== null) {
        $stmt = $pdo->prepare("UPDATE users SET password_hash = ?, must_change_pin = ?, failed_login_count = 0, lockout_level = 0, locked_until = NULL, updated_at = NOW() WHERE id = ?");
        $stmt->execute([$passwordHash, $forceChange, $userId]);
    }
    if ($pinHash !== null) {
        $stmt = $pdo->prepare("UPDATE users SET pin_encrypted = ?, pin_last_reset_at = NOW(), must_change_pin = ?, failed_login_count = 0, lockout_level = 0, locked_until = NULL, updated_at = NOW() WHERE id = ?");
        $stmt->execute([$pinHash, $forceChange, $userId]);
    }

    if ($passwordHash !== null || $pinHash !== null) { touch_user_credentials($userId); }
    save_contact_value($userId, 'email', $email);
    save_contact_value($userId, 'cell_phone', $cellPhone);

    if ($incidentId > 0) {
        sync_user_roles($incidentId, $userId, $roleIds);

        $eligibleForCompanionCheckin = companion_checkin_user_is_eligible($incidentId, $userId);
        if ($eligibleForCompanionCheckin) {
            companion_checkin_set_user_locations($incidentId, $userId, $companionLocationIds, (int)(current_user()['id'] ?? 0) ?: null);

            if ($companionAction === 'enable') {
                companion_checkin_enable_user_access($incidentId, $userId, (int)(current_user()['id'] ?? 0) ?: null);
                audit_log((int)(current_user()['id'] ?? 0), 'enable_companion_checkin_access', 'user', $userId, 'Enabled CIR/SAM Companion Check-In access.');
            } elseif ($companionAction === 'revoke') {
                companion_checkin_revoke_user_access($incidentId, $userId, (int)(current_user()['id'] ?? 0) ?: null);
                audit_log((int)(current_user()['id'] ?? 0), 'revoke_companion_checkin_access', 'user', $userId, 'Revoked CIR/SAM Companion Check-In access.');
            }

            companion_checkin_sync_user_ics211($incidentId, $userId);
        } else {
            companion_checkin_revoke_user_access($incidentId, $userId, (int)(current_user()['id'] ?? 0) ?: null);
        }

        external_checkin_enqueue_companion_user_snapshot($incidentId, $userId);

        if ($checkInNow) {
            sync_user_checkin_records($incidentId, $userId, true);
        }
    }

    $auditDetails = 'Updated user and role assignments';
    $changes = [];
    if ($auditBefore['username'] !== $username) { $changes[] = 'username changed'; }
    if ($auditBefore['real_name'] !== $realName) { $changes[] = 'name changed'; }
    if ($auditBefore['email'] !== $email) { $changes[] = 'email changed'; }
    if ($auditBefore['is_active'] !== $isActive) { $changes[] = $isActive ? 'account reactivated' : 'account deactivated'; }
    if ($incidentId > 0) {
        $rolesAfter = get_user_role_codes($incidentId, $userId);
        $added = array_diff($rolesAfter, $rolesBefore); $removed = array_diff($rolesBefore, $rolesAfter);
        if ($added) { $changes[] = 'positions added: ' . implode(', ', $added); }
        if ($removed) { $changes[] = 'positions removed: ' . implode(', ', $removed); }
    }
    if ($changes) { $auditDetails .= ' (' . implode('; ', $changes) . ')'; }
    $successMessage = 'User updated.';
    if ($passwordHash !== null) {
        $auditDetails .= '; reset Technical Services Coordinator password';
        $successMessage = 'User and password updated.';
    } elseif ($pinHash !== null) {
        $auditDetails .= '; reset local PIN';
        $successMessage = 'User and PIN updated.';
    }
    audit_log((int)(current_user()['id'] ?? 0), 'update_user', 'user', $userId, $auditDetails);
    flash('success', $successMessage);
    header('Location: ' . app_url('/admin/user_edit.php?id=' . $userId));
    exit;
}

render_header('Edit User');
?>
<div class="card">
  <h2>Edit User</h2>
  <p class="small">For Entra login, create or edit the ICS user, set Login Type to Microsoft Entra, enter the user's Entra Object ID or UPN, then assign the normal ICS roles/positions below.</p>
  <form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="user_id" value="<?= e((string)$userId) ?>">
    <div class="grid">
      <div><label>Username</label><input name="username" value="<?= e($user['username']) ?>" required></div>
      <div><label>Full Name</label><input name="real_name" value="<?= e($user['real_name']) ?>" required></div>
      <div><label>Email</label><input name="email" type="email" value="<?= e($user['email']) ?>"></div>
      <div><label>Cell Phone</label><input name="cell_phone" value="<?= e($user['cell_phone']) ?>"></div>
      <div>
        <label>Login Type</label>
        <select name="auth_provider">
          <option value="local"<?= (($user['auth_provider'] ?? 'local') === 'local') ? ' selected' : '' ?>>Local PIN</option>
          <option value="entra"<?= (($user['auth_provider'] ?? 'local') === 'entra') ? ' selected' : '' ?>>Microsoft Entra</option>
        </select>
        <p class="small">Choose Microsoft Entra for M365 sign-in users. Local PIN users continue to use their PIN.</p>
      </div>
      <div><label>Entra Object ID</label><input name="entra_object_id" value="<?= e($user['entra_object_id'] ?? '') ?>"><p class="small">Paste the user's Object ID from Microsoft Entra. This is the best unique login match.</p></div>
      <div><label>Entra User Principal Name</label><input name="entra_user_principal_name" value="<?= e($user['entra_user_principal_name'] ?? '') ?>"><p class="small">Usually their M365/email sign-in name, for example user@example.com.</p></div>
      <div>
        <label>Assigned Positions</label>
        <select name="role_ids[]" multiple size="8">
          <?php foreach ($roles as $role): ?>
            <option value="<?= e((string)$role['id']) ?>"<?= in_array((int)$role['id'], $user['role_ids'], true) ? ' selected' : '' ?>>
              <?= e($role['code']) ?> - <?= e($role['name']) ?>
            </option>
          <?php endforeach; ?>
        </select>
        <p class="small">Hold Ctrl or Command to select multiple roles.</p>
      </div>
      <div class="checkbox-cell">
        <label class="checkbox-row"><input type="checkbox" name="is_active" value="1"<?= !empty($user['is_active']) ? ' checked' : '' ?>> User is active</label>
        <label class="checkbox-row"><input type="checkbox" name="check_in_now" value="1" checked> Refresh ICS 211 / ICS 205A from current assignments</label>
      </div>
    </div>
    <?php if (!empty($user['is_tsc_admin'])): ?>
      <h3>Technical Services Coordinator Password</h3>
      <p class="small">Leave these fields blank to keep the current password.</p>
      <div class="grid">
        <div><label>New Password</label><input name="new_password" type="password" autocomplete="new-password"></div>
        <div><label>Confirm New Password</label><input name="new_password_confirm" type="password" autocomplete="new-password"></div>
      </div>
    <?php else: ?>
      <h3>Local PIN</h3>
      <p class="small">Set a new six-digit PIN for local sign-in. Leave these fields blank to keep the current PIN. PINs are not used when Login Type is Microsoft Entra.</p>
      <div class="grid">
        <div><label>New 6-Digit PIN</label><input name="new_pin" type="password" inputmode="numeric" pattern="\d{6}" maxlength="6" autocomplete="new-password"></div>
        <div><label>Confirm New PIN</label><input name="new_pin_confirm" type="password" inputmode="numeric" pattern="\d{6}" maxlength="6" autocomplete="new-password"></div>
      </div>
    <?php endif; ?>
    <?php if ($incidentId > 0): ?>
      <h3>Companion Check-In Access (CIR / SAM)</h3>
      <p class="small">Only active users assigned the CIR or SAM role can use Companion Check-In. Select the authorized check-in locations. The same CommandBridge PIN is used for local sign-in and Companion Check-In.</p>
      <?php
        $companionRoleIds = [];
        foreach ($roles as $role) {
            if (in_array((string)$role['code'], ['CIR', 'SAM'], true)) {
                $companionRoleIds[] = (int)$role['id'];
            }
        }
        $hasCompanionRole = !empty(array_intersect(array_map('intval', (array)$user['role_ids']), $companionRoleIds));
      ?>
      <div class="grid">
        <div>
          <label>Authorized Check-In Locations</label>
          <select name="companion_location_ids[]" multiple size="6">
            <?php foreach ($companionLocations as $location): ?>
              <option value="<?= e((string)$location['id']) ?>"<?= in_array((int)$location['id'], $companionLocationIds, true) ? ' selected' : '' ?>>
                <?= e($location['location_name'] . ' (' . $location['location_type'] . ' / ' . $location['lifecycle_status'] . ')') ?>
              </option>
            <?php endforeach; ?>
          </select>
          <p class="small">Only active locations are synchronized to the Check-In Gateway. Hold Ctrl or Command to select more than one.</p>
        </div>
        <div>
          <label>Current Status</label>
          <?php if (!$hasCompanionRole): ?>
            <p class="small">Assign CIR or SAM above, then save the user to manage Companion Check-In access.</p>
          <?php elseif (!empty($companionAccess['access_revoked_at'])): ?>
            <p class="small">Access revoked.</p>
          <?php elseif (!empty($companionAccess['access_id']) && empty($companionLocationIds)): ?>
            <p class="small">Access is enabled, but no authorized check-in locations are assigned.</p>
          <?php elseif (!empty($companionAccess['access_id'])): ?>
            <p class="small">Access enabled. The user’s CommandBridge PIN is used for Companion Check-In.</p>
          <?php else: ?>
            <p class="small">Companion Check-In access is not enabled.</p>
          <?php endif; ?>
          <label>Access Action</label>
          <select name="companion_access_action">
            <option value="save">Keep current access status</option>
            <option value="enable">Enable Companion Check-In access</option>
            <option value="revoke">Revoke Companion Check-In access</option>
          </select>
          <p class="small">Changing the user's CommandBridge PIN also updates their Companion Check-In PIN.</p>
        </div>
      </div>
    <?php endif; ?>

    <div style="margin-top:16px;">
      <button type="submit">Save Changes</button>
      <a class="btn btn-secondary" href="<?= e(app_url('/admin/users.php')) ?>">Back to Users</a>
      <a class="btn btn-secondary" href="<?= e(app_url('/admin/email_user.php?id=' . $userId)) ?>">Email User</a>
    </div>
  </form>
</div>
<?php render_footer(); ?>
