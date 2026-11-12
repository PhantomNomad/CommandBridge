<?php
declare(strict_types=1);
require_once dirname(dirname(__DIR__)) . '/includes/security.php';
require_csrf_for_post();
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/helpers.php';
require_login();

$pdo = db();
$incident = active_incident();
$incidentId = (int)($incident['id'] ?? 0);
$user = current_user();
$userId = (int)($user['id'] ?? 0);
$permissions = $incidentId > 0 ? get_current_user_form_permissions('ICS202', $incidentId) : get_form_permission_defaults('ICS202');
$canEdit = !empty($permissions['can_edit']) || !empty($permissions['can_create']);

function redirect_202(): void { header('Location: ' . app_url('/forms/ics202/index.php')); exit; }
function table_exists_202(PDO $pdo, string $table): bool { try { $st=$pdo->query("SHOW TABLES LIKE ".$pdo->quote($table)); return (bool)($st && $st->fetchColumn()); } catch(Throwable $e){ return false; } }
function get_ics202_upload_form_record(PDO $pdo, int $incidentId): ?array {
    if ($incidentId <= 0 || !table_exists_202($pdo, 'form_records')) { return null; }
    $stmt = $pdo->prepare("SELECT * FROM form_records WHERE incident_id=? AND form_type='ICS202' ORDER BY id DESC LIMIT 1");
    $stmt->execute([$incidentId]);
    $record = $stmt->fetch();
    return is_array($record) ? $record : null;
}
function get_ics202_upload_dir_local(int $incidentId): string { $path = storage_root_path() . '/uploads/incidents/' . $incidentId . '/ics202'; ensure_directory($path); return $path; }

try {
    if ($incidentId <= 0) { throw new RuntimeException('No active incident is available.'); }
    if (!$canEdit) { throw new RuntimeException('You do not have permission to add ICS 202 attachments.'); }
    if (!table_exists_202($pdo, 'form_202_attachments')) { throw new RuntimeException('The form_202_attachments table is missing. Run patch_step10_ics202_auto_iap_and_attachments.sql.'); }

    $formRecord = get_ics202_upload_form_record($pdo, $incidentId);
    $formRecordId = (int)($formRecord['id'] ?? 0);
    if ($formRecordId <= 0) { throw new RuntimeException('Save the ICS 202 draft before adding attachments.'); }
    $st = $pdo->prepare("SELECT id FROM form_202_headers WHERE form_record_id=? LIMIT 1");
    $st->execute([$formRecordId]);
    $headerId = (int)($st->fetchColumn() ?: 0);
    if ($headerId <= 0) { throw new RuntimeException('Save the ICS 202 draft before adding attachments.'); }

    $files = $_FILES['attachments'] ?? [];
    if (!isset($files['name']) || !is_array($files['name'])) { throw new RuntimeException('No files were uploaded.'); }
    $allowed = ics201_allowed_extensions_map();
    $dir = get_ics202_upload_dir_local($incidentId);
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $saved = 0;
    for ($i=0; $i<count($files['name']); $i++) {
        $error = (int)($files['error'][$i] ?? UPLOAD_ERR_NO_FILE);
        $originalName = (string)($files['name'][$i] ?? '');
        if ($error === UPLOAD_ERR_NO_FILE || trim($originalName)==='') { continue; }
        if ($error !== UPLOAD_ERR_OK) { throw new RuntimeException(upload_error_message($error).' File: '.$originalName); }
        $tmpName = (string)($files['tmp_name'][$i] ?? '');
        $size = (int)($files['size'][$i] ?? 0);
        if ($size <= 0) { throw new RuntimeException('Uploaded file is empty: '.$originalName); }
        if ($size > 15 * 1024 * 1024) { throw new RuntimeException('Uploaded file exceeds the 15 MB limit: '.$originalName); }
        $ext = normalize_uploaded_extension($originalName);
        if (!isset($allowed[$ext])) { throw new RuntimeException('Unsupported file type: '.$originalName); }
        $mime = (string)$finfo->file($tmpName);
        if (!in_array($mime, $allowed[$ext], true) && !($ext === 'txt' && str_starts_with($mime, 'text/'))) { throw new RuntimeException('File type verification failed for: '.$originalName); }
        if (in_array($ext, ['bmp','jpg','jpeg','png'], true) && @getimagesize($tmpName) === false) { throw new RuntimeException('The uploaded image file is invalid: '.$originalName); }
        $stored = sprintf('202_%d_%s_%s.%s', $headerId, bin2hex(random_bytes(6)), date('YmdHis'), $ext);
        if (!move_uploaded_file($tmpName, $dir.'/'.$stored) && !rename($tmpName, $dir.'/'.$stored)) { throw new RuntimeException('Failed to store uploaded file: '.$originalName); }
        $stmt=$pdo->prepare("INSERT INTO form_202_attachments (form_202_header_id, original_name, stored_name, mime_type, file_ext, file_size, uploaded_by_user_id, created_at) VALUES (?,?,?,?,?,?,?,NOW())");
        $stmt->execute([$headerId,$originalName,$stored,$mime,$ext,$size,$userId ?: null]);
        $saved++;
    }
    if ($saved <= 0) { throw new RuntimeException('No files were uploaded.'); }
    flash('success', $saved.' attachment(s) uploaded.');
} catch (Throwable $e) {
    flash('error', $e->getMessage());
}
redirect_202();
