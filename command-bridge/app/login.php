<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/security.php';
require_csrf_for_post();

require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/login_throttle.php';

if (!tsc_admin_exists()) {
    header('Location: ' . app_url('/setup/setup_tsc.php'));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $secret = trim($_POST['secret'] ?? '');
    $ip = login_throttle_client_ip();
    $pdo = db();
    $genericError = 'Invalid credentials, or the account is temporarily locked. '
        . 'If you are locked out, ask your TSC or IC to unlock your account.';

    if (login_throttle_ip_blocked($pdo, $ip)) {
        // Blocked requests are not recorded, so waiting out the window always works.
        http_response_code(429);
        flash('error', 'Too many unsuccessful sign-in attempts from this network. Wait ' . LOGIN_IP_WINDOW_MINUTES . ' minutes and try again.');
    } else {
        $user = fetch_user_by_username($username);
        $locked = $user && login_throttle_user_locked_until($user) !== null;
        $ok = false;

        if ($user && !$locked) {
            if ((int)$user['is_tsc_admin'] === 1 && !empty($user['password_hash'])) {
                $ok = password_verify($secret, $user['password_hash']);
            } elseif (!empty($user['pin_encrypted'])) {
                $ok = verify_pin($secret, (string)$user['pin_encrypted']);
            }
        } else {
            // Unknown user or locked account: do the same amount of work, never accept.
            login_throttle_dummy_verify($secret);
        }

        if ($ok) {
            login_throttle_record_success($pdo, $user, $ip);
            login_user($user);
            audit_log((int)$user['id'], 'login', 'user', (int)$user['id'], 'Successful login from ' . $ip);
            header('Location: ' . app_url('/dashboard/index.php'));
            exit;
        }

        // Only a known, currently unlocked account accumulates failures. Attempts
        // against a locked account do not extend the lock; they still count toward
        // the network throttle.
        $failure = login_throttle_record_failure($pdo, ($user && !$locked) ? $user : null, $username, $ip);
        // Record the failed attempt. Never store what was typed (a PIN can end up in the wrong box); only who/where.
        audit_log($user ? (int)$user['id'] : null, 'login_failed', 'user', $user ? (int)$user['id'] : 0,
            ($user ? ($locked ? 'Sign-in attempt on a locked account' : 'Failed sign-in attempt') : 'Failed sign-in attempt for an unknown username') . ' from ' . $ip);
        if ($failure['locked']) {
            audit_log((int)$user['id'], 'account_locked', 'user', (int)$user['id'],
                'Account locked for ' . login_throttle_format_remaining((int)$failure['locked_until'])
                . ' after repeated failed sign-ins from ' . $ip . ' (lock level ' . $failure['level'] . ')');
            notify_tsc_account_locked($user, $ip, login_throttle_format_remaining((int)$failure['locked_until']));
        }
        flash('error', $genericError);
    }
}

$entra = entra_config();
$entraLoginAvailable = !empty($entra['enabled'])
    && trim((string)($entra['tenant_id'] ?? '')) !== ''
    && trim((string)($entra['client_id'] ?? '')) !== ''
    && trim((string)($entra['client_secret'] ?? '')) !== ''
    && trim((string)($entra['redirect_uri'] ?? '')) !== '';

render_header('Login');
?>
<div class="card" style="max-width:520px;">
  <?php if ($entraLoginAvailable): ?>
    <p><a class="button" href="<?= e(app_url('/auth/entra_login.php')) ?>">Sign in with Microsoft</a></p>
  <?php endif; ?>
  <form method="post">
    <?= csrf_field() ?>
    <label for="username">Username</label>
    <input id="username" name="username" required>

    <label for="secret">Password or 6-digit PIN</label>
    <input id="secret" name="secret" type="password" required>

    <div style="margin-top:16px;">
      <button type="submit">Sign in</button>
    </div>
    <p class="small">Technical Services Coordinator administrators sign in with username and password. Incident users sign in with username and PIN.</p>
  </form>
</div>
<?php render_footer(); ?>
