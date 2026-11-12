<?php
declare(strict_types=1);
$appRoot = dirname(dirname(__DIR__));
require_once $appRoot . '/includes/layout.php';
require_once $appRoot . '/includes/pdf/Ics203PdfRenderer.php';
require_once $appRoot . '/includes/pdf/pdf_request.php';
require_login();

$pdo = db();
$incident = active_incident();
$incidentId = (int)($incident['id'] ?? 0);
if ($incidentId <= 0) { http_response_code(400); echo 'No active incident.'; exit; }
if (!current_user_can_view_form('ICS203', $incidentId)) { http_response_code(403); echo 'Forbidden'; exit; }

function ics203_pdf_table_exists(PDO $pdo, string $table): bool { try { $st=$pdo->query("SHOW TABLES LIKE ".$pdo->quote($table)); return (bool)($st && $st->fetchColumn()); } catch(Throwable $e){ return false; } }
function ics203_position_catalog(): array {
    return [
        ['Command Staff','Incident Commander',['IC']],
        ['Command Staff','Safety Officer',['SOFR','SO']],
        ['Command Staff','Information Officer',['PIO','IO']],
        ['Command Staff','Liaison Officer',['LOFR','LO']],
        ['Operations Section','Operations Section Chief',['OSC','OPS','OSCHIEF']],
        ['Operations Section','Staging Area Manager',['STAM','SAM']],
        ['Operations Section','Branch Director',['BD','BDIR']],
        ['Operations Section','Division/Group Supervisor',['DIVS','DGS','DGSUP']],
        ['Operations Section','Air Operations Branch Director',['AOBD']],
        ['Planning Section','Planning Section Chief',['PSC','PLAN','PSCHIEF']],
        ['Planning Section','Resources Unit Leader',['RESL','RUL']],
        ['Planning Section','Situation Unit Leader',['SITL']],
        ['Planning Section','Documentation Unit Leader',['DOCL','DUL']],
        ['Planning Section','Demobilization Unit Leader',['DMOB','DEMOB']],
        ['Planning Section','Technical Specialist',['THSP','TS']],
        ['Logistics Section','Logistics Section Chief',['LSC','LOG','LSCHIEF']],
        ['Logistics Section','Communication Unit Leader',['COML']],
        ['Logistics Section','Medical Unit Leader',['MEDL','MUL']],
        ['Logistics Section','Food Unit Leader',['FOOD']],
        ['Logistics Section','Supply Unit Leader',['SPUL','SUL']],
        ['Logistics Section','Facilities Unit Leader',['FUL']],
        ['Logistics Section','Ground Support Unit Leader',['GSUL','GUL']],
        ['Finance / Administration Section','Finance/Admin Section Chief',['FSC','FAC','FIN','FAS']],
        ['Finance / Administration Section','Time Unit Leader',['TIME','TUL']],
        ['Finance / Administration Section','Procurement Unit Leader',['PROC','PUL']],
        ['Finance / Administration Section','Compensation/Claims Unit Leader',['COMP','CCUL','CCL']],
        ['Finance / Administration Section','Cost Unit Leader',['COST']],
    ];
}

function ics203_pdf_rows(PDO $pdo, int $incidentId): array {
    $positions = ics203_position_catalog();
    $assigned = [];
    try {
        $st = $pdo->prepare("SELECT r.role_code, u.id AS user_id, u.real_name, u.username
            FROM incident_positions ip
            INNER JOIN roles r ON r.id=ip.role_id
            LEFT JOIN users u ON u.id=ip.user_id AND u.deleted_at IS NULL
            WHERE ip.incident_id=? AND ip.deleted_at IS NULL AND (ip.assigned_end IS NULL OR ip.assigned_end >= NOW())");
        $st->execute([$incidentId]);
        foreach ($st->fetchAll() as $r) {
            $code = strtoupper(trim((string)$r['role_code']));
            if ($code === '') continue;
            $assigned[$code] = ['user_id'=>(int)($r['user_id'] ?? 0), 'name'=>(string)($r['real_name'] ?: $r['username'] ?: '')];
        }
    } catch (Throwable $e) {}
    $rows = [];
    $i = 1;
    foreach ($positions as $p) {
        $a = ['user_id'=>null,'name'=>''];
        foreach ((array)$p[2] as $code) {
            $code = strtoupper((string)$code);
            if (isset($assigned[$code])) { $a = $assigned[$code]; break; }
        }
        $rows[] = ['section_name'=>$p[0], 'position_title'=>$p[1], 'assigned_name'=>$a['name'], 'sort_order'=>$i++];
    }
    return $rows;
}
function ics203_pdf_ensure_record(PDO $pdo, int $incidentId, array $rows): void {
    if (!ics203_pdf_table_exists($pdo, 'form_203_assignments')) return;
    $fr = get_form_record($incidentId, 'ICS203');
    $id = (int)($fr['id'] ?? 0);
    if ($id <= 0) {
        $st=$pdo->prepare("INSERT INTO form_records (incident_id, form_type, form_number, status, prepared_by_user_id, prepared_at) VALUES (?, 'ICS203', '203', 'draft', ?, NOW())");
        $st->execute([$incidentId, current_user()['id'] ?? null]); $id=(int)$pdo->lastInsertId();
    }
    $pdo->prepare("DELETE FROM form_203_assignments WHERE form_record_id=?")->execute([$id]);
    $st=$pdo->prepare("INSERT INTO form_203_assignments (form_record_id,section_name,position_title,assigned_name,sort_order) VALUES (?,?,?,?,?)");
    foreach($rows as $r){ $st->execute([$id,$r['section_name'],$r['position_title'],$r['assigned_name'] ?: null,$r['sort_order']]); }
}
$rows = ics203_pdf_rows($pdo, $incidentId);
$hasIc = false; foreach ($rows as $r) { if ($r['position_title']==='Incident Commander' && trim((string)$r['assigned_name']) !== '') $hasIc = true; }
if (!$hasIc) { http_response_code(409); echo 'ICS 203 is incomplete because no Incident Commander is assigned.'; exit; }
try { ics203_pdf_ensure_record($pdo, $incidentId, $rows); } catch(Throwable $e) {}

$op = [];
if (function_exists('current_operational_period')) {
    $cur = current_operational_period($incidentId);
    if ($cur) { $op = ['id'=>$cur['id'] ?? null, 'name'=>$cur['period_name'] ?? $cur['name'] ?? '', 'start_time'=>$cur['op_start'] ?? $cur['start_time'] ?? '', 'end_time'=>$cur['op_end'] ?? $cur['end_time'] ?? '']; }
}

try {
    $layout = require $appRoot . '/includes/pdf/ics203_layout.php';
    $renderer = new Ics203PdfRenderer($layout);
    $renderer->renderFilled(['incident'=>$incident, 'operational_period'=>$op, 'assignments'=>$rows, 'attachments'=>[], 'inline'=>ics_pdf_preview_requested()]);
    exit;
} catch (Throwable $e) {
    http_response_code(500); render_header('ICS 203 PDF'); echo '<div class="card"><p>'.e($e->getMessage()).'</p></div>'; render_footer(); exit;
}
