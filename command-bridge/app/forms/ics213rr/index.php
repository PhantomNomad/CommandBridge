<?php
declare(strict_types=1);
/**
 * The original ICS 213RR page was removed in 1.0.2.54; ICS 213RR v2 is the only 213RR form.
 * This stub keeps old bookmarks and old notification links working by forwarding them (query string included) to v2.
 */
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/helpers.php';
$query = (string)($_SERVER['QUERY_STRING'] ?? '');
header('Location: ' . app_url('/forms/ics213rr_v2/index.php') . ($query !== '' ? '?' . $query : ''), true, 302);
exit;
