<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/includes/layout.php';
require_once dirname(__DIR__) . '/includes/meetings.php';
require_login();

$incident = active_incident();
$incidentId = (int)($incident['id'] ?? 0);
$currentOperationalPeriod = $incidentId > 0 ? current_operational_period($incidentId) : null;
$currentOperationalPeriodId = (int)($currentOperationalPeriod['id'] ?? 0);


$meetings = [];
$nextMeetingId = 0;
$nowTs = time();
$currentTimeDisplay = date('M j, Y H:i');

if ($incidentId > 0 && $currentOperationalPeriodId > 0) {
    $stmt = db()->prepare("
        SELECT m.id,m.title,m.scheduled_at,m.location,m.status,m.chair_name,m.chair_position_title,
               COALESCE(mt.name, m.title) AS meeting_type_name
        FROM operational_period_meetings m
        LEFT JOIN meeting_types mt ON mt.id = m.meeting_type_id
        WHERE m.incident_id = ? AND m.operational_period_id = ? AND m.deleted_at IS NULL
          AND m.scheduled_at >= NOW() AND LOWER(m.status) <> 'cancelled'
        ORDER BY m.scheduled_at ASC, m.display_order ASC, m.id ASC
    ");
    $stmt->execute([$incidentId, $currentOperationalPeriodId]);
    $meetings = $stmt->fetchAll();

    foreach ($meetings as $meeting) {
        $scheduledTs = strtotime((string)($meeting['scheduled_at'] ?? ''));
        if ($scheduledTs !== false && $scheduledTs >= $nowTs) {
            $nextMeetingId = (int)($meeting['id'] ?? 0);
            break;
        }
    }
}

function dashboard_get_notifications(int $incidentId): array {
    if ($incidentId <= 0) { return []; }
    try {
        $roleCodes = get_user_role_codes($incidentId, (int)(current_user()['id'] ?? 0));
        $roleCodes[] = 'ALL_AUTHENTICATED';
        $roleCodes = array_values(array_unique(array_filter(array_map('strtoupper', $roleCodes))));
        $params = [$incidentId, (int)(current_user()['id'] ?? 0)];
        $roleSql = '';
        if ($roleCodes) {
            $roleSql = ' OR n.role_code IN (' . implode(',', array_fill(0, count($roleCodes), '?')) . ')';
            $params = array_merge($params, $roleCodes);
        }
        $sql = "
            SELECT n.id,n.title,n.body,n.link_url,n.created_at,
                   COALESCE(NULLIF(n.urgency,''), rr.priority_level, 'routine') AS urgency
            FROM notifications n
            LEFT JOIN form_213rr_headers rr
              ON n.related_table='form_213rr_headers' AND rr.id=n.related_id
            WHERE n.incident_id=? AND n.is_read=0 AND (n.user_id=? $roleSql)
              AND NOT (n.notification_type='account_locked' AND NOT EXISTS (
                    SELECT 1 FROM users lu WHERE lu.id=n.related_id AND lu.locked_until IS NOT NULL AND lu.locked_until > UTC_TIMESTAMP()))
              AND NOT (n.notification_type='meeting_status_reminder' AND NOT EXISTS (
                    SELECT 1 FROM operational_period_meetings om WHERE om.id=n.related_id AND om.deleted_at IS NULL
                      AND LOWER(om.status) IN ('scheduled','tentative')))
            ORDER BY
              CASE LOWER(COALESCE(NULLIF(n.urgency,''), rr.priority_level, 'routine'))
                WHEN 'urgent' THEN 1
                WHEN 'high' THEN 1
                WHEN 'routine' THEN 2
                WHEN 'normal' THEN 2
                WHEN 'low' THEN 3
                ELSE 2
              END ASC,
              n.created_at DESC,
              n.id DESC
            LIMIT 25";
        $stmt = db()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    } catch (Throwable $e) { return []; }
}

function dashboard_get_current_op_form_statuses(int $incidentId, int $operationalPeriodId): array {
    if ($incidentId <= 0 || $operationalPeriodId <= 0) { return []; }

    $baseForms = [
        'ICS202' => ['number' => '202', 'name' => 'Incident Objectives', 'sort' => 202.0],
        'ICS203' => ['number' => '203', 'name' => 'Organization Assignment List', 'sort' => 203.0],
        'ICS204' => ['number' => '204', 'name' => 'Assignment List', 'sort' => 204.0],
        'ICS205' => ['number' => '205', 'name' => 'Incident Radio Communications Plan', 'sort' => 205.0],
        'ICS205A' => ['number' => '205A', 'name' => 'Communications List', 'sort' => 205.1],
        'ICS206' => ['number' => '206', 'name' => 'Medical Plan', 'sort' => 206.0],
        'ICS207' => ['number' => '207', 'name' => 'Incident Organization Chart', 'sort' => 207.0],
        'ICS208' => ['number' => '208', 'name' => 'Safety Message / Plan', 'sort' => 208.0],
    ];

    $rows = [];
    try {
        $stmt = db()->prepare("
            SELECT fr.id, fr.form_type, fr.form_number, fr.status, fr.review_required,
                   fr.copied_from_form_record_id, fr.approved_at, fr.submitted_at, fr.updated_at, fr.created_at,
                   COALESCE(fd.form_name, f.form_title, fr.form_type) AS form_name
            FROM form_records fr
            LEFT JOIN form_definitions fd ON UPPER(fd.form_type)=UPPER(fr.form_type)
            LEFT JOIN forms f ON UPPER(f.form_code)=UPPER(fr.form_type)
            WHERE fr.incident_id=?
              AND fr.operational_period_id=?
              AND fr.is_deleted=0
              AND (UPPER(REPLACE(fr.form_type, ' ', ''))<>'ICS214' OR fr.prepared_by_user_id=?)
            ORDER BY fr.form_type ASC, fr.updated_at DESC, fr.id DESC
        ");
        // ICS 214 logs are personal: show only your own here (everyone's are listed on forms/ics214/logs.php).
        $stmt->execute([$incidentId, $operationalPeriodId, (int)(current_user()['id'] ?? 0)]);
        $records = $stmt->fetchAll();

        $hasBaseRecord = [];
        foreach ($records as $r) {
            $type = strtoupper((string)$r['form_type']);
            $number = (string)($r['form_number'] ?: preg_replace('/^ICS/i', '', $type));
            $isBase = array_key_exists($type, $baseForms);
            $rows[] = [
                'id' => (int)$r['id'],
                'form_type' => $type,
                'form_number' => $isBase ? $baseForms[$type]['number'] : $number,
                'form_name' => (string)($r['form_name'] ?: ($baseForms[$type]['name'] ?? $type)),
                'status' => (string)$r['status'],
                'review_required' => (int)($r['review_required'] ?? 0),
                'copied_from_form_record_id' => (int)($r['copied_from_form_record_id'] ?? 0),
                'approved_at' => (string)($r['approved_at'] ?? ''),
                'submitted_at' => (string)($r['submitted_at'] ?? ''),
                'updated_at' => (string)($r['updated_at'] ?? ''),
                'created_at' => (string)($r['created_at'] ?? ''),
                'sort' => $isBase ? $baseForms[$type]['sort'] : 900.0,
                'is_base' => $isBase,
                'has_record' => true,
            ];
            if ($isBase) { $hasBaseRecord[$type] = true; }
        }

        foreach ($baseForms as $type => $meta) {
            if (empty($hasBaseRecord[$type])) {
                $rows[] = [
                    'id' => 0,
                    'form_type' => $type,
                    'form_number' => $meta['number'],
                    'form_name' => $meta['name'],
                    'status' => 'not_started',
                    'review_required' => 0,
                    'copied_from_form_record_id' => 0,
                    'approved_at' => '',
                    'submitted_at' => '',
                    'updated_at' => '',
                    'created_at' => '',
                    'sort' => $meta['sort'],
                    'is_base' => true,
                    'has_record' => false,
                ];
            }
        }

        usort($rows, function(array $a, array $b): int {
            $cmp = ($a['sort'] <=> $b['sort']);
            if ($cmp !== 0) { return $cmp; }
            return strcmp((string)$a['form_type'], (string)$b['form_type']) ?: ((int)$b['id'] <=> (int)$a['id']);
        });

        return $rows;
    } catch (Throwable $e) {
        error_log('Dashboard current OP form status query failed: ' . $e->getMessage());
        return [];
    }
}

function dashboard_form_status_label(string $status): string {
    $status = strtolower($status);
    if ($status === 'not_started') { return 'Not started'; }
    return ucfirst($status);
}

function dashboard_form_link(string $formType, int $recordId): string {
    if (strtoupper(str_replace(' ', '', $formType)) === 'ICS214') {
        return app_url('/forms/ics214/index.php') . ($recordId > 0 ? '?record_id=' . $recordId : '');
    }
    $slug = strtolower(str_replace('ICS', 'ics', $formType));
    if ($slug === 'ics205a') { $slug = 'ics205a'; }
    return app_url('/forms/' . $slug . '/index.php');
}


function dashboard_user_has_any_role(int $incidentId, array $roles): bool {
    $roleCodes = get_user_role_codes($incidentId, (int)(current_user()['id'] ?? 0));
    $roleCodes = array_values(array_unique(array_filter(array_map('strtoupper', $roleCodes))));
    foreach ($roles as $role) {
        if (in_array(strtoupper($role), $roleCodes, true)) { return true; }
    }
    return !empty(current_user()['is_tsc_admin']);
}

function dashboard_get_213rr_action_items(int $incidentId): array {
    if ($incidentId <= 0) { return []; }
    $items = [];
    try {
        if (dashboard_user_has_any_role($incidentId, ['LSC','SPUL','PROC'])) {
            $stmt = db()->prepare("
                SELECT h.id, h.request_number, h.priority_level, h.needed_by_datetime,
                       h.workflow_status, fr.updated_at AS action_updated_at,
                       i.item_kind, i.unit_of_issue, i.item_description
                FROM form_213rr_headers h
                INNER JOIN form_records fr ON fr.id=h.form_record_id
                LEFT JOIN form_213rr_items i ON i.header_id=h.id AND i.deleted_at IS NULL
                WHERE fr.incident_id=? AND fr.form_type='ICS213RR' AND fr.is_deleted=0
                  AND h.workflow_status IN ('logistics_review','approved_for_logistics','awaiting_logistics')
                ORDER BY
                  CASE LOWER(COALESCE(h.priority_level,'routine'))
                    WHEN 'urgent' THEN 1
                    WHEN 'high' THEN 1
                    WHEN 'routine' THEN 2
                    WHEN 'normal' THEN 2
                    WHEN 'low' THEN 3
                    ELSE 2
                  END ASC,
                  h.needed_by_datetime IS NULL ASC,
                  h.needed_by_datetime ASC,
                  fr.updated_at ASC
            ");
            $stmt->execute([$incidentId]);
            foreach ($stmt->fetchAll() as $r) {
                $summary = trim((string)($r['item_kind'] ?? '') . ' / ' . (string)($r['unit_of_issue'] ?? ''), ' /');
                if ($summary === '') { $summary = (string)($r['item_description'] ?? 'Resource request'); }
                $items[] = [
                    'title' => '213RR awaiting Logistics review',
                    'body' => (string)$r['request_number'] . ' - ' . $summary,
                    'urgency' => (string)($r['priority_level'] ?? 'routine'),
                    'created_at' => (string)($r['updated_at'] ?? ''),
                    'link_url' => app_url('/forms/ics213rr_v2/index.php?action=view&id=' . (int)$r['id']),
                ];
            }
        }
    } catch (Throwable $e) {
        error_log('Dashboard 213RR action item query failed: ' . $e->getMessage());
    }
    return $items;
}

function dashboard_render_notification_panels(int $incidentId): void {
    cb_meeting_reminders_run($incidentId);
    $notifications = dashboard_get_notifications($incidentId);
    $actionItems = dashboard_get_213rr_action_items($incidentId);
    $currentOp = current_operational_period($incidentId);
    $currentOpForms = $currentOp ? dashboard_get_current_op_form_statuses($incidentId, (int)$currentOp['id']) : [];
    $closeoutForms = $currentOp ? operational_period_unsubmitted_forms($incidentId, (int)$currentOp['id']) : [];
    $reviewRequiredForms = $currentOp ? operational_period_copied_forms_requiring_review($incidentId, (int)$currentOp['id']) : [];
    $myReviewRequiredForms = $currentOp ? copied_forms_assigned_to_current_user($incidentId, (int)$currentOp['id']) : [];
    $positionAssignments = $currentOp ? operational_period_position_assignments($incidentId, (int)$currentOp['id']) : [];
    ?>
    <div id="dashboard-notification-content">
      <?php if (!$notifications && !$actionItems): ?>
        <p class="muted">No unread notifications or role action items.</p>
      <?php else: ?>
        <table><thead><tr><th>Notification / Action Item</th><th>Urgency</th><th>Created/Updated</th><th>Action</th></tr></thead><tbody>
        <?php foreach ($actionItems as $n): ?>
          <tr>
            <td><strong><?= e((string)$n['title']) ?></strong><br><span class="small"><?= e((string)$n['body']) ?></span></td>
            <td><span class="priority-<?= e(strtolower((string)$n['urgency'])) ?>"><?= e(ucfirst((string)$n['urgency'])) ?></span></td>
            <td><?= e((string)$n['created_at']) ?></td>
            <td><?php if (!empty($n['link_url'])): ?><a class="btn btn-small" href="<?= e((string)$n['link_url']) ?>">Open</a><?php endif; ?></td>
          </tr>
        <?php endforeach; ?>
        <?php foreach ($notifications as $n): ?>
          <tr>
            <td><strong><?= e((string)$n['title']) ?></strong><br><span class="small"><?= e((string)$n['body']) ?></span></td>
            <td><span class="priority-<?= e(strtolower((string)$n['urgency'])) ?>"><?= e(ucfirst((string)$n['urgency'])) ?></span></td>
            <td><?= e((string)$n['created_at']) ?></td>
            <td><?php if (!empty($n['link_url'])): ?><a class="btn btn-small" href="<?= e((string)$n['link_url']) ?>">Open</a><?php endif; ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody></table>
      <?php endif; ?>

      <?php if (!$currentOp): ?>
        <div class="notice warning">
          <h3>No Active Operational Period</h3>
          <p>The previous Operational Period has ended and no new Operational Period has been created.</p>
          <p>ICS forms are tied to an active Operational Period. Create or start a new Operational Period before creating or reviewing current forms.</p>
          <?php if (current_user_can_create_operational_period($incidentId)): ?>
            <p><a class="btn btn-primary" href="<?= e(app_url('/operational_periods/new.php')) ?>">Create New Operational Period</a></p>
          <?php endif; ?>
        </div>
      <?php else: ?>

      <h3>Your Copied Forms to Review</h3>
      <?php if (!$myReviewRequiredForms): ?>
        <p class="muted">No copied-forward forms are specifically assigned to your current position/user account.</p>
      <?php else: ?>
        <p class="small muted">These copied-forward forms are assigned to your current operational-period position or directly to you.</p>
        <table><thead><tr><th>Form</th><th>Responsible position</th><th>Status</th><th>Action</th></tr></thead><tbody>
        <?php foreach ($myReviewRequiredForms as $f): ?>
          <tr>
            <td><?= e('ICS ' . (string)$f['form_number'] . ' - ' . (string)($f['form_name'] ?: $f['form_type'])) ?></td>
            <td><?= e((string)($f['responsible_position_title'] ?? $f['responsible_position_code'] ?? 'Not assigned')) ?></td>
            <td><span class="priority-high">Review required</span><br><span class="small"><?= e(ucfirst((string)$f['status'])) ?></span></td>
            <td><a class="btn btn-small" href="<?= e(dashboard_form_link((string)$f['form_type'], (int)$f['id'])) ?>">Open / Review</a></td>
          </tr>
        <?php endforeach; ?>
        </tbody></table>
      <?php endif; ?>

      <h3>Operational Period Staffing / Position Assignments</h3>
      <?php if (!$positionAssignments): ?>
        <p class="muted">No operational-period-specific position assignments have been set yet.</p>
      <?php else: ?>
        <table><thead><tr><th>Position</th><th>Assigned person</th><th>Status</th></tr></thead><tbody>
        <?php foreach ($positionAssignments as $a): ?>
          <tr>
            <td><?= e((string)$a['position_title']) ?></td>
            <td><?= e((string)($a['assigned_name'] ?: $a['user_real_name'] ?: $a['username'] ?: 'Vacant')) ?></td>
            <td><?= e(ucfirst((string)$a['assignment_status'])) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody></table>
      <?php endif; ?>

      <h3>Copied Forms Requiring Review</h3>
      <?php if (!$reviewRequiredForms): ?>
        <p class="muted">No copied-forward forms currently require review.</p>
      <?php else: ?>
        <p class="small muted">These forms were copied from the previous operational period. They must be reviewed, updated if needed, and submitted/approved for the current operational period.</p>
        <table><thead><tr><th>Form</th><th>Status</th><th>Copied from</th><th>Current preparer</th><th>Action</th></tr></thead><tbody>
        <?php foreach ($reviewRequiredForms as $f): ?>
          <?php
            $fromPeriod = '';
            if (!empty($f['copied_from_period_number'])) {
                $fromPeriod = 'OP ' . (string)$f['copied_from_period_number'];
                if (!empty($f['copied_from_period_name'])) { $fromPeriod .= ' - ' . (string)$f['copied_from_period_name']; }
            }
          ?>
          <tr>
            <td><?= e('ICS ' . (string)$f['form_number'] . ' - ' . (string)($f['form_name'] ?: $f['form_type'])) ?></td>
            <td><span class="priority-high">Review required</span><br><span class="small"><?= e(ucfirst((string)$f['status'])) ?></span></td>
            <td><?= e($fromPeriod ?: 'Previous operational period') ?></td>
            <td><?= e(trim((string)($f['prepared_by_name'] ?? '') . ' ' . (!empty($f['prepared_by_position_title']) ? '(' . (string)$f['prepared_by_position_title'] . ')' : '')) ?: 'Not assigned') ?></td>
            <td><a class="btn btn-small" href="<?= e(dashboard_form_link((string)$f['form_type'], (int)$f['id'])) ?>">Open / Review</a></td>
          </tr>
        <?php endforeach; ?>
        </tbody></table>
      <?php endif; ?>

      <h3>Forms to Submit Before Operational Period Ends</h3>
      <?php if (!$closeoutForms): ?>
        <p class="muted">No current-period draft or rejected forms requiring submission.</p>
      <?php else: ?>
        <p class="small muted">These forms are not submitted as final for the current operational period<?php if ($currentOp): ?> ending <?= e(format_datetime_display((string)($currentOp['op_end'] ?? ''))) ?><?php endif; ?>.</p>
        <table><thead><tr><th>Form</th><th>Status</th><th>Prepared by</th><th>Action</th></tr></thead><tbody>
        <?php foreach ($closeoutForms as $f): ?>
          <tr>
            <td><?= e('ICS ' . (string)$f['form_number'] . ' - ' . (string)($f['form_name'] ?: $f['form_type'])) ?></td>
            <td><?= e(ucfirst((string)$f['status'])) ?></td>
            <td><?= e(trim((string)($f['prepared_by_name'] ?? '') . ' ' . (!empty($f['prepared_by_position_title']) ? '(' . (string)$f['prepared_by_position_title'] . ')' : ''))) ?></td>
            <td><a class="btn btn-small" href="<?= e(dashboard_form_link((string)$f['form_type'], (int)$f['id'])) ?>">Open</a></td>
          </tr>
        <?php endforeach; ?>
        </tbody></table>
      <?php endif; ?>

      <h3>Current Operational Period Form Status</h3>
      <p class="small muted">Forms 201 through 208 are shown for the current operational period. Other forms are shown only when a current-period record exists.</p>
      <?php if (!$currentOpForms): ?>
        <p class="muted">No current operational period form status is available.</p>
      <?php else: ?>
        <table><thead><tr><th>Form</th><th>Status</th><th>Last activity</th><th>Action</th></tr></thead><tbody>
        <?php foreach ($currentOpForms as $f): ?>
          <?php
            $status = (string)$f['status'];
            $statusClass = 'priority-routine';
            if ($status === 'approved') { $statusClass = 'priority-low'; }
            elseif ($status === 'submitted') { $statusClass = 'priority-routine'; }
            elseif ($status === 'rejected' || $status === 'draft' || $status === 'not_started') { $statusClass = 'priority-high'; }
            $lastActivity = (string)($f['approved_at'] ?: $f['submitted_at'] ?: $f['updated_at'] ?: $f['created_at'] ?: '');
          ?>
          <tr>
            <td><?= e('ICS ' . (string)$f['form_number'] . ' - ' . (string)$f['form_name']) ?></td>
            <td>
              <span class="<?= e($statusClass) ?>"><?= e(dashboard_form_status_label($status)) ?></span>
              <?php if (!empty($f['review_required'])): ?><br><span class="small priority-high">Review required</span><?php endif; ?>
            </td>
            <td><?= e($lastActivity ?: '-') ?></td>
            <td>
              <?php if (!empty($f['has_record']) && !empty($f['id'])): ?>
                <a class="btn btn-small" href="<?= e(dashboard_form_link((string)$f['form_type'], (int)$f['id'])) ?>">Open</a>
              <?php else: ?>
                <a class="btn btn-small btn-secondary" href="<?= e(dashboard_form_link((string)$f['form_type'], 0)) ?>">Start</a>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody></table>
      <?php endif; ?>
      <p class="small muted">Notifications refresh every 120 seconds. Last checked: <?= e(date('M j, Y H:i:s')) ?></p>
      <?php endif; ?>

    </div>
    <?php
}

if (isset($_GET['partial']) && $_GET['partial'] === 'notifications') {
    dashboard_render_notification_panels($incidentId);
    exit;
}

render_header('Dashboard');

?>

<div class="dashboard-row">
  <div class="card dashboard-main-card">
    <h2>Notifications</h2>
    <div class="actions"><button class="btn btn-secondary btn-small" type="button" id="refresh-notifications">Refresh Notifications</button></div>
    <div id="dashboard-notifications-wrap">
      <?php dashboard_render_notification_panels($incidentId); ?>
    </div>
  </div>

  <div class="card dashboard-side-card">
    <?php $canCreateOp = current_user_can_create_operational_period($incidentId); ?>
    <h2>Scheduled Meetings</h2>
    <p class="actions" style="display:flex;flex-wrap:wrap;gap:12px">
      <?php if ($currentOperationalPeriod && current_user_can_edit_operational_period($incidentId)): ?>
        <a class="btn btn-secondary" href="<?= e(app_url('/operational_periods/edit.php?id=' . (int)$currentOperationalPeriod['id'])) ?>">Edit Current Operational Period</a>
      <?php endif; ?>
      <?php if ($canCreateOp): ?>
        <a class="btn" href="<?= e(app_url('/operational_periods/new.php')) ?>">New Operational Period</a>
        <a class="btn btn-secondary" href="<?= e(app_url('/operational_periods/assignments.php')) ?>">Position Assignments</a>
      <?php endif; ?>
    </p>
    <p class="dashboard-current-time">Current time: <?= e($currentTimeDisplay) ?></p>
    <?php if ($currentOperationalPeriod): ?>
      <p class="small"><?= e(operational_period_label($currentOperationalPeriod)) ?></p>
      <p class="small"><strong>Current operational period ends:</strong> <?= e(format_datetime_display((string)($currentOperationalPeriod['op_end'] ?? ''))) ?></p>
    <?php endif; ?>
    <?php if (!$meetings): ?>
      <p class="muted">No upcoming meetings scheduled for the selected operational period.</p>
    <?php else: ?>
      <div class="meeting-list">
        <?php foreach ($meetings as $meeting): ?>
          <?php $isNextMeeting = $nextMeetingId > 0 && (int)$meeting['id'] === $nextMeetingId; ?>
          <div class="meeting-list-item<?= $isNextMeeting ? ' next-meeting' : '' ?>">
            <?php if ($isNextMeeting): ?><div class="next-meeting-badge">Next meeting</div><?php endif; ?>
            <div class="meeting-time"><?= e(format_datetime_display((string)$meeting['scheduled_at'])) ?></div>
            <div class="meeting-title"><?= e((string)$meeting['title']) ?></div>
            <div class="meeting-meta"><?= e((string)$meeting['meeting_type_name']) ?><?php if (!empty($meeting['location'])): ?> · <?= e((string)$meeting['location']) ?><?php endif; ?></div>
            <?php if (!empty($meeting['chair_name'])): ?>
              <div class="meeting-meta">Chair: <?= e((string)$meeting['chair_name']) ?><?php if (!empty($meeting['chair_position_title'])): ?> (<?= e((string)$meeting['chair_position_title']) ?>)<?php endif; ?></div>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
      </div>
      <p><a class="btn btn-secondary" href="<?= e(app_url('/forms/ics230/index.php')) ?>">Manage Schedule</a></p>
    <?php endif; ?>
  </div>
</div>

<script>
(function(){
  const wrap = document.getElementById('dashboard-notifications-wrap');
  const btn = document.getElementById('refresh-notifications');
  async function refreshNotifications() {
    if (!wrap) return;
    try {
      const url = new URL(window.location.href);
      url.searchParams.set('partial', 'notifications');
      const response = await fetch(url.toString(), {headers: {'X-Requested-With': 'XMLHttpRequest'}, cache: 'no-store'});
      if (response.status === 401) {
        // Signed out for inactivity: leave the incident data and show the sign-in page with the reason.
        const info = await response.json().catch(() => ({}));
        window.location.href = info.login_url || '<?= e(app_url('/login.php')) ?>';
        return;
      }
      if (response.ok) wrap.innerHTML = await response.text();
    } catch (err) {}
  }
  if (btn) btn.addEventListener('click', refreshNotifications);
  window.setInterval(refreshNotifications, 120000);
})();
</script>

<?php render_footer(); ?>
