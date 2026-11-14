<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/security.php';
require_csrf_for_post();

require_once dirname(__DIR__, 2) . '/includes/layout.php';
require_login();

$pdo = db();
$incident = active_incident();
$incidentId = (int)($_GET['incident_id'] ?? ($incident['id'] ?? 0));

if ($incidentId > 0) {
    require_active_operational_period($incidentId);
}
$user = current_user();
$canFinanceView = current_user_can_finance_view($incidentId);
$canFinanceManage = current_user_can_finance_manage($incidentId);
if (!$canFinanceView) {
    render_header('Finance/Admin Queue');
    echo '<div class="card"><h1>Finance/Admin Queue</h1><div class="alert alert-danger">You are not authorized to view the Finance/Admin Queue.</div></div>';
    render_footer();
    exit;
}

function finance_column_exists(PDO $pdo, string $table, string $column): bool {
    try {
        $stmt = $pdo->prepare("SHOW COLUMNS FROM `$table` LIKE ?");
        $stmt->execute([$column]);
        return (bool)$stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) { return false; }
}
function finance_status_label(?string $status, ?string $workflowStatus = null): string {
    $s = strtolower(trim((string)($status ?? '')));
    $w = strtolower(trim((string)($workflowStatus ?? '')));
    if ($s === '' && $w === 'finance_review') { $s = 'finance_review'; }
    $map = [
        'finance_review' => 'Pending Review',
        'pending_review' => 'Pending Review',
        'pending review' => 'Pending Review',
        'returned' => 'Returned',
        'return' => 'Returned',
        'on_hold' => 'On Hold',
        'on hold' => 'On Hold',
        'approved' => 'Approved',
        'finance_signed' => 'Approved',
        'rejected' => 'Rejected',
        'paid' => 'Paid',
        'not_required' => 'Not Required',
        'not required' => 'Not Required',
        'no' => 'Not Required',
    ];
    return $map[$s] ?? (($s === '') ? 'Pending Review' : ucwords(str_replace('_',' ', $s)));
}
function finance_form_record_status(string $workflowStatus): string {
    if (in_array($workflowStatus, ['draft','returned_for_revision'], true)) { return 'draft'; }
    if ($workflowStatus === 'section_chief_review') { return 'submitted'; }
    if (in_array($workflowStatus, ['cancelled','closed'], true)) { return 'void'; }
    if ($workflowStatus === 'unable_to_fill') { return 'rejected'; }
    // form_records.status is a coarse workflow/status field, commonly an ENUM.
    // Keep detailed 213RR states such as finance_review/finance_signed in
    // form_213rr_headers.workflow_status to avoid ENUM truncation errors.
    return 'approved';
}

function finance_internal_status(string $label): string {
    $map = [
        'Pending Review' => 'pending_review',
        'Returned' => 'returned',
        'On Hold' => 'on_hold',
        'Approved' => 'approved',
        'Rejected' => 'rejected',
        'Paid' => 'paid',
        'Not Required' => 'not_required',
    ];
    return $map[$label] ?? 'pending_review';
}

