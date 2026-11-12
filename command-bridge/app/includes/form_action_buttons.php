<?php
declare(strict_types=1);
if (!function_exists('ics_action_h')) {
    function ics_action_h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
}
if (!function_exists('ics_form_status_label')) {
    function ics_form_status_label($status): string {
        $s = strtolower(trim((string)($status ?: 'draft')));
        $map = ['submitted'=>'Complete','approved'=>'Complete','final'=>'Complete','complete'=>'Complete','draft'=>'Draft'];
        return $map[$s] ?? ucwords(str_replace('_',' ', $s));
    }
}
if (!function_exists('ics_action_button_mapping')) {
    function ics_action_button_mapping(string $formCode): array {
        $c = strtoupper($formCode);
        if (in_array($c, ['ICS201','ICS205A','ICS211','ICS230'], true)) return ['field'=>'form_action','save'=>'save','submit'=>'submit'];
        if ($c === 'ICS202') return ['field'=>'action','save'=>'save_ics202','submit'=>'approve_ics202'];
        if ($c === 'ICS203') return ['field'=>'action','save'=>'save','submit'=>'submit_ics203'];
        if ($c === 'ICS204') return ['field'=>'action','save'=>'save','submit'=>'submit_ics204'];
        return ['field'=>'action','save'=>'save','submit'=>'submit'];
    }
}

if (!function_exists('ics_form_pdf_urls')) {
    /**
     * Standard PDF links for an ICS form.
     *
     * Every form keeps its PDF generator at /forms/<form code>/pdf.php. The same endpoint serves both buttons:
     *   - Download PDF  -> pdf.php?incident_id=..&op_id=..             (sent as an attachment)
     *   - Preview PDF   -> pdf.php?incident_id=..&op_id=..&preview=1   (shown inline in the browser tab)
     * If a form has no pdf.php yet, both links fall back to the "not implemented" page.
     *
     * @return array{preview: string, download: string, available: bool}
     */
    function ics_form_pdf_urls(string $formCode, array $options = []): array
    {
        $code = strtoupper(trim($formCode));
        $dir = strtolower(preg_replace('/[^A-Za-z0-9_]/', '', $code));
        $pdfFile = dirname(__DIR__) . '/forms/' . $dir . '/pdf.php';
        $available = $dir !== '' && is_file($pdfFile);

        $incidentId = $options['incident_id'] ?? ($_GET['incident_id'] ?? '');
        $opId = $options['op_id'] ?? ($_GET['op_id'] ?? ($_GET['operational_period_id'] ?? ''));
        $query = [];
        if ((string)$incidentId !== '') $query['incident_id'] = (string)$incidentId;
        if ((string)$opId !== '') $query['op_id'] = (string)$opId;
        // Forms that hold several records per period (e.g. ICS 213 messages) can pass the one to print.
        foreach ((array)($options['extra_query'] ?? []) as $key => $value) {
            if ($value !== null && $value !== '' && $value !== 0 && $value !== '0') $query[(string)$key] = (string)$value;
        }

        if ($available) {
            $base = app_url('/forms/' . $dir . '/pdf.php');
            return [
                'preview' => $base . '?' . http_build_query($query + ['preview' => '1']),
                'download' => $base . ($query ? '?' . http_build_query($query) : ''),
                'available' => true,
            ];
        }

        $ni = app_url('/not_implemented.php') . '?form=' . rawurlencode($code);
        return [
            'preview' => $ni . '&feature=' . rawurlencode('PDF Preview'),
            'download' => $ni . '&feature=' . rawurlencode('Download PDF'),
            'available' => false,
        ];
    }
}

