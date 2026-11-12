<?php
declare(strict_types=1);
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';

function current_user(): ?array {
    return $_SESSION['user'] ?? null;
}

function require_login(): void {
    if (!current_user()) {
        header('Location: ' . app_url('/login.php'));
        exit;
    }

    // After an administrator reset, the user must choose their own PIN/password
    // before using anything else.
    if (!empty(current_user()['must_change_pin'])) {
        $script = basename((string)($_SERVER['SCRIPT_NAME'] ?? ''));
        if (!in_array($script, ['account.php', 'logout.php'], true)) {
            header('Location: ' . app_url('/account.php'));
            exit;
        }
    }

    // End sessions of accounts that were deactivated/deleted, or whose PIN/password was changed after this session
    // signed in (for example an administrator reset). Costs one small query per request.
    if (!auth_session_still_valid()) {
        $_SESSION = ['flash' => [['type' => 'error', 'message' => 'Your session ended because your account or sign-in details changed. Please sign in again.']]];
        header('Location: ' . app_url('/login.php'));
        exit;
    }

    // Automatic audit trail for form saves / submissions (see ics_audit_register_form_post_hook()).
    if (function_exists('ics_audit_register_form_post_hook')) { ics_audit_register_form_post_hook(); }
}

/**
 * False when the signed-in account was deactivated or deleted, or its PIN/password was changed after this session
 * started. Tolerates a database that has not been patched yet (no credentials_changed_at column).
 */
function auth_session_still_valid(): bool {
    static $result = null;
    if ($result !== null) { return $result; }
    $uid = (int)(current_user()['id'] ?? 0);
    if ($uid <= 0) { return $result = true; }
    if (empty($_SESSION['auth_at'])) { $_SESSION['auth_at'] = time(); }

    $row = null;
    try {
        $st = db()->prepare('SELECT is_active, deleted_at, credentials_changed_at FROM users WHERE id = ? LIMIT 1');
        $st->execute([$uid]);
        $row = $st->fetch();
    } catch (Throwable $e) {
        try {
            $st = db()->prepare('SELECT is_active, deleted_at FROM users WHERE id = ? LIMIT 1');
            $st->execute([$uid]);
            $row = $st->fetch();
        } catch (Throwable $e2) { return $result = true; }
    }
    if (!$row || !empty($row['deleted_at']) || (isset($row['is_active']) && (int)$row['is_active'] === 0)) {
        return $result = false;
    }
    if (!empty($row['credentials_changed_at'])) {
        $changed = strtotime((string)$row['credentials_changed_at'] . ' UTC');
        if ($changed !== false && $changed > (int)$_SESSION['auth_at']) { return $result = false; }
    }
    return $result = true;
}

function require_tsc_admin(): void {
    require_login();
    $user = current_user();
    if (empty($user['is_tsc_admin'])) {
        http_response_code(403);
        echo 'Forbidden';
        exit;
    }
}

function current_user_can_manage_users(): bool {
    $user = current_user();
    if (!$user) {
        return false;
    }

    if (!empty($user['is_tsc_admin'])) {
        return true;
    }

    $userId = (int)($user['id'] ?? 0);
    if ($userId <= 0) {
        return false;
    }

    $incidentId = (int)($user['incident_id'] ?? 0);
    if ($incidentId <= 0) {
        $incidentId = (int)(active_incident()['id'] ?? 0);
    }
    if ($incidentId <= 0) {
        return false;
    }

    $stmt = db()->prepare("
        SELECT COUNT(*) AS c
        FROM incident_positions ip
        INNER JOIN roles r ON r.id = ip.role_id
        WHERE ip.incident_id = ?
          AND ip.user_id = ?
          AND ip.deleted_at IS NULL
          AND (ip.assigned_end IS NULL OR ip.assigned_end >= NOW())
          AND r.role_code IN ('IC', 'DOCL', 'FSC')
    ");
    $stmt->execute([$incidentId, $userId]);
    $row = $stmt->fetch();

    return ((int)($row['c'] ?? 0)) > 0;
}

function require_user_management_access(): void {
    require_login();
    if (!current_user_can_manage_users()) {
        http_response_code(403);
        echo 'Forbidden';
        exit;
    }
}


function login_user(array $user): void {
    // A new session ID on every sign-in prevents session fixation.
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_regenerate_id(true);
    }
    $_SESSION['auth_at'] = time();
    $_SESSION['user'] = [
        'id' => $user['id'],
        'username' => $user['username'],
        'display_name' => $user['real_name'] ?? $user['username'],
        'email' => $user['email'] ?? '',
        'role_code' => $user['role_code'] ?? null,
        'incident_id' => $user['incident_id'] ?? null,
        'is_tsc_admin' => (int)($user['is_tsc_admin'] ?? 0),
        // Entra accounts cannot change a credential here, so never trap them.
        'must_change_pin' => (!empty($user['must_change_pin']) && ($user['auth_provider'] ?? 'local') !== 'entra') ? 1 : 0,
    ];
}

