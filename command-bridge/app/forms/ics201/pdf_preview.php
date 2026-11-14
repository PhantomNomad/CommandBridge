<?php
declare(strict_types=1);
// The blank ICS 201 is the official ICS Canada form (kept for old links).
require_once dirname(dirname(__DIR__)) . '/includes/layout.php';
require_login();
header('Location: ' . app_url('/assets/pdf/official/ICS201.pdf'));
exit;
