<?php
declare(strict_types=1);
function security_log(Throwable $e, string $context = ''): void {
    $prefix = $context !== '' ? '[' . $context . '] ' : '';
    error_log($prefix . get_class($e) . ': ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
}

/**
 * Sign-out after inactivity. The limit is PHP's session.gc_maxlifetime (set to 3600 = 1 hour in
 * /etc/php/<ver>/{apache2,cli}/conf.d/90-commandbridge.ini by install.sh / update.sh), so one setting controls
 * both this check and Debian's clean-up of old session files. Values under 5 minutes are ignored.
 */
function cb_session_idle_seconds(): int {
    $seconds = (int)ini_get('session.gc_maxlifetime');
    return $seconds >= 300 ? $seconds : 3600;
}

/** Background requests (the dashboard's notification refresh) do not count as activity. */
function cb_session_is_background_request(): bool {
    return !empty($_SERVER['HTTP_X_REQUESTED_WITH']);
}

/** Start the session with the app's cookie settings, then apply the inactivity limit. Safe to call repeatedly. */
function cb_session_start(): void {
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    if (!headers_sent()) {
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }
    session_start();
    cb_session_enforce_idle();
}

function cb_session_enforce_idle(): void {
    if (empty($_SESSION['user'])) {
        return;
    }
    $now = time();
    $last = (int)($_SESSION['last_activity'] ?? 0);
    if ($last > 0 && $now - $last > cb_session_idle_seconds()) {
        // Signed out: keep only the message for the sign-in page, and issue a new session ID.
        $_SESSION = ['flash' => [['type' => 'error', 'message' => 'You were signed out after '
            . intdiv(cb_session_idle_seconds(), 60) . ' minutes without activity. Please sign in again.']]];
        session_regenerate_id(true);
        return;
    }
    if ($last === 0 || !cb_session_is_background_request()) {
        $_SESSION['last_activity'] = $now;
    }
}

function csrf_token(): string {
    cb_session_start();
    if (empty($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_field(): string {
    return '<input type="hidden" name="csrf_token" value="' .
        htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') . '">';
}

function verify_csrf_token(): void {
    cb_session_start();
    $submitted = $_POST['csrf_token'] ?? '';
    $expected = $_SESSION['csrf_token'] ?? '';
    if (!is_string($submitted) || !is_string($expected) || $submitted === '' || !hash_equals($expected, $submitted)) {
        csrf_failure_response(empty($_SESSION['user']));
    }
}

/**
 * Plain-language answer when a form's security token does not match, instead of a bare "Invalid CSRF token".
 * Almost always the sign-in timed out while the page sat open (PHP's session.gc_maxlifetime), or the page was
 * stale (opened before signing in again in another tab). Nothing from the submitted form has been saved.
 */
function csrf_failure_response(bool $sessionEnded): never {
    $base = defined('APP_BASE_URL') && APP_BASE_URL !== '' ? rtrim((string)APP_BASE_URL, '/') : '';
    $loginUrl = $base . '/login.php';
    $page = basename((string)($_SERVER['SCRIPT_NAME'] ?? ''));

    if ($sessionEnded && empty($_SESSION['flash'])) {   // the inactivity check may already have left a message
        $_SESSION['flash'][] = ['type' => 'error', 'message' => $page === 'login.php'
            ? 'The sign-in page had been open too long. Please sign in again.'
            : 'Your session timed out, so the page you submitted was not saved. Please sign in again.'];
    }
    // The sign-in form itself: just show it again, with the message above.
    if ($sessionEnded && $page === 'login.php') {
        header('Location: ' . $loginUrl, true, 303);
        exit;
    }

    http_response_code(403);
    header('Cache-Control: no-store');
    if (function_exists('ics_send_security_headers')) {
        ics_send_security_headers();
    }

    $accept = strtolower((string)($_SERVER['HTTP_ACCEPT'] ?? ''));
    $wantsJson = str_contains($accept, 'application/json')
        || !empty($_SERVER['HTTP_X_REQUESTED_WITH'])
        || str_contains((string)($_SERVER['SCRIPT_NAME'] ?? ''), '/api/')
        || (bool)preg_grep('/^content-type:\s*application\/json/i', headers_list());
    if ($wantsJson) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($sessionEnded
            ? ['ok' => false, 'error' => 'session_expired', 'message' => 'Your session has timed out. Please sign in again.', 'login_url' => $loginUrl]
            : ['ok' => false, 'error' => 'page_expired', 'message' => 'This page is out of date. Reload it and try again.'],
            JSON_UNESCAPED_SLASHES);
        exit;
    }

    $back = (string)($_SERVER['HTTP_REFERER'] ?? '');
    $sameSite = $back !== '' && parse_url($back, PHP_URL_HOST) === parse_url('//' . (string)($_SERVER['HTTP_HOST'] ?? ''), PHP_URL_HOST);
    $reloadUrl = $sameSite ? $back : $base . '/index.php';
    $esc = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    $seconds = 10;

    header('Content-Type: text/html; charset=utf-8');
    if ($sessionEnded) {
        $title = 'Your session has timed out';
        $body = '<p>You were signed out after ' . intdiv(cb_session_idle_seconds(), 60) . ' minutes without activity. '
            . '<strong>What you entered on that page was not saved.</strong></p>'
            . '<p><a class="button" href="' . $esc($loginUrl) . '">Sign in again</a></p>'
            . '<p class="muted">Taking you to the sign-in page in <span id="cb-countdown">' . $seconds . '</span> seconds&hellip;</p>';
        $refresh = '<meta http-equiv="refresh" content="' . $seconds . ';url=' . $esc($loginUrl) . '">';
        $script = '<script>(function(){var n=' . $seconds . ',el=document.getElementById("cb-countdown");'
            . 'var t=setInterval(function(){n--;if(el&&n>=0){el.textContent=n;}if(n<=0){clearInterval(t);}},1000);})();</script>';
    } else {
        $title = 'This page is out of date';
        $body = '<p>The page you submitted was opened before your current sign-in (for example in another tab), '
            . 'so it could not be accepted. <strong>Nothing was saved.</strong></p>'
            . '<p>Reload the page and enter the information again.</p>'
            . '<p><a class="button" href="' . $esc($reloadUrl) . '">Reload the page</a></p>';
        $refresh = '';
        $script = '';
    }

    echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1">' . $refresh
        . '<title>' . $esc($title) . ' - Command Bridge</title>'
        . '<style>body{font-family:system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;background:#f3f4f6;color:#111827;margin:0;'
        . 'display:flex;min-height:100vh;align-items:center;justify-content:center;padding:1rem;box-sizing:border-box}'
        . '.card{background:#fff;border-radius:10px;box-shadow:0 2px 10px rgba(0,0,0,.08);padding:2rem;max-width:30rem;width:100%}'
        . 'h1{font-size:1.4rem;margin:0 0 1rem}.button{display:inline-block;background:#1d4ed8;color:#fff;text-decoration:none;'
        . 'padding:.65rem 1.2rem;border-radius:6px;font-weight:600}.button:focus,.button:hover{background:#1e40af}'
        . '.muted{color:#6b7280;font-size:.95rem}</style></head><body><main class="card" role="alert">'
        . '<h1>' . $esc($title) . '</h1>' . $body . '</main>' . $script . '</body></html>';
    exit;
}

function require_csrf_for_post(): void {
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        verify_csrf_token();
    }
}