function logout_user(): void {
    $_SESSION = [];
    session_destroy();
}

function tsc_admin_exists(): bool {
    $stmt = db()->query("SELECT COUNT(*) AS c FROM users WHERE is_tsc_admin = 1 AND deleted_at IS NULL");
    $row = $stmt->fetch();
    return ((int)$row['c']) > 0;
}

function fetch_user_by_username(string $username): ?array {
    $stmt = db()->prepare("
        SELECT
            u.*,
            r.role_code AS role_code,
            ip.incident_id
        FROM users u
        LEFT JOIN incident_positions ip
            ON ip.user_id = u.id
           AND ip.deleted_at IS NULL
           AND (ip.assigned_end IS NULL OR ip.assigned_end >= NOW())
        LEFT JOIN roles r ON r.id = ip.role_id
        WHERE u.username = ? AND u.deleted_at IS NULL AND u.is_active = 1
        ORDER BY ip.id DESC
        LIMIT 1
    ");
    $stmt->execute([$username]);
    $row = $stmt->fetch();
    return $row ?: null;
}


function current_user_can_create_operational_period(int $incidentId): bool {
    $user = current_user();
    if (!$user || $incidentId <= 0) {
        return false;
    }

    // Admin/TSC (Technical Services Coordinator) is always authorized.
    if (!empty($user['is_tsc_admin'])) {
        return true;
    }

    $roleCodes = get_user_role_codes($incidentId, (int)($user['id'] ?? 0));
    $roleCodes = array_values(array_unique(array_filter(array_map('strtoupper', $roleCodes))));

    // IC = Incident Commander. PSC/PSC1/PLANNING_SECTION_CHIEF are accepted to support existing/local role coding.
    $allowed = ['IC', 'PSC', 'PSC1', 'PLANNING_SECTION_CHIEF', 'PLANNING'];
    foreach ($allowed as $role) {
        if (in_array($role, $roleCodes, true)) {
            return true;
        }
    }

    return false;
}

function require_operational_period_create_access(int $incidentId): void {
    require_login();
    if (!current_user_can_create_operational_period($incidentId)) {
        http_response_code(403);
        echo 'Forbidden';
        exit;
    }
}

function fetch_user_by_entra_object_id(string $objectId): ?array {
    $stmt = db()->prepare('SELECT * FROM users WHERE entra_object_id = ? AND auth_provider = \'entra\' AND deleted_at IS NULL LIMIT 1');
    $stmt->execute([$objectId]);
    $user = $stmt->fetch();
    return $user ?: null;
}


function get_app_setting(string $settingKey, ?string $default = null): ?string {
    try {
        $stmt = db()->prepare("SELECT setting_value FROM app_settings WHERE setting_key = ? LIMIT 1");
        $stmt->execute([$settingKey]);
        $value = $stmt->fetchColumn();
        return $value === false ? $default : (string)$value;
    } catch (Throwable $e) {
        return $default;
    }
}

function save_app_setting(string $settingKey, ?string $settingValue): void {
    $stmt = db()->prepare("
        INSERT INTO app_settings (setting_key, setting_value, updated_at)
        VALUES (?, ?, NOW())
        ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW()
    ");
    $stmt->execute([$settingKey, $settingValue]);
}

function entra_config(): array {
    return [
        'enabled' => (get_app_setting('entra_enabled', defined('ENTRA_ENABLED') && ENTRA_ENABLED ? '1' : '0') === '1'),
        'tenant_id' => get_app_setting('entra_tenant_id', defined('ENTRA_TENANT_ID') ? ENTRA_TENANT_ID : ''),
        'client_id' => get_app_setting('entra_client_id', defined('ENTRA_CLIENT_ID') ? ENTRA_CLIENT_ID : ''),
        'client_secret' => get_app_setting('entra_client_secret', defined('ENTRA_CLIENT_SECRET') ? ENTRA_CLIENT_SECRET : ''),
        'redirect_uri' => get_app_setting('entra_redirect_uri', defined('ENTRA_REDIRECT_URI') ? ENTRA_REDIRECT_URI : ''),
        'scopes' => get_app_setting('entra_scopes', defined('ENTRA_SCOPES') ? ENTRA_SCOPES : 'openid profile email User.Read'),
    ];
}

function save_entra_config(array $data): void {
    save_app_setting('entra_enabled', !empty($data['entra_enabled']) ? '1' : '0');
    save_app_setting('entra_tenant_id', trim((string)($data['entra_tenant_id'] ?? '')));
    save_app_setting('entra_client_id', trim((string)($data['entra_client_id'] ?? '')));
    save_app_setting('entra_client_secret', trim((string)($data['entra_client_secret'] ?? '')));
    save_app_setting('entra_redirect_uri', trim((string)($data['entra_redirect_uri'] ?? '')));
    save_app_setting('entra_scopes', trim((string)($data['entra_scopes'] ?? 'openid profile email User.Read')));
}

function suggested_entra_redirect_uri(): string {
    $configured = trim((string)get_app_setting('entra_redirect_uri', ''));
    if ($configured !== '') {
        return $configured;
    }

    $base = defined('APP_BASE_URL') ? (string)APP_BASE_URL : '/';
    if (preg_match('#^https?://#i', $base)) {
        return rtrim($base, '/') . '/auth/entra_callback.php';
    }

    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    $scheme = $https ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'your-server';
    return $scheme . '://' . $host . '/' . ltrim($base, '/') . 'auth/entra_callback.php';
}

function validate_entra_config(array $config): array {
    $tenantId = trim((string)($config['tenant_id'] ?? ''));
    $clientId = trim((string)($config['client_id'] ?? ''));
    $clientSecret = trim((string)($config['client_secret'] ?? ''));
    $scopes = trim((string)($config['scopes'] ?? 'https://graph.microsoft.com/.default'));

    if ($tenantId === '' || $clientId === '' || $clientSecret === '') {
        return ['ok' => false, 'message' => 'Tenant ID, Client ID, and Client Secret are required before validation.'];
    }

    $tokenUrl = 'https://login.microsoftonline.com/' . rawurlencode($tenantId) . '/oauth2/v2.0/token';
    $postFields = http_build_query([
        'client_id' => $clientId,
        'client_secret' => $clientSecret,
        'grant_type' => 'client_credentials',
        'scope' => 'https://graph.microsoft.com/.default',
    ]);

    $ch = curl_init($tokenUrl);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $postFields,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
    ]);
    $response = curl_exec($ch);
    $curlError = curl_error($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($response === false) {
        return ['ok' => false, 'message' => 'Validation request failed: ' . $curlError];
    }

    $body = json_decode((string)$response, true);
    if ($httpCode >= 200 && $httpCode < 300 && !empty($body['access_token'])) {
        return ['ok' => true, 'message' => 'Entra settings validated successfully. Tenant, client ID, and client secret can obtain a token.'];
    }

    $error = $body['error_description'] ?? $body['error'] ?? ('HTTP ' . $httpCode);
    return ['ok' => false, 'message' => 'Entra validation failed: ' . $error];
}


function entra_get_app_access_token(): array {
    $config = entra_config();
    $tenantId = trim((string)($config['tenant_id'] ?? ''));
    $clientId = trim((string)($config['client_id'] ?? ''));
    $clientSecret = trim((string)($config['client_secret'] ?? ''));
    if ($tenantId === '' || $clientId === '' || $clientSecret === '') {
        return ['ok' => false, 'message' => 'Microsoft Entra settings are incomplete.'];
    }
    $ch = curl_init('https://login.microsoftonline.com/' . rawurlencode($tenantId) . '/oauth2/v2.0/token');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query([
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'grant_type' => 'client_credentials',
            'scope' => 'https://graph.microsoft.com/.default',
        ]),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
    ]);
    $response = curl_exec($ch);
    $error = curl_error($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($response === false) return ['ok' => false, 'message' => 'Microsoft token request failed: ' . $error];
    $body = json_decode((string)$response, true);
    if ($httpCode >= 200 && $httpCode < 300 && !empty($body['access_token'])) return ['ok' => true, 'access_token' => (string)$body['access_token']];
    return ['ok' => false, 'message' => 'Microsoft token request failed: ' . ($body['error_description'] ?? $body['error'] ?? ('HTTP ' . $httpCode))];
}

