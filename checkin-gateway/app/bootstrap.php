<?php
declare(strict_types=1);

const GATEWAY_APP_ROOT = __DIR__ . '/..';
require_once __DIR__ . '/version.php';
const GATEWAY_ENV_PATH = '/etc/commandbridge/checkin_gateway.env';
const GATEWAY_MAX_REQUEST_BYTES = 65536;

require_once __DIR__ . '/login_throttle.php';

function gateway_config(): array {
    static $config = null;
    if (is_array($config)) return $config;

    $config = [
        'env' => 'production', 'sync_enabled' => false, 'db_dsn' => '', 'db_user' => '', 'db_password' => '',
        'hmac_key_id' => '', 'hmac_secret' => '', 'commandbridge_events_url' => '',
        'delivery_mode' => 'push', 'pull_lease_seconds' => 300,
        'request_ttl_seconds' => 300, 'session_idle_seconds' => 28800,
        'connect_timeout_seconds' => 5, 'request_timeout_seconds' => 20, 'max_attempts' => 12, 'timezone' => 'UTC',
    ];
    $values = [];
    $path = getenv('CHECKIN_GATEWAY_ENV_PATH') ?: GATEWAY_ENV_PATH;
    if (!is_file($path) || !is_readable($path)) return $config;

    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) continue;
        [$key, $value] = explode('=', $line, 2);
        $value = trim($value);
        if (strlen($value) >= 2 && $value[0] === '"' && $value[strlen($value) - 1] === '"') {
            $value = substr($value, 1, -1);
        }
        $values[trim($key)] = $value;
    }
    $config['env'] = $values['GATEWAY_ENV'] ?? $config['env'];
    $config['sync_enabled'] = in_array(strtolower($values['GATEWAY_SYNC_ENABLED'] ?? '0'), ['1','true','yes','on'], true);
    $config['db_dsn'] = $values['GATEWAY_DB_DSN'] ?? '';
    $config['db_user'] = $values['GATEWAY_DB_USER'] ?? '';
    $config['db_password'] = $values['GATEWAY_DB_PASSWORD'] ?? '';
    $config['hmac_key_id'] = $values['EXTERNAL_CHECKIN_HMAC_KEY_ID'] ?? '';
    $config['hmac_secret'] = $values['EXTERNAL_CHECKIN_HMAC_SECRET'] ?? '';
    $config['commandbridge_events_url'] = $values['COMMANDBRIDGE_EVENTS_URL'] ?? '';
    $deliveryMode = strtolower(trim((string) ($values['GATEWAY_DELIVERY_MODE'] ?? 'push')));
    $config['delivery_mode'] = in_array($deliveryMode, ['push', 'pull'], true) ? $deliveryMode : 'push';
    $timezone = (string)($values['GATEWAY_TIMEZONE'] ?? 'UTC');
    $config['timezone'] = in_array($timezone, timezone_identifiers_list(), true) ? $timezone : 'UTC';
    date_default_timezone_set($config['timezone']);
    foreach ([
        'GATEWAY_REQUEST_TTL_SECONDS' => ['request_ttl_seconds', 60, 900],
        'GATEWAY_SESSION_IDLE_SECONDS' => ['session_idle_seconds', 300, 28800],
        'GATEWAY_CONNECT_TIMEOUT_SECONDS' => ['connect_timeout_seconds', 1, 30],
        'GATEWAY_REQUEST_TIMEOUT_SECONDS' => ['request_timeout_seconds', 5, 60],
        'GATEWAY_MAX_ATTEMPTS' => ['max_attempts', 1, 50],
        'GATEWAY_PULL_LEASE_SECONDS' => ['pull_lease_seconds', 60, 1800],
    ] as $key => [$target, $min, $max]) {
        $config[$target] = max($min, min($max, (int)($values[$key] ?? $config[$target])));
    }
    return $config;
}