$hasFinanceStatus = finance_column_exists($pdo, 'form_213rr_headers', 'finance_status');
$hasFinanceRequired = finance_column_exists($pdo, 'form_213rr_headers', 'finance_required');
$hasClosureStatus = finance_column_exists($pdo, 'form_213rr_headers', 'closure_status');
$hasActualCost = finance_column_exists($pdo, 'form_213rr_headers', 'actual_cost');
$hasVendorName = finance_column_exists($pdo, 'form_213rr_headers', 'vendor_name');
$hasInvoiceNumber = finance_column_exists($pdo, 'form_213rr_headers', 'invoice_number');
$hasCostCode = finance_column_exists($pdo, 'form_213rr_headers', 'cost_code');
$hasResourceStatus = finance_column_exists($pdo, 'form_213rr_headers', 'resource_tracking_status');
$hasEstimatedCostHeader = finance_column_exists($pdo, 'form_213rr_headers', 'estimated_cost');
$hasEstimatedCostItems = finance_column_exists($pdo, 'form_213rr_items', 'estimated_cost');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$canFinanceManage) {
        flash('error', 'You are not authorized to update Finance/Admin records.');
        $returnTo = (string)($_POST['return_to'] ?? '');
    if ($returnTo === 'detail') {
        header('Location: ' . app_url('/forms/finance/index.php?action=detail&id=' . $id));
    } else {
        header('Location: ' . app_url('/forms/finance/index.php?status=' . urlencode((string)($_GET['status'] ?? 'all'))));
    }
        exit;
    }
    $id = (int)($_POST['id'] ?? 0);
    $action = (string)($_POST['finance_action'] ?? '');
    $comments = trim((string)($_POST['finance_comments'] ?? ''));
    $actualCost = trim((string)($_POST['actual_cost'] ?? ''));
    $vendorName = trim((string)($_POST['vendor_name'] ?? ''));
    $invoiceNumber = trim((string)($_POST['invoice_number'] ?? ''));
    $costCode = trim((string)($_POST['cost_code'] ?? ''));
    $allowed = ['pending_review','returned','on_hold','approved','rejected','paid','not_required'];
    if ($id > 0 && in_array($action, $allowed, true)) {
        $sets = [];
        $params = [];
        if ($hasFinanceStatus) { $sets[] = "finance_status=?"; $params[] = $action; }
        if ($hasFinanceRequired) { $sets[] = "finance_required=?"; $params[] = ($action === 'not_required' ? 'No' : 'Yes'); }
        if (finance_column_exists($pdo, 'form_213rr_headers', 'finance_comments')) { $sets[] = "finance_comments=?"; $params[] = $comments; }
        if ($hasActualCost && $actualCost !== '') { $sets[] = "actual_cost=?"; $params[] = $actualCost; }
        if ($hasVendorName) { $sets[] = "vendor_name=NULLIF(?, '')"; $params[] = $vendorName; }
        if ($hasInvoiceNumber) { $sets[] = "invoice_number=NULLIF(?, '')"; $params[] = $invoiceNumber; }
        if ($hasCostCode) { $sets[] = "cost_code=NULLIF(?, '')"; $params[] = $costCode; }
        if (in_array($action, ['approved','paid'], true)) {
            if (finance_column_exists($pdo, 'form_213rr_headers', 'finance_signed_at')) { $sets[] = "finance_signed_at=NOW()"; }
            if (finance_column_exists($pdo, 'form_213rr_headers', 'finance_signed_by_user_id')) { $sets[] = "finance_signed_by_user_id=?"; $params[] = (int)($user['id'] ?? 0) ?: null; }
            if (finance_column_exists($pdo, 'form_213rr_headers', 'finance_signed_by_name')) { $sets[] = "finance_signed_by_name=?"; $params[] = (string)($user['display_name'] ?? ''); }
            if (finance_column_exists($pdo, 'form_213rr_headers', 'finance_signed_by_position')) { $sets[] = "finance_signed_by_position=?"; $params[] = (string)($user['role_code'] ?? ''); }
        }
        if ($action === 'paid' && finance_column_exists($pdo, 'form_213rr_headers', 'finance_paid_at')) { $sets[] = "finance_paid_at=NOW()"; }
        if ($action === 'returned' && finance_column_exists($pdo, 'form_213rr_headers', 'finance_return_reason')) { $sets[] = "finance_return_reason=?"; $params[] = $comments; }

        if ($sets) {
            $params[] = $id;
            $pdo->prepare("UPDATE form_213rr_headers SET ".implode(', ', $sets)." WHERE id=?")->execute($params);
        }

        $workflow = ($action === 'approved' || $action === 'paid') ? 'finance_signed' : (($action === 'returned') ? 'returned_for_revision' : (($action === 'rejected') ? 'cancelled' : 'finance_review'));
        $recordStatus = finance_form_record_status($workflow);
        $pdo->prepare("UPDATE form_records fr INNER JOIN form_213rr_headers h ON h.form_record_id=fr.id SET fr.status=?, h.workflow_status=?, fr.updated_at=NOW() WHERE h.id=?")->execute([$recordStatus,$workflow,$id]);

        if (function_exists('ics213rr_log_status')) {
            @ics213rr_log_status($id, null, $workflow, 'Finance/Admin action: ' . finance_status_label($action) . ($comments !== '' ? ' - '.$comments : ''));
        }
        flash('success', 'Finance/Admin status updated.');
    }
    header('Location: ' . app_url('/forms/finance/index.php?status=' . urlencode((string)($_GET['status'] ?? 'all'))));
    exit;
}