if (!function_exists('render_ics_form_actions')) {
function render_ics_form_actions(array $options = []): void {
    $formCode = (string)($options['form_code'] ?? '');
    $formTitle = (string)($options['form_title'] ?? $formCode);
    $permissions = $options['permissions'] ?? [];
    $isFinal = !empty($options['is_final']) || in_array(strtolower((string)($options['status'] ?? '')), ['submitted','approved','complete'], true);
    $canView = !empty($permissions['can_view']) || !empty($permissions['view']) || !empty($options['can_view']);
    $canCreate = !empty($permissions['can_create']) || !empty($permissions['create']) || !empty($options['can_create']);
    $canEdit = !empty($permissions['can_edit']) || !empty($permissions['edit']) || !empty($options['can_edit']);
    $canSubmit = (!array_key_exists('show_submit', $options) || !empty($options['show_submit'])) && (!empty($permissions['can_submit_final']) || !empty($permissions['submit_final']) || !empty($permissions['can_submit']) || !empty($options['can_submit_final']));
    $map = ics_action_button_mapping($formCode);
    $field = $options['action_field'] ?? $map['field'];
    $saveValue = $options['save_value'] ?? $map['save'];
    $saveLabel = $options['save_label'] ?? 'Save';
    $submitValue = $options['submit_value'] ?? $map['submit'];
    $showPreview = !array_key_exists('show_preview', $options) || !empty($options['show_preview']);
    $showDownload = !array_key_exists('show_download', $options) || !empty($options['show_download']);
    $blankUrl = $options['blank_download_url'] ?? null;

    // Preview / Download links are derived from the form code, so every form gets the same pair of buttons.
    // A form can still override either link with 'preview_url' / 'download_url'.
    $pdf = ics_form_pdf_urls($formCode, $options);
    $previewUrl = $options['preview_url'] ?? $pdf['preview'];
    $downloadUrl = $options['download_url'] ?? $pdf['download'];
    ?>
    <div class="card mt-4 mb-4 form-actions-card no-print">
      <div class="card-header"><strong>Form Actions</strong></div>
      <div class="card-body"><div class="d-flex flex-wrap gap-2 align-items-center">
        <?php if (!$isFinal && ($canCreate || $canEdit)): ?><button type="submit" name="<?= ics_action_h($field) ?>" value="<?= ics_action_h($saveValue) ?>" class="btn btn-primary"><?= ics_action_h($saveLabel) ?></button><?php endif; ?>
        <?php if (!$isFinal && $canSubmit): ?><?php require_once __DIR__ . '/signoff.php'; $staged = signoff_form_supported($formCode); ?><button type="submit" name="<?= ics_action_h($field) ?>" value="<?= ics_action_h($submitValue) ?>" class="btn btn-success" onclick="return confirm('<?= $staged ? 'Sign and submit ' : 'Approve and submit ' ?><?= ics_action_h($formTitle) ?>?<?= $staged ? ' It will be locked until an approver returns it for changes.' : '' ?>');"><?= $staged ? 'Sign and Submit ' : 'Approve / Submit ' ?><?= ics_action_h($formCode) ?></button><?php endif; ?>
        <?php if ($canView && $showPreview): ?><a class="btn btn-outline-secondary" target="_blank" rel="noopener" href="<?= ics_action_h($previewUrl) ?>">Preview PDF</a><?php endif; ?>
        <?php if ($canView && $showDownload): ?><a class="btn btn-outline-secondary" target="_blank" rel="noopener" href="<?= ics_action_h($downloadUrl) ?>">Download PDF</a><?php endif; ?>
        <?php if ($canView && $blankUrl): ?><a class="btn btn-outline-secondary" target="_blank" rel="noopener" href="<?= ics_action_h($blankUrl) ?>">Download Blank Form</a><?php endif; ?>
      </div><?php if ($isFinal): ?><div class="text-muted small mt-3">This form is complete/submitted. Editing may be restricted by permissions.</div><?php endif; ?></div>
    </div>
    <?php
    // Staged sign-off (creator signs, named approver approves). Not shown for forms with their own workflow.
    try {
        require_once __DIR__ . '/signoff.php';
        $soIncident = (int)($options['incident_id'] ?? 0);
        $soOp = (int)($options['op_id'] ?? 0);
        $soRecord = (int)($options['form_record_id'] ?? ($GLOBALS['formRecordId'] ?? 0));
        if ($soIncident <= 0) { $soIncident = (int)($GLOBALS['incidentId'] ?? 0); }
        if ($soOp <= 0) { $soOp = (int)($GLOBALS['opId'] ?? ($GLOBALS['operationalPeriodId'] ?? 0)); }
        if ($soRecord <= 0 && $soIncident > 0 && signoff_form_supported($formCode)) {
            $soRow = get_form_record($soIncident, signoff_normalize($formCode), $soOp ?: null);
            $soRecord = (int)($soRow['id'] ?? 0);
        }
        if ($soRecord > 0) { signoff_render_panel($formCode, $soIncident, $soOp, $soRecord); }
    } catch (Throwable $e) { security_log($e, 'signoff_panel'); }
}
}
