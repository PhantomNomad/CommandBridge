<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/includes/layout.php';
require_system_administrator();
header('Location: ' . app_url('/admin/settings.php'));
exit;
