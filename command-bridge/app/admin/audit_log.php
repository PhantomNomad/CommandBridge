<?php
declare(strict_types=1);
// The audit log moved to /audit/index.php (filters, paging, and open to the Incident Commander as well).
require_once dirname(__DIR__) . '/includes/layout.php';
require_login();
header('Location: ' . app_url('/audit/index.php'), true, 301);
exit;
