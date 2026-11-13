<?php
declare(strict_types=1);
require_once __DIR__ . '/security.php';


// Cookie settings and the inactivity sign-out live in security.php (cb_session_start).
cb_session_start();

if (!defined('APP_BASE_URL')) {
    define('APP_BASE_URL', '/');
}

if (!defined('APP_NAME')) {
    define('APP_NAME', 'Command Bridge');
    define('APP_SHORT_NAME', 'Command Bridge');
    define('APP_ORG_NAME', 'Organization');
    define('APP_ICS_LOGO', '/assets/img/ics-canada-logo.svg');
    define('APP_CUSTOM_LOGO', '/assets/img/organization-logo.gif');
}


if (!defined('MAIL_FROM_ADDRESS')) {
    define('MAIL_FROM_ADDRESS', 'noreply@example.invalid');
    define('MAIL_FROM_NAME', APP_NAME);
}

// Time zone: APP_TIMEZONE in db.env (install.sh asks for it), else the PHP default, else UTC.
require_once __DIR__ . '/db.php';
$cbTimezone = '';
try { $cbTimezone = (string)(load_db_env()['APP_TIMEZONE'] ?? ''); } catch (Throwable $e) { $cbTimezone = ''; }
if ($cbTimezone === '' || !in_array($cbTimezone, DateTimeZone::listIdentifiers(), true)) { $cbTimezone = (string)ini_get('date.timezone') ?: 'UTC'; }
date_default_timezone_set($cbTimezone);
unset($cbTimezone);


// Microsoft Entra ID / M365 SSO settings.
// Fill these values after creating an App Registration in Entra ID.
if (!defined('ENTRA_ENABLED')) {
    define('ENTRA_ENABLED', false);
    define('ENTRA_TENANT_ID', 'YOUR_TENANT_ID');
    define('ENTRA_CLIENT_ID', 'YOUR_CLIENT_ID');
    define('ENTRA_CLIENT_SECRET', 'YOUR_CLIENT_SECRET');
    define('ENTRA_REDIRECT_URI', APP_BASE_URL . 'auth/entra_callback.php');
    define('ENTRA_SCOPES', 'openid profile email User.Read');
}

require_once __DIR__ . '/security_headers.php';
ics_send_security_headers();
