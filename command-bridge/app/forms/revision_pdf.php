<?php
declare(strict_types=1);
/*
 * Opens the saved PDF of an earlier version of a form (made when the form was revised; see includes/form_lock.php).
 * Anyone who can view the form can open its earlier versions.
 */
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/form_lock.php';
require_login();

$id = (int)($_GET['id'] ?? 0);
$st = db()->prepare('SELECT * FROM form_revisions WHERE id = ? LIMIT 1');
$st->execute([$id]);
$rev = $st->fetch();
$fail = static function (int $code, string $msg): never {
    http_response_code($code);
    header('Content-Type: text/plain; charset=utf-8');
    echo $msg;
    exit;
};
if (!$rev || empty($rev['snapshot_file'])) { $fail(404, 'That earlier version has no saved PDF.'); }
if (!current_user_can_view_form((string)$rev['form_code'], (int)$rev['incident_id'])) { $fail(403, 'Forbidden'); }
if ((string)$rev['form_code'] === 'ICS214') {
    // Personal logs: the same people who can open the log itself.
    require_once __DIR__ . '/../includes/ics214_log.php';
    $owner = ics214_owner_of_record((int)$rev['form_record_id']);
    $st = db()->prepare('SELECT operational_period_id FROM form_records WHERE id = ? LIMIT 1');
    $st->execute([(int)$rev['form_record_id']]);
    $access = ics214_access((int)$rev['incident_id'], $owner, (int)($st->fetchColumn() ?: 0));
    if (empty($access['view'])) { $fail(403, 'Forbidden'); }
}

$rel = (string)$rev['snapshot_file'];
$root = realpath(storage_root_path());
$path = realpath(storage_root_path() . '/' . $rel);
if ($root === false || $path === false || strpos($path, $root . DIRECTORY_SEPARATOR) !== 0 || !is_file($path)) {
    $fail(404, 'The saved PDF is missing from storage.');
}
audit_log((int)(current_user()['id'] ?? 0), 'view', (string)$rev['form_code'], (int)$rev['form_record_id'],
    'Opened the saved PDF of ' . ics_audit_form_label((string)$rev['form_code']) . ' ' . ((int)$rev['revision'] === 0 ? 'original' : 'revision ' . (int)$rev['revision']));
$name = $rev['form_code'] . '_' . ((int)$rev['revision'] === 0 ? 'original' : 'rev' . (int)$rev['revision']) . '.pdf';
header('Content-Type: application/pdf');
header('Content-Length: ' . (string)filesize($path));
header('Content-Disposition: inline; filename="' . preg_replace('/[^A-Za-z0-9._-]+/', '_', $name) . '"');
header('X-Content-Type-Options: nosniff');
readfile($path);
exit;
