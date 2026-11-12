<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/security.php';
require_once __DIR__ . '/../../includes/layout.php';
require_login();

header('Location: ' . app_url('/admin/locations.php'), true, 302);
exit;
