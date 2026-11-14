<?php
declare(strict_types=1);
/*
 * Saves a PDF of one form record exactly as the form's own "Download PDF" would make it.
 * Run by includes/form_lock.php (cb_form_snapshot_pdf) just before a form is revised, as the web server user:
 *
 *   php app/bin/form_snapshot.php --user=ID --record=FORM_RECORD_ID --out=/path/to/file.pdf
 *
 * It runs the form's pdf.php as that user (so the same permissions apply) and writes the PDF to --out instead of
 * sending it to a browser. Exit code 0 when the PDF was written.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$args = [];
foreach (array_slice($argv, 1) as $a) {
    if (preg_match('/^--([a-z]+)=(.*)$/', $a, $m)) { $args[$m[1]] = $m[2]; }
}
$userId = (int)($args['user'] ?? 0);
$recordId = (int)($args['record'] ?? 0);
$out = (string)($args['out'] ?? '');
if ($userId <= 0 || $recordId <= 0 || $out === '' || !is_dir(dirname($out))) {
    fwrite(STDERR, "usage: form_snapshot.php --user=ID --record=ID --out=FILE\n");
    exit(2);
}

$appRoot = dirname(__DIR__);
// A private, throw-away session holding the user, so require_login() and the permission checks work as usual.
session_save_path(sys_get_temp_dir());
ini_set('session.use_cookies', '0');
session_id('cbsnap' . bin2hex(random_bytes(12)));
session_start();
register_shutdown_function(static function (): void { if (session_status() === PHP_SESSION_ACTIVE) { session_destroy(); } });

require_once $appRoot . '/includes/layout.php';
require_once $appRoot . '/includes/pdf/pdf_request.php';
require_once $appRoot . '/includes/pdf/OfficialPdfTemplateExporter.php';

$st = db()->prepare('SELECT * FROM users WHERE id = ? AND deleted_at IS NULL LIMIT 1');
$st->execute([$userId]);
$user = $st->fetch();
if (!$user) { fwrite(STDERR, "user not found\n"); exit(3); }
$_SESSION['auth_at'] = time();
$_SESSION['last_activity'] = time();
$_SESSION['user'] = [
    'id' => (int)$user['id'], 'username' => $user['username'], 'display_name' => $user['real_name'] ?? $user['username'],
    'email' => $user['email'] ?? '', 'role_code' => $user['role_code'] ?? null, 'incident_id' => $user['incident_id'] ?? null,
    'is_sysadmin' => (int)($user['is_sysadmin'] ?? 0), 'must_change_pin' => 0,
];

$st = db()->prepare('SELECT * FROM form_records WHERE id = ? LIMIT 1');
$st->execute([$recordId]);
$rec = $st->fetch();
if (!$rec) { fwrite(STDERR, "form record not found\n"); exit(3); }
$code = strtoupper(str_replace([' ', '_', '-'], '', (string)$rec['form_type']));
$dir = strtolower($code);
$pdfScript = $appRoot . '/forms/' . $dir . '/pdf.php';
if (!is_file($pdfScript)) { fwrite(STDERR, "no PDF for $code\n"); exit(4); }

// The same query string the form page's "Download PDF" link uses.
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['SCRIPT_NAME'] = '/forms/' . $dir . '/pdf.php';
$_GET = ['incident_id' => (string)$rec['incident_id']];
if (!empty($rec['operational_period_id'])) { $_GET['op_id'] = (string)$rec['operational_period_id']; }
if ($code === 'ICS214' || $code === 'ICS209' || $code === 'ICS209WF') { $_GET['record_id'] = (string)$recordId; }

// Write the finished PDF to --out instead of sending it: OfficialPdfTemplateExporter::sendPdf() copies it there and
// ends the program (exit 0), so whatever pdf.php does around the send does not matter.
$GLOBALS['cb_pdf_capture_path'] = $out;
ob_start();
(static function (string $script): void { require $script; })($pdfScript);
// Reached only when pdf.php returned without making a PDF.
$text = trim((string)ob_get_clean());
fwrite(STDERR, $text !== '' ? mb_substr(strip_tags($text), 0, 300) . "\n" : "no PDF was produced\n");
exit(5);
