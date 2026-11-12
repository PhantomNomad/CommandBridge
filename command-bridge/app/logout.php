<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/auth.php';
$__uid = (int)(current_user()['id'] ?? 0);
if ($__uid > 0) { audit_log($__uid, 'logout', 'user', $__uid, 'Signed out'); }
logout_user();
header('Location: ' . app_url('/login.php'));
exit;