$statusFilter = (string)($_GET['status'] ?? 'all');
$allowedStatuses = ['all','Pending Review','Other Statuses','Returned','On Hold','Approved','Rejected','Paid','Not Required'];
if (!in_array($statusFilter, $allowedStatuses, true)) { $statusFilter = 'Pending Review'; }

render_header('Finance/Admin Queue');

$missingColumns = [];
foreach (['workflow_status'] as $col) {
    if (!finance_column_exists($pdo, 'form_213rr_headers', $col)) { $missingColumns[] = "form_213rr_headers.$col"; }
}
if (!$hasEstimatedCostHeader && !$hasEstimatedCostItems) {
    // Not fatal; keep the queue usable.
}
if ($missingColumns) {
    echo '<div class="card"><h1>Finance/Admin Queue</h1><div class="alert alert-warning"><strong>Required 213RR database fields are missing.</strong><br>Missing: <code>' . e(implode(', ', $missingColumns)) . '</code></div></div>';
    render_footer();
    exit;
}

$estimatedCostSelect = $hasEstimatedCostHeader
    ? "h.estimated_cost AS estimated_cost"
    : ($hasEstimatedCostItems ? "(SELECT COALESCE(SUM(i.estimated_cost),0) FROM form_213rr_items i WHERE i.header_id = h.id) AS estimated_cost" : "NULL AS estimated_cost");
$sql = "
    SELECT h.*, $estimatedCostSelect, fr.id AS form_record_id, fr.incident_id, fr.operational_period_id,
           fr.prepared_by_name, fr.created_at AS record_created_at, fr.updated_at AS record_updated_at,
           (SELECT GROUP_CONCAT(DISTINCT TRIM(CONCAT(COALESCE(NULLIF(i.item_kind,''),'Unspecified kind'), CASE WHEN COALESCE(NULLIF(i.unit_of_issue,''),'') <> '' THEN CONCAT(' / ', i.unit_of_issue) ELSE '' END)) ORDER BY i.line_number SEPARATOR '; ') FROM form_213rr_items i WHERE i.header_id=h.id AND i.deleted_at IS NULL) AS kind_type_summary
    FROM form_213rr_headers h
    INNER JOIN form_records fr ON fr.id = h.form_record_id
    WHERE fr.incident_id = ? AND fr.form_type = 'ICS213RR' AND COALESCE(fr.is_deleted, 0) = 0
";
$params = [$incidentId];

if ($statusFilter !== 'all') {
    $pendingParts = ["h.workflow_status = 'finance_review'"];
    if ($hasFinanceStatus) { $pendingParts[] = "LOWER(REPLACE(COALESCE(h.finance_status,''),' ','_')) IN ('pending_review','finance_review','')"; }
    $pendingSql = "(" . implode(' OR ', $pendingParts) . ")";

    if ($statusFilter === 'Pending Review') {
        $sql .= " AND " . $pendingSql . " ";
    } elseif ($statusFilter === 'Other Statuses') {
        // Show all 213RRs that are not currently waiting for Finance/Admin approval.
        // This catches already-approved records plus returned/on-hold/rejected/paid/not-required
        // and any legacy workflow values that do not map cleanly to the Finance status list.
        $sql .= " AND NOT " . $pendingSql . " ";
    } else {
        $internal = finance_internal_status($statusFilter);
        if ($hasFinanceStatus) {
            $sql .= " AND LOWER(REPLACE(COALESCE(h.finance_status,''),' ','_')) = ? ";
            $params[] = $internal;
        } else {
            $sql .= " AND 1=0 ";
        }
    }
}
$sql .= " ORDER BY COALESCE(fr.updated_at, fr.created_at) DESC, h.id DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

