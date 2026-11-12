<?php
declare(strict_types=1);
require_once dirname(dirname(__DIR__)) . '/includes/security.php';
require_csrf_for_post();
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/helpers.php';
require_login();
$pdo=db(); $incident=active_incident(); $incidentId=(int)($incident['id']??0);
$permissions = $incidentId > 0 ? get_current_user_form_permissions('ICS202', $incidentId) : get_form_permission_defaults('ICS202');
$canEdit = !empty($permissions['can_edit']) || !empty($permissions['can_create']);
try {
    if (!$canEdit) { throw new RuntimeException('You do not have permission to delete ICS 202 attachments.'); }
    $id=(int)($_POST['attachment_id'] ?? 0);
    $st=$pdo->prepare("SELECT a.*, fr.incident_id FROM form_202_attachments a INNER JOIN form_202_headers h ON h.id=a.form_202_header_id INNER JOIN form_records fr ON fr.id=h.form_record_id WHERE a.id=? LIMIT 1");
    $st->execute([$id]); $a=$st->fetch();
    if ($a && (int)$a['incident_id']===$incidentId) {
        $pdo->prepare("DELETE FROM form_202_attachments WHERE id=?")->execute([$id]);
        $path=storage_root_path().'/uploads/incidents/'.$incidentId.'/ics202/'.$a['stored_name'];
        if (is_file($path)) @unlink($path);
        flash('success','Attachment deleted.');
    }
} catch(Throwable $e){ flash('error',$e->getMessage()); }
header('Location: '.app_url('/forms/ics202/index.php')); exit;
