<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/security.php';
require_csrf_for_post();

require_once dirname(__DIR__) . '/includes/layout.php';
require_login();

$incident = active_incident();
$incidentId = (int)($incident['id'] ?? 0);
require_operational_period_create_access($incidentId);

$periods = get_operational_periods($incidentId);
$previous = $periods ? end($periods) : null;
$previousIdForCloseout = (int)($previous['id'] ?? 0);
$unsubmittedForms = $previousIdForCloseout > 0 ? operational_period_unsubmitted_forms($incidentId, $previousIdForCloseout) : [];
$nextNumber = $previous ? ((int)$previous['period_number'] + 1) : 1;
$defaultStart = $previous ? substr((string)$previous['op_end'], 0, 16) : date('Y-m-d H:i');
$defaultEnd = date('Y-m-d H:i', strtotime($defaultStart . ' +12 hours'));
$copyable = current_operational_period_copyable_form_types();
$copyableLabels = ['ICS202' => 'ICS 202 - Incident Objectives','ICS203' => 'ICS 203 - Organization Assignment List','ICS204' => 'ICS 204 - Assignment List','ICS205' => 'ICS 205 - Incident Radio Communications Plan','ICS205A' => 'ICS 205A - Communications List','ICS206' => 'ICS 206 - Medical Plan','ICS207' => 'ICS 207 - Incident Organization Chart','ICS208' => 'ICS 208 - Safety Message / Plan','ICS209' => 'ICS 209 - Incident Status Summary','ICS215A' => 'ICS 215A - Incident Action Plan Safety Analysis'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $periodNumber = (int)($_POST['period_number'] ?? 0);
    $periodName = trim((string)($_POST['period_name'] ?? ''));
    $opStart = trim((string)($_POST['op_start'] ?? ''));
    $opEnd = trim((string)($_POST['op_end'] ?? ''));
    $previousId = (int)($_POST['previous_operational_period_id'] ?? 0);
    $copyTypes = isset($_POST['copy_forms']) && is_array($_POST['copy_forms']) ? $_POST['copy_forms'] : [];
    $closeoutAction = (string)($_POST['closeout_action'] ?? 'continue');
    $closeoutReason = trim((string)($_POST['closeout_reason'] ?? ''));
    $closeoutFormIds = isset($_POST['closeout_form_ids']) && is_array($_POST['closeout_form_ids']) ? $_POST['closeout_form_ids'] : [];

    if ($closeoutAction === 'notify' && $previousId > 0) {
        $count = create_form_closeout_notifications($incidentId, $previousId);
        flash('success', 'Created ' . $count . ' closeout notification(s) for unsubmitted forms.');
        header('Location: ' . app_url('/dashboard/index.php'));
        exit;
    }

    if ($periodNumber <= 0 || strtotime($opStart) === false || strtotime($opEnd) === false || strtotime($opStart) >= strtotime($opEnd)) {
        flash('error', 'Enter a valid period number and ensure the start time is before the end time.');
    } elseif ($closeoutAction === 'submit_selected' && !$closeoutFormIds) {
        flash('error', 'Select at least one current-period form to submit on behalf, or choose a different closeout action.');
    } elseif ($closeoutAction === 'submit_selected' && $closeoutReason === '') {
        flash('error', 'Enter a reason/note when submitting forms on behalf of another position.');
    } else {
        try {
            if ($closeoutAction === 'submit_selected' && $previousId > 0) {
                $submitted = closeout_submit_forms_on_behalf($incidentId, $previousId, $closeoutFormIds, (int)(current_user()['id'] ?? 0), $closeoutReason);
                flash('success', 'Submitted ' . $submitted . ' current-period form(s) for closeout.');
            } elseif ($unsubmittedForms && $closeoutAction === 'continue' && $closeoutReason === '') {
                throw new RuntimeException('There are unsubmitted current-period forms. Enter a closeout note to continue without submitting them, or use the notify/submit options.');
            }
            $newId = create_operational_period_from_previous($incidentId, $periodNumber, $periodName, $opStart, $opEnd, $previousId, $copyTypes, (int)(current_user()['id'] ?? 0));
            set_selected_operational_period_id($newId);
            flash('success', 'New operational period created. Selected forms were copied forward as draft/review-required records.');
            header('Location: ' . app_url('/dashboard/index.php?op_id=' . $newId));
            exit;
        } catch (Throwable $e) {
            flash('error', 'Unable to create operational period: ' . $e->getMessage());
        }
    }
}