if ((string)($_GET['action'] ?? '') === 'detail') {
    $detailId = (int)($_GET['id'] ?? 0);
    $detailSql = "
        SELECT h.*, $estimatedCostSelect, fr.id AS form_record_id, fr.incident_id, fr.operational_period_id,
               fr.prepared_by_name, fr.created_at AS record_created_at, fr.updated_at AS record_updated_at,
               (SELECT GROUP_CONCAT(DISTINCT TRIM(CONCAT(COALESCE(NULLIF(i.item_kind,''),'Unspecified kind'), CASE WHEN COALESCE(NULLIF(i.unit_of_issue,''),'') <> '' THEN CONCAT(' / ', i.unit_of_issue) ELSE '' END)) ORDER BY i.line_number SEPARATOR '; ') FROM form_213rr_items i WHERE i.header_id=h.id AND i.deleted_at IS NULL) AS kind_type_summary
        FROM form_213rr_headers h
        INNER JOIN form_records fr ON fr.id = h.form_record_id
        WHERE fr.incident_id = ? AND fr.form_type = 'ICS213RR' AND COALESCE(fr.is_deleted, 0) = 0 AND h.id = ?
        LIMIT 1";
    $detailParams = [$incidentId, $detailId];
    $dst = $pdo->prepare($detailSql);
    $dst->execute($detailParams);
    $row = $dst->fetch(PDO::FETCH_ASSOC);
    echo '<div class="card finance-detail-card">';
    if (!$row) {
        echo '<h1>Finance/Admin Detail</h1><p>213RR record not found.</p><p><a class="btn" href="' . e(app_url('/forms/finance/index.php')) . '">Back to Finance/Admin Queue</a></p>';
    } else {
        $label = finance_status_label($row['finance_status'] ?? null, $row['workflow_status'] ?? null);
        ?>
        <h1>Finance/Admin Detail - <?= e($row['request_number'] ?? ('213RR #' . $row['id'])) ?></h1>
        <p>
          <a class="btn" href="<?= e(app_url('/forms/finance/index.php')) ?>">Back to Finance/Admin Queue</a>
          <a class="btn" href="<?= e(app_url('/forms/ics213rr_v2/index.php?action=view&id=' . (int)$row['id'])) ?>">Open Source 213RR</a>
        </p>
        <div class="info-panel">
          <h3>Request Summary</h3>
          <p><strong>Kind / Type:</strong> <?= e((string)($row['kind_type_summary'] ?? '')) ?></p>
          <p><strong>Estimated cost:</strong> <?= e((string)($row['estimated_cost'] ?? '')) ?> &nbsp; <strong>Finance status:</strong> <?= e($label) ?></p>
          <p><strong>Resource status:</strong> <?= e((string)($row['resource_tracking_status'] ?? '')) ?> &nbsp; <strong>Workflow:</strong> <?= e((string)($row['workflow_status'] ?? '')) ?></p>
        </div>
        <?php if ($canFinanceManage): ?>
        <form method="post" class="form-grid">
    <?= csrf_field() ?>
          <input type="hidden" name="id" value="<?= e((string)$row['id']) ?>">
          <input type="hidden" name="return_to" value="detail">
          <label>Actual Amount <input name="actual_cost" value="<?= e((string)($row['actual_cost'] ?? '')) ?>"></label>
          <label>Vendor <input name="vendor_name" value="<?= e((string)($row['vendor_name'] ?? '')) ?>"></label>
          <label>Invoice # <input name="invoice_number" value="<?= e((string)($row['invoice_number'] ?? '')) ?>"></label>
          <label>Cost Code <input name="cost_code" value="<?= e((string)($row['cost_code'] ?? '')) ?>"></label>
          <label>Finance Action
            <select name="finance_action">
              <option value="approved">Approve</option>
              <option value="returned">Return for revision</option>
              <option value="on_hold">Place on hold</option>
              <option value="rejected">Reject / cancel</option>
              <option value="paid">Mark paid</option>
              <option value="not_required">Finance not required</option>
            </select>
          </label>
          <label>Finance notes / return reason <textarea name="finance_comments" rows="4"><?= e((string)($row['finance_comments'] ?? '')) ?></textarea></label>
          <button class="btn btn-primary" type="submit">Save Finance/Admin Update</button>
        </form>
        <?php else: ?>
          <p class="small">You have view-only access to Finance/Admin. No update actions are available.</p>
        <?php endif; ?>
        <div class="info-panel small">
          <h3>Finance/Admin field guide</h3>
          <p><strong>Approve</strong> records Finance/Admin sign-off and keeps the 213RR as approved. <strong>Mark paid</strong> records Finance/Admin sign-off and marks the item as paid. <strong>Return</strong> sends the request back for revision. <strong>On hold</strong> keeps the item in Finance review without approval. <strong>Reject</strong> cancels the request. <strong>Not required</strong> records that no Finance/Admin action is required.</p>
          <p><strong>Resource status</strong> describes RESL/resource tracking progress. <strong>Workflow</strong> is the 213RR routing stage. <strong>Finance status</strong> is the Finance/Admin decision state.</p>
        </div>
        <?php
    }
    echo '</div>';
    render_footer();
    exit;
}

