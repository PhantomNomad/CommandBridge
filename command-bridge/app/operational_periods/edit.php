<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/security.php';
require_csrf_for_post();
require_once dirname(__DIR__) . '/includes/layout.php';
require_login();

$periodId = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$stmt = db()->prepare('SELECT * FROM operational_periods WHERE id=? AND deleted_at IS NULL');
$stmt->execute([$periodId]);
$period = $stmt->fetch();
if (!$period) { http_response_code(404); exit('Operational period not found.'); }
$incidentId = (int)$period['incident_id'];
if (!current_user_can_edit_operational_period($incidentId)) { http_response_code(403); exit('Forbidden'); }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $number = (int)($_POST['period_number'] ?? 0);
    $name = trim((string)($_POST['period_name'] ?? ''));
    $start = trim((string)($_POST['op_start'] ?? ''));
    $end = trim((string)($_POST['op_end'] ?? ''));
    $status = trim((string)($_POST['status'] ?? 'draft'));
    if ($number < 1 || $start === '' || $end === '' || strtotime($start) === false || strtotime($end) === false || strtotime($start) >= strtotime($end)) {
        flash('error', 'Enter a valid period number and ensure the end time is after the start time.');
    } elseif (!in_array($status, ['draft','active','completed'], true)) {
        flash('error', 'Select a valid status.');
    } else {
        try {
            $before = 'number=' . $period['period_number'] . '; name=' . $period['period_name'] . '; start=' . $period['op_start'] . '; end=' . $period['op_end'] . '; status=' . $period['status'];
            db()->prepare('UPDATE operational_periods SET period_number=?, period_name=?, op_start=?, op_end=?, status=?, updated_by_user_id=? WHERE id=?')
                ->execute([$number, $name, $start, $end, $status, (int)(current_user()['id'] ?? 0), $periodId]);
            $after = 'number=' . $number . '; name=' . $name . '; start=' . $start . '; end=' . $end . '; status=' . $status;
            audit_log((int)(current_user()['id'] ?? 0), 'operational_period_updated', 'operational_period', $periodId, $before . ' | ' . $after);
            flash('success', 'Operational period updated.');
            header('Location: ' . app_url('/dashboard/index.php?op_id=' . $periodId)); exit;
        } catch (Throwable $e) {
            flash('error', 'Unable to update operational period. The period number may already be in use for this incident.');
        }
    }
    $stmt->execute([$periodId]); $period = $stmt->fetch();
}
render_header('Edit Operational Period');
?>
<div class="card">
  <h2>Edit Current Operational Period</h2>
  <p class="small muted">Update the initial default schedule once the Incident Commander establishes the operational period.</p>
  <form method="post">
    <?= csrf_field() ?><input type="hidden" name="id" value="<?= e((string)$periodId) ?>">
    <label>Period Number<br><input type="number" name="period_number" min="1" required value="<?= e((string)$period['period_number']) ?>"></label><br>
    <label>Period Name<br><input type="text" name="period_name" value="<?= e((string)$period['period_name']) ?>"></label><br>
    <label>Start<br><input class="govai-dt-display" type="text" name="op_start" required value="<?= e(substr((string)$period['op_start'],0,16)) ?>"></label><br>
    <label>End<br><input class="govai-dt-display" type="text" name="op_end" required value="<?= e(substr((string)$period['op_end'],0,16)) ?>"></label><br>
    <label>Status<br><select name="status"><?php foreach (['draft'=>'Draft','active'=>'Active','completed'=>'Completed'] as $v=>$l): ?><option value="<?= e($v) ?>" <?= $period['status']===$v?'selected':'' ?>><?= e($l) ?></option><?php endforeach; ?></select></label><br>
    <p style="display:flex;gap:12px;margin-top:16px"><button class="btn" type="submit">Save Operational Period</button><a class="btn btn-secondary" href="<?= e(app_url('/dashboard/index.php')) ?>">Cancel</a></p>
  </form>
</div>
<?php render_footer(); ?>