function gateway_db(): PDO {
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;
    $c = gateway_config();
    if ($c['db_dsn'] === '' || $c['db_user'] === '') throw new RuntimeException('Gateway database configuration is unavailable.');
    $pdo = new PDO($c['db_dsn'], $c['db_user'], $c['db_password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    return $pdo;
}

function gateway_log(string $event, array $context = []): void {
    $safe = [];
    foreach ($context as $key => $value) {
        if (in_array($key, ['secret','signature','authorization','password','access_code'], true)) continue;
        $safe[$key] = is_scalar($value) || $value === null ? $value : '[complex]';
    }
    error_log('checkin_gateway ' . $event . ' ' . json_encode($safe, JSON_UNESCAPED_SLASHES));
}

function gateway_json(int $status, array $body): never {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function gateway_h(string|null $value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function gateway_start_session(): void {
    if (session_status() === PHP_SESSION_ACTIVE) return;
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    session_name('CB_CHECKIN');
    ini_set('session.gc_maxlifetime', (string)gateway_config()['session_idle_seconds']);
    session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'secure' => $secure, 'httponly' => true, 'samesite' => 'Strict']);
    ini_set('session.use_strict_mode', '1');
    session_start();
}

function gateway_destroy_session(): void {
    if (session_status() !== PHP_SESSION_ACTIVE) return;
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'] ?: '/', $params['domain'] ?? '', (bool)$params['secure'], (bool)$params['httponly']);
    }
    session_destroy();
}

function gateway_csrf_token(): string {
    gateway_start_session();
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
    return $_SESSION['csrf'];
}

function gateway_require_csrf(): void {
    gateway_start_session();
    $received = (string)($_POST['csrf'] ?? '');
    if ($received === '' || empty($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $received)) {
        gateway_render_error(400, 'Your form session has expired. Please return to the check-in page and try again.');
    }
}

function gateway_render_header(string $title): void {
    $title = gateway_h($title);
    echo <<<HTML
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>{$title} | Command Bridge Check-In</title><link rel="stylesheet" href="/assets/app.css"></head>
<body><main class="container"><header><p class="eyebrow">Command Bridge</p><h1>{$title}</h1></header>
HTML;
}
function gateway_render_footer(): void {
    echo '<footer class="muted" style="margin-top:24px;font-size:.85em">Command Bridge Check-In Gateway ' . gateway_h(GATEWAY_VERSION)
        . ' &middot; free software under the GNU AGPL-3.0 &middot; <a href="' . gateway_h(GATEWAY_SOURCE_URL) . '">source code</a></footer></main></body></html>';
}
function gateway_render_error(int $status, string $message): never {
    http_response_code($status); gateway_render_header('Check-In Gateway'); echo '<div class="notice error">' . gateway_h($message) . '</div>'; gateway_render_footer(); exit;
}
function gateway_flash(string $type, string $message): void { gateway_start_session(); $_SESSION['flash'] = [$type, $message]; }
function gateway_show_flash(): void {
    gateway_start_session();
    if (!empty($_SESSION['flash']) && is_array($_SESSION['flash'])) {
        [$type, $message] = $_SESSION['flash']; unset($_SESSION['flash']);
        echo '<div class="notice ' . gateway_h((string)$type) . '">' . gateway_h((string)$message) . '</div>';
    }
}

function gateway_client_ip(): string { return substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45); }
function gateway_normalize_text(string $value, int $max): string { return mb_substr(trim(preg_replace('/\s+/', ' ', $value) ?? ''), 0, $max); }

