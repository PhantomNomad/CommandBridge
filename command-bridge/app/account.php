<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/security.php';
require_once __DIR__ . '/includes/external_checkin_sync.php';
require_csrf_for_post();

require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/login_throttle.php';
require_login();

$pdo = db();
$currentUser = current_user();
$userId = (int)($currentUser['id'] ?? 0);

$stmt = $pdo->prepare("
    SELECT id, username, real_name, is_tsc_admin, auth_provider, password_hash, pin_encrypted
    FROM users
    WHERE id = ? AND deleted_at IS NULL AND is_active = 1
    LIMIT 1
");
$stmt->execute([$userId]);
$user = $stmt->fetch();

if (!$user) {
    logout_user();
    header('Location: ' . app_url('/login.php'));
    exit;
}

$forcedChange = !empty($currentUser['must_change_pin']);
$isTscAdmin = !empty($user['is_tsc_admin']);
$usesEntra = !$isTscAdmin && (($user['auth_provider'] ?? 'local') === 'entra');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($usesEntra) {
        flash('error', 'Microsoft Entra credentials are managed outside Command Bridge.');
        header('Location: ' . app_url('/account.php'));
        exit;
    }

    $changedOk = false;
    $currentSecret = (string)($_POST['current_secret'] ?? '');
    $newSecret = (string)($_POST['new_secret'] ?? '');
    $newSecretConfirm = (string)($_POST['new_secret_confirm'] ?? '');

    $currentSecretValid = $isTscAdmin
        ? (!empty($user['password_hash']) && password_verify($currentSecret, (string)$user['password_hash']))
        : (!empty($user['pin_encrypted']) && verify_pin($currentSecret, (string)$user['pin_encrypted']));

    if (!$currentSecretValid) {
        flash('error', $isTscAdmin ? 'Your current password is incorrect.' : 'Your current PIN is incorrect.');
    } elseif ($newSecret === '' || $newSecret !== $newSecretConfirm) {
        flash('error', $isTscAdmin ? 'The new password and confirmation do not match.' : 'The new PIN and confirmation do not match.');
    } elseif (!$isTscAdmin && !preg_match('/^\d{6}$/', $newSecret)) {
        flash('error', 'Your new PIN must contain exactly 6 digits.');
    } elseif ($newSecret === $currentSecret) {
        flash('error', $isTscAdmin ? 'Choose a new password that is different from the current one.' : 'Choose a new PIN that is different from the current one.');
    } elseif (!$isTscAdmin && pin_is_too_obvious($newSecret)) {
        flash('error', 'That PIN is too easy to guess (for example 123456 or 111111). Choose a different six digits.');
    } else {
        if ($isTscAdmin) {
            $stmt = $pdo->prepare("UPDATE users SET password_hash = ?, must_change_pin = 0, failed_login_count = 0, lockout_level = 0, locked_until = NULL, updated_at = NOW() WHERE id = ?");
            $stmt->execute([password_hash($newSecret, PASSWORD_DEFAULT), $userId]);
            touch_user_credentials($userId);
            audit_log($userId, 'change_own_password', 'user', $userId, 'User changed their own password');
            flash('success', 'Your password has been changed.');
            $changedOk = true;
        } else {
            $stmt = $pdo->prepare("UPDATE users SET pin_encrypted = ?, pin_last_reset_at = NOW(), must_change_pin = 0, failed_login_count = 0, lockout_level = 0, locked_until = NULL, updated_at = NOW() WHERE id = ?");
            $stmt->execute([encrypt_pin($newSecret), $userId]);

            $incidentId = (int)(active_incident()['id'] ?? 0);
            if ($incidentId > 0) {
                external_checkin_enqueue_companion_user_snapshot($incidentId, $userId);
            }

            touch_user_credentials($userId);
            audit_log($userId, 'change_own_pin', 'user', $userId, 'User changed their own PIN');
            flash('success', 'Your PIN has been changed.');
            $changedOk = true;
        }
    }

    // Once the credential has been changed the forced-change restriction is lifted.
    if ($changedOk) {
        $_SESSION['user']['must_change_pin'] = 0;
        header('Location: ' . app_url($forcedChange ? '/dashboard/index.php' : '/account.php'));
        exit;
    }
    header('Location: ' . app_url('/account.php'));
    exit;
}

render_header('My Account');
?>
<div class="card" style="max-width:620px;">
  <h2>My Account</h2>
  <?php if ($forcedChange): ?>
    <div class="flash error">An administrator reset your <?= $isTscAdmin ? 'password' : 'PIN' ?>. Choose your own new <?= $isTscAdmin ? 'password' : '6-digit PIN' ?> now to continue. Enter the temporary <?= $isTscAdmin ? 'password' : 'PIN' ?> you were given as the current one.</div>
  <?php endif; ?>
  <p><strong>User:</strong> <?= e((string)$user['real_name']) ?> (<?= e((string)$user['username']) ?>)</p>

  <?php if ($usesEntra): ?>
    <p>Your account uses Microsoft Entra sign-in. Change your Microsoft password through your organization’s Microsoft 365 account management process.</p>
  <?php elseif ($isTscAdmin): ?>
    <p class="small">Enter your current password and a new password. Your password is not displayed or recoverable.</p>
    <form method="post">
      <?= csrf_field() ?>
      <label>Current Password</label>
      <input name="current_secret" type="password" autocomplete="current-password" required>
      <label>New Password</label>
      <input name="new_secret" type="password" autocomplete="new-password" required>
      <label>Confirm New Password</label>
      <input name="new_secret_confirm" type="password" autocomplete="new-password" required>
      <div style="margin-top:16px;">
        <button type="submit">Change Password</button>
        <a class="btn btn-secondary" href="<?= e(app_url('/dashboard/index.php')) ?>">Back to Dashboard</a>
      </div>
    </form>
  <?php else: ?>
    <p class="small">Enter your current PIN and a new six-digit PIN.</p>
    <form method="post">
      <?= csrf_field() ?>
      <label>Current 6-Digit PIN</label>
      <input name="current_secret" type="password" inputmode="numeric" pattern="\d{6}" maxlength="6" autocomplete="current-password" required>
      <label>New 6-Digit PIN</label>
      <input name="new_secret" type="password" inputmode="numeric" pattern="\d{6}" maxlength="6" autocomplete="new-password" required>
      <label>Confirm New 6-Digit PIN</label>
      <input name="new_secret_confirm" type="password" inputmode="numeric" pattern="\d{6}" maxlength="6" autocomplete="new-password" required>
      <div style="margin-top:16px;">
        <button type="submit">Change PIN</button>
        <a class="btn btn-secondary" href="<?= e(app_url('/dashboard/index.php')) ?>">Back to Dashboard</a>
      </div>
    </form>
  <?php endif; ?>
</div>
<?php render_footer(); ?>