?>
<div class="card finance-queue-card">
<h1>Finance/Admin Queue</h1>
<p class="small">Finance/Admin approvals are completed here. By default this page shows all 213RRs for the active incident, including items already approved, paid, returned, rejected, not required, or otherwise no longer waiting for approval.</p>
<form method="get" class="mb-3">
    <input type="hidden" name="incident_id" value="<?= e((string)$incidentId) ?>">
    <label for="status">Status</label>
    <select name="status" id="status" onchange="this.form.submit()">
        <?php foreach ($allowedStatuses as $status): ?>
            <option value="<?= e($status) ?>" <?= $status === $statusFilter ? 'selected' : '' ?>><?= e($status === 'all' ? 'All' : $status) ?></option>
        <?php endforeach; ?>
    </select>
</form>
<div class="finance-table-wrap">
<table class="table table-striped table-sm finance-queue-table cb-cards">
    <thead><tr><th>213RR</th><th>Kind / Type</th><th>Finance Status</th><th>Resource Status</th><th>Workflow</th><th>Estimated</th><th>Actual</th><th>Vendor</th><th>Invoice</th><th>Detail</th></tr></thead>
    <tbody>
        <?php if (!$rows): ?><tr><td colspan="10">No 213RR records found for this Finance/Admin queue filter.</td></tr><?php endif; ?>
        <?php foreach ($rows as $row): $label = finance_status_label($row['finance_status'] ?? null, $row['workflow_status'] ?? null); ?>
            <tr>
                <td><a href="<?= e(app_url('/forms/ics213rr_v2/index.php?action=view&id=' . (string)$row['id'])) ?>"><?= e($row['request_number'] ?? ('213RR #' . $row['id'])) ?></a></td>
                <td><?= e((string)($row['kind_type_summary'] ?? '')) ?></td>
                <td><?= e($label) ?></td>
                <td><?= e($row['resource_tracking_status'] ?? '') ?></td>
                <td><?= e($row['workflow_status'] ?? '') ?></td>
                <td><?= e((string)($row['estimated_cost'] ?? '')) ?></td>
                <td><?= e((string)($row['actual_cost'] ?? '')) ?></td>
                <td><?= e($row['vendor_name'] ?? '') ?></td>
                <td><?= e($row['invoice_number'] ?? '') ?></td>
                <td><a class="btn btn-sm" href="<?= e(app_url('/forms/finance/index.php?action=detail&id=' . (int)$row['id'])) ?>">Finance detail</a></td>
            </tr>
        <?php endforeach; ?>
    </tbody>
</table>
</div>
</div>
<?php render_footer(); ?>