render_header('New Operational Period');
?>
<div class="card">
  <h2>Create New Operational Period</h2>
  <p class="small muted">Admin/TSC, Incident Commander, and Planning Section Chief may create operational periods. Complete the current-period closeout check before copying forms forward.</p>
  <form method="post">
    <?= csrf_field() ?>
    <label>Period Number<br><input type="number" name="period_number" min="1" value="<?= e((string)$nextNumber) ?>" required></label><br>
    <label>Period Name<br><input type="text" name="period_name" value="<?= e('Operational Period ' . $nextNumber) ?>"></label><br>
    <label>Start<br><input class="govai-dt-display" type="text" name="op_start" value="<?= e($defaultStart) ?>" required></label><br>
    <label>End<br><input class="govai-dt-display" type="text" name="op_end" value="<?= e($defaultEnd) ?>" required></label><br>
    <input type="hidden" name="previous_operational_period_id" value="<?= e((string)(int)($previous['id'] ?? 0)) ?>">

    <h3>Current Operational Period Closeout</h3>
    <?php if (!$previous): ?>
      <p class="muted">No previous/current operational period is available for closeout.</p>
    <?php elseif (!$unsubmittedForms): ?>
      <p class="muted">No draft or rejected forms require closeout for <?= e(operational_period_label($previous)) ?>.</p>
      <input type="hidden" name="closeout_action" value="continue">
    <?php else: ?>
      <p class="small"><strong><?= count($unsubmittedForms) ?></strong> form(s) in <?= e(operational_period_label($previous)) ?> are still draft or rejected. Notify the responsible users, submit selected forms on behalf for closeout, or continue with a note.</p>
      <table style="border-collapse:collapse;width:100%;text-align:left;">
        <thead><tr><th style="width:32px;"></th><th>Form</th><th>Status</th><th>Prepared by</th><th>Updated</th></tr></thead>
        <tbody>
        <?php foreach ($unsubmittedForms as $f): ?>
          <tr>
            <td style="vertical-align:middle;"><input type="checkbox" name="closeout_form_ids[]" value="<?= e((string)$f['id']) ?>"></td>
            <td><?= e('ICS ' . (string)$f['form_number'] . ' - ' . (string)($f['form_name'] ?: $f['form_type'])) ?></td>
            <td><?= e(ucfirst((string)$f['status'])) ?></td>
            <td><?= e(trim((string)($f['prepared_by_name'] ?? '') . ' ' . (!empty($f['prepared_by_position_title']) ? '(' . (string)$f['prepared_by_position_title'] . ')' : ''))) ?></td>
            <td><?= e(format_datetime_display((string)($f['updated_at'] ?? ''))) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <p>
        <label><input type="radio" name="closeout_action" value="notify"> Notify responsible users and return to dashboard</label><br>
        <label><input type="radio" name="closeout_action" value="submit_selected"> Submit selected forms as final on behalf of the listed positions, then continue</label><br>
        <label><input type="radio" name="closeout_action" value="continue" checked> Continue without submitting, with note</label>
      </p>
      <label>Closeout note / reason<br><textarea name="closeout_reason" rows="3" style="width:100%;" placeholder="Required if submitting on behalf or continuing while forms remain unsubmitted."></textarea></label>
    <?php endif; ?>

    <h3>Copy forward from previous period</h3>
    <?php if (!$previous): ?>
      <p class="muted">No previous operational period is available.</p>
    <?php else: ?>
      <p class="small">Previous: <?= e(operational_period_label($previous)) ?></p>
      <table class="copy-forward-options" style="border-collapse:collapse;margin:8px 0 0 0;padding:0;width:100%;max-width:100%;table-layout:auto;text-align:left;">
        <tbody>
        <?php foreach ($copyable as $type): ?>
          <tr>
            <td style="width:28px;min-width:28px;max-width:28px;padding:3px 6px 3px 0;vertical-align:middle;text-align:left;border:0;">
              <input type="checkbox" name="copy_forms[]" value="<?= e($type) ?>" <?= in_array($type, ['ICS215','ICS215A','ICS220'], true) ? '' : 'checked' ?> style="margin:0;vertical-align:middle;">
            </td>
            <td style="padding:3px 0;vertical-align:middle;text-align:left;border:0;white-space:nowrap;">
              <?= e($copyableLabels[$type] ?? $type) ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>

    <div class="actions" style="margin-top:16px;">
      <button class="btn" type="submit">Create Operational Period</button>
      <a class="btn btn-secondary" href="<?= e(app_url('/dashboard/index.php')) ?>">Cancel</a>
    </div>
  </form>
</div>
<?php render_footer(); ?>
