<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') gateway_render_error(405, 'Method not allowed.');
gateway_require_csrf();

$token = preg_replace('/[^A-Za-z0-9]/', '', (string)($_POST['location_token'] ?? ''));
$location = gateway_location_by_token($token);
if (!$location || $location['lifecycle_status'] !== 'active') {
    gateway_render_error(404, 'This check-in location is not currently active.');
}

$pin = trim((string)($_POST['companion_pin'] ?? ''));
if ($pin === '' || !preg_match('/^\d{1,64}$/', $pin)) {
    gateway_flash('error', 'Enter your individual CIR/SAM PIN.');
    header('Location: /?location=' . rawurlencode($token), true, 303);
    exit;
}

$ip = gateway_client_ip();
$fingerprint = gateway_pin_attempt_fingerprint($pin);
$throttle = gateway_pin_throttle_status((int)$location['id'], $ip, $fingerprint);
if ($throttle['blocked']) {
    header('Retry-After: ' . (string)$throttle['retry_after']);
    gateway_render_error(429, 'Too many unsuccessful PIN attempts. Try again in ' . gateway_throttle_wait_text($throttle['retry_after']) . '.');
}

$user = gateway_companion_user_by_pin((int)$location['id'], $pin);
if (!$user) {
    gateway_record_failed_pin_attempt((int)$location['id'], $ip, $fingerprint);
    // If this failure just tripped a limit, leave a record so the TSC can see it.
    $after = gateway_pin_throttle_status((int)$location['id'], $ip, $fingerprint);
    if ($after['blocked']) {
        gateway_audit('pin_lockout_triggered', (int)$location['id'], [
            'reason' => $after['reason'],
            'wait_seconds' => $after['retry_after'],
        ]);
    }
    gateway_flash('error', 'The individual CIR/SAM PIN was not accepted for this location.');
    header('Location: /?location=' . rawurlencode($token), true, 303);
    exit;
}

$roles = gateway_companion_roles($user);
$role = gateway_companion_primary_role($user);
if (!$roles || $role === '') {
    gateway_record_failed_pin_attempt((int)$location['id'], $ip, $fingerprint);
    gateway_render_error(401, 'Your CIR/SAM authorization is not valid for this location.');
}

gateway_start_session();
session_regenerate_id(true);
$_SESSION = [
    'location_id' => (int)$location['id'],
    'companion_user_id' => (int)$user['id'],
    'gateway_user_id' => (int)$user['gateway_user_id'],
    'user_display_name' => (string)$user['display_name'],
    'user_roles' => $roles,
    'user_role' => $role,
    'authenticated_at' => gmdate('c'),
    'last_activity' => time(),
    'csrf' => bin2hex(random_bytes(32)),
];
gateway_audit('location_session_started', (int)$location['id'], [
    'authorized_roles' => $roles,
    'authenticated_at' => $_SESSION['authenticated_at'],
]);
header('Location: /checkin.php', true, 303);