function entra_lookup_user(string $usernameOrEmail): array {
    $query = trim($usernameOrEmail);
    if ($query === '') return ['ok' => false, 'message' => 'Enter a username or email address first.'];
    $token = entra_get_app_access_token();
    if (empty($token['ok'])) return $token;
    $select = '$select=id,displayName,userPrincipalName,mail,mobilePhone,businessPhones';
    $url = 'https://graph.microsoft.com/v1.0/users/' . rawurlencode($query) . '?' . $select;
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token['access_token'], 'Accept: application/json'],
    ]);
    $response = curl_exec($ch);
    $error = curl_error($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($response === false) return ['ok' => false, 'message' => 'Microsoft Graph user lookup failed: ' . $error];
    $body = json_decode((string)$response, true);
    if ($httpCode === 404) return ['ok' => false, 'message' => 'No Microsoft Entra user was found for that username or email address.'];
    if ($httpCode < 200 || $httpCode >= 300) return ['ok' => false, 'message' => 'Microsoft Graph user lookup failed: ' . ($body['error']['message'] ?? ('HTTP ' . $httpCode))];
    $phone = trim((string)($body['mobilePhone'] ?? ''));
    if ($phone === '' && !empty($body['businessPhones'][0])) $phone = (string)$body['businessPhones'][0];
    return ['ok' => true, 'user' => [
        'id' => $body['id'] ?? '',
        'displayName' => $body['displayName'] ?? '',
        'userPrincipalName' => $body['userPrincipalName'] ?? '',
        'mail' => $body['mail'] ?? ($body['userPrincipalName'] ?? ''),
        'phone' => $phone,
    ]];
}


