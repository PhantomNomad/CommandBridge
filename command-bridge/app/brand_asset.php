<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/auth.php';
$file = get_app_setting('branding_logo_file', '');
if (!$file || !preg_match('/^org-logo-[a-f0-9]{24}\.(png|jpg|gif|webp)$/', $file)) { http_response_code(404); exit; }
$path = __DIR__ . '/storage/branding/' . $file;
if (!is_file($path)) { http_response_code(404); exit; }
$ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
$types = ['png'=>'image/png','jpg'=>'image/jpeg','gif'=>'image/gif','webp'=>'image/webp'];
header('Content-Type: ' . $types[$ext]);
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, max-age=86400');
readfile($path);