function gateway_checkin_status(int $locationId): array {
    $stmt = gateway_db()->prepare("SELECT
        COUNT(*) AS total_resources,
        COALESCE(SUM(CASE WHEN publication_status='published' THEN 1 ELSE 0 END), 0) AS awaiting_checkin,
        COALESCE(SUM(CASE WHEN publication_status='checked_in' THEN 1 ELSE 0 END), 0) AS checked_in,
        COALESCE(SUM(CASE WHEN publication_status='withdrawn' THEN 1 ELSE 0 END), 0) AS withdrawn,
        MAX(updated_at) AS manifest_updated_at
        FROM gateway_expected_resources WHERE gateway_location_id=?");
    $stmt->execute([$locationId]);
    $status = $stmt->fetch() ?: [];
    return [
        'total_resources' => (int)($status['total_resources'] ?? 0),
        'awaiting_checkin' => (int)($status['awaiting_checkin'] ?? 0),
        'checked_in' => (int)($status['checked_in'] ?? 0),
        'withdrawn' => (int)($status['withdrawn'] ?? 0),
        'manifest_updated_at' => $status['manifest_updated_at'] ?: null,
    ];
}

function gateway_format_local_datetime(?string $value): string {
    if (!$value) return 'Not available';
    try {
        return (new DateTimeImmutable($value, new DateTimeZone(date_default_timezone_get())))->format('M j, Y g:i a T');
    } catch (Throwable) {
        return 'Not available';
    }
}

function gateway_location_by_token(string $token): ?array {
    $stmt = gateway_db()->prepare('SELECT * FROM gateway_locations WHERE location_token=? LIMIT 1');
    $stmt->execute([$token]); return $stmt->fetch() ?: null;
}
function gateway_pin_attempt_fingerprint(string $pin): string {
    // A keyed fingerprint permits rate limiting without retaining a PIN or an
    // unsalted value that could be cheaply tested from the database alone.
    $secret = (string)(gateway_config()['hmac_secret'] ?? '');
    return hash_hmac('sha256', $pin, $secret !== '' ? $secret : 'checkin-gateway-local-fallback');
}

/** @return array{blocked:bool, retry_after:int, reason:string} See app/login_throttle.php for the rules. */
function gateway_pin_throttle_status(int $locationId, string $remoteIp, string $fingerprint): array {
    return gateway_throttle_check(gateway_db(), $locationId, $remoteIp, $fingerprint);
}

function gateway_pin_rate_limited(int $locationId, string $remoteIp, string $fingerprint): bool {
    return gateway_pin_throttle_status($locationId, $remoteIp, $fingerprint)['blocked'];
}

function gateway_record_failed_pin_attempt(int $locationId, string $remoteIp, string $fingerprint): void {
    gateway_throttle_record_failure(gateway_db(), $locationId, $remoteIp, $fingerprint);
}

function gateway_companion_roles(array $user): array {
    $roles = json_decode((string)($user['roles_json'] ?? '[]'), true);
    if (!is_array($roles)) return [];
    $roles = array_values(array_unique(array_filter(array_map(
        static fn($role): string => strtoupper(trim((string)$role)),
        $roles
    ), static fn(string $role): bool => in_array($role, ['CIR', 'SAM'], true))));
    return $roles;
}

function gateway_companion_primary_role(array $user): string {
    $roles = gateway_companion_roles($user);
    return in_array('CIR', $roles, true) ? 'CIR' : (in_array('SAM', $roles, true) ? 'SAM' : '');
}

function gateway_companion_user_by_pin(int $locationId, string $pin): ?array {
    $stmt = gateway_db()->prepare(
        'SELECT u.*
           FROM gateway_companion_users u
           INNER JOIN gateway_companion_user_locations ul
             ON ul.gateway_companion_user_id=u.id
            AND ul.revoked_at IS NULL
           INNER JOIN gateway_locations l
             ON l.id=ul.gateway_location_id
            AND l.incident_id=u.incident_id
          WHERE l.id=?
            AND l.lifecycle_status="active"
            AND u.active=1
            AND u.revoked_at IS NULL
          ORDER BY u.id'
    );
    $stmt->execute([$locationId]);
    $matched = null;
    foreach ($stmt->fetchAll() as $user) {
        $hash = (string)($user['pin_verifier'] ?? '');
        if ($hash !== '' && password_verify($pin, $hash) && $matched === null) {
            $matched = $user;
        }
    }
    return $matched;
}

function gateway_require_location_session(): array {
    gateway_start_session();
    $locationId = (int)($_SESSION['location_id'] ?? 0);
    $companionUserId = (int)($_SESSION['companion_user_id'] ?? 0);
    $gatewayUserId = (int)($_SESSION['gateway_user_id'] ?? 0);
    $lastActivity = (int)($_SESSION['last_activity'] ?? 0);
    $idleSeconds = (int)gateway_config()['session_idle_seconds'];

    if ($locationId <= 0 || $companionUserId <= 0 || $gatewayUserId <= 0) {
        gateway_destroy_session();
        gateway_render_error(401, 'Authorized check-in access is required.');
    }
    if ($lastActivity <= 0 || (time() - $lastActivity) > $idleSeconds) {
        gateway_destroy_session();
        gateway_render_error(401, 'Your check-in session has expired due to inactivity. Please sign in again.');
    }

    $stmt = gateway_db()->prepare(
        'SELECT l.*
           FROM gateway_locations l
           INNER JOIN gateway_companion_user_locations ul
             ON ul.gateway_location_id=l.id
            AND ul.gateway_companion_user_id=?
            AND ul.revoked_at IS NULL
           INNER JOIN gateway_companion_users u
             ON u.id=ul.gateway_companion_user_id
            AND u.incident_id=l.incident_id
          WHERE l.id=?
            AND l.lifecycle_status="active"
            AND u.gateway_user_id=?
            AND u.active=1
            AND u.revoked_at IS NULL
          LIMIT 1'
    );
    $stmt->execute([$companionUserId, $locationId, $gatewayUserId]);
    $location = $stmt->fetch();
    if (!$location) {
        gateway_destroy_session();
        gateway_render_error(401, 'Your authorization for this check-in location is no longer active.');
    }
    $_SESSION['last_activity'] = time();
    return $location;
}

function gateway_session_identity(): array {
    gateway_start_session();
    return [
        'gateway_user_id' => (int)($_SESSION['gateway_user_id'] ?? 0),
        'companion_user_id' => (int)($_SESSION['companion_user_id'] ?? 0),
        'name' => (string)($_SESSION['user_display_name'] ?? ''),
        'agency' => '',
        'position' => (string)($_SESSION['user_role'] ?? ''),
        'roles' => is_array($_SESSION['user_roles'] ?? null) ? $_SESSION['user_roles'] : [],
        'authenticated_at' => (string)($_SESSION['authenticated_at'] ?? ''),
        'location_id' => (int)($_SESSION['location_id'] ?? 0),
    ];
}

function gateway_audit(string $action, ?int $locationId = null, array $details = []): void {
    $identity = gateway_session_identity();
    $stmt = gateway_db()->prepare(
        'INSERT INTO gateway_audit_log
            (location_id,gateway_user_id,action,actor_name,actor_agency,actor_position,actor_role,remote_ip,details_json)
         VALUES (?,?,?,?,?,?,?,?,?)'
    );
    $stmt->execute([
        $locationId,
        $identity['gateway_user_id'] ?: null,
        $action,
        $identity['name'] ?: null,
        null,
        $identity['position'] ?: null,
        $identity['position'] ?: null,
        gateway_client_ip(),
        json_encode($details, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
    ]);
}