function is_system_administrator(): bool {
    return !empty(current_user()['is_tsc_admin']);
}

function require_system_administrator(): void {
    require_tsc_admin();
}

function branding_settings(): array {
    return [
        'application_name' => get_app_setting('branding_application_name', defined('APP_SHORT_NAME') ? APP_SHORT_NAME : APP_NAME) ?: 'Command Bridge',
        'organization_name' => get_app_setting('branding_organization_name', defined('APP_ORG_NAME') ? APP_ORG_NAME : '') ?: 'Organization',
        'header_text' => get_app_setting('branding_header_text', '') ?: '',
        'footer_text' => get_app_setting('branding_footer_text', '') ?: '',
        'theme' => get_app_setting('branding_theme', 'blue') ?: 'blue',
        'show_ics_logo' => get_app_setting('branding_show_ics_logo', '1') === '1',
        'logo_file' => get_app_setting('branding_logo_file', '') ?: '',
    ];
}


function current_user_can_edit_operational_period(int $incidentId): bool {
    $user = current_user();
    if (!$user || $incidentId <= 0) return false;
    if (!empty($user['is_tsc_admin'])) return true;
    $roleCodes = get_user_role_codes($incidentId, (int)($user['id'] ?? 0));
    $roleCodes = array_map('strtoupper', $roleCodes);
    foreach (['IC', 'INCIDENT_COMMANDER', 'DIC', 'DEPUTY_IC', 'DEPUTY_INCIDENT_COMMANDER'] as $role) {
        if (in_array($role, $roleCodes, true)) return true;
    }
    return false;
}
