<?php
declare(strict_types=1);
/*
 * Audit Log viewer for SysAdmins and the Incident Commander / Unified Command.
 * Filters: person, category, date range and text; 25/50/100/250/All rows per page. Read-only, no export.
 */
require_once dirname(__DIR__) . '/includes/layout.php';
require_once dirname(__DIR__) . '/includes/ics214_activity.php';
require_login();

$pdo = db();
$incident = active_incident();
$incidentId = (int)($incident['id'] ?? 0);
$me = current_user();
$myId = (int)($me['id'] ?? 0);
$isSysadmin = is_system_administrator();
$isCommand = $incidentId > 0 && (bool)array_intersect(['IC', 'UC'], array_map('strtoupper', get_user_role_codes($incidentId, $myId)));
if (!$isSysadmin && !$isCommand) {
    http_response_code(403);
    render_header('Audit Log');
    echo '<div class="card"><h2>Audit Log</h2><p>The audit log is available to SysAdmins and the Incident Commander.</p></div>';
    render_footer(); exit;
}

// ---------------------------------------------------------------------------------------------- filters
$categories = ['signin' => 'Sign-ins', 'account' => 'Accounts', 'form' => 'Forms', 'system' => 'Settings / other'];
$pageSizes = ['25' => 25, '50' => 50, '100' => 100, '250' => 250, 'all' => 0];
const AUDIT_VIEW_ALL_CAP = 20000;

$f = [
    'person' => (int)($_GET['person'] ?? 0),
    'cat' => array_key_exists((string)($_GET['cat'] ?? ''), $categories) ? (string)$_GET['cat'] : '',
    'from' => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['from'] ?? '')) ? (string)$_GET['from'] : '',
    'to' => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['to'] ?? '')) ? (string)$_GET['to'] : '',
    'q' => mb_substr(trim((string)($_GET['q'] ?? '')), 0, 100),
    'scope' => ($isSysadmin && ($_GET['scope'] ?? '') === 'all') ? 'all' : 'incident',
    'per' => array_key_exists((string)($_GET['per'] ?? ''), $pageSizes) ? (string)$_GET['per'] : '50',
];
$page = max(1, (int)($_GET['page'] ?? 1));

// The same buckets as the ICS 214 activity log (ics214_audit_category()), as SQL.
$catSql = "CASE
    WHEN LOWER(a.action_type) IN ('login', 'logout', 'login_failed') THEN 'signin'
    WHEN UPPER(a.entity_type) = 'USER' OR LOWER(a.action_type) IN ('account_locked', 'unlock_user', 'reset_pin', 'create_user')
         OR LOWER(a.action_type) LIKE 'change\\_own\\_%' OR LOWER(a.action_type) LIKE '%companion\\_checkin%' THEN 'account'
    WHEN UPPER(a.entity_type) LIKE 'ICS%' OR UPPER(a.entity_type) IN ('FORM_RECORD', 'FINANCE', 'RESOURCES', 'EXTERNAL_CHECKIN', 'OPERATIONAL_PERIOD')
         OR LOWER(a.action_type) LIKE '%form%'
         OR LOWER(a.action_type) IN ('operational_period_assignments_updated', 'create_operational_period', 'operational_period_updated') THEN 'form'
    ELSE 'system' END";

$where = ['1=1'];
$params = [];
if ($f['scope'] === 'incident') {
    // This incident, plus events that belong to no incident (sign-ins, settings).
    $where[] = '(a.incident_id = ? OR a.incident_id IS NULL)';
    $params[] = $incidentId;
}
if ($f['person'] > 0) {
    $where[] = "(a.user_id = ? OR (a.entity_type = 'user' AND a.entity_id = ?))";
    $params[] = $f['person']; $params[] = $f['person'];
}
if ($f['cat'] !== '') { $where[] = "($catSql) = ?"; $params[] = $f['cat']; }
if ($f['from'] !== '') { $where[] = 'a.created_at >= ?'; $params[] = $f['from'] . ' 00:00:00'; }
if ($f['to'] !== '') { $where[] = 'a.created_at < DATE_ADD(?, INTERVAL 1 DAY)'; $params[] = $f['to']; }
if ($f['q'] !== '') {
    $like = '%' . addcslashes($f['q'], '%_\\') . '%';
    $where[] = '(a.action_detail LIKE ? OR a.action_type LIKE ? OR a.entity_type LIKE ? OR u.real_name LIKE ? OR u.username LIKE ?)';
    array_push($params, $like, $like, $like, $like, $like);
}
$whereSql = implode(' AND ', $where);

$total = 0;
$rows = [];
$perPage = $pageSizes[$f['per']];
try {
    $st = $pdo->prepare("SELECT COUNT(*) FROM audit_log a LEFT JOIN users u ON u.id = a.user_id WHERE $whereSql");
    $st->execute($params);
    $total = (int)$st->fetchColumn();
    $limit = $perPage > 0 ? $perPage : AUDIT_VIEW_ALL_CAP;
    $pages = $perPage > 0 ? max(1, (int)ceil($total / $perPage)) : 1;
    $page = min($page, $pages);
    $offset = $perPage > 0 ? ($page - 1) * $perPage : 0;
    $st = $pdo->prepare("SELECT a.*, COALESCE(NULLIF(u.real_name, ''), u.username) AS actor_name, ($catSql) AS cat
        FROM audit_log a LEFT JOIN users u ON u.id = a.user_id
        WHERE $whereSql ORDER BY a.created_at DESC, a.id DESC LIMIT " . (int)$limit . ' OFFSET ' . (int)$offset);
    $st->execute($params);
    $rows = $st->fetchAll();
} catch (Throwable $e) {
    security_log($e, 'audit_view');
    flash('error', 'The audit log could not be read.');
    $pages = 1;
}

// Names of the accounts that were the subject of account events.
$targets = [];
$ids = [];
foreach ($rows as $r) { if (strtolower((string)$r['entity_type']) === 'user' && (int)$r['entity_id'] > 0) { $ids[(int)$r['entity_id']] = true; } }
if ($ids) {
    try {
        $st = $pdo->prepare('SELECT id, COALESCE(NULLIF(real_name, \'\'), username) AS n FROM users WHERE id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')');
        $st->execute(array_keys($ids));
        foreach ($st->fetchAll() as $u) { $targets[(int)$u['id']] = (string)$u['n']; }
    } catch (Throwable $e) {}
}

$people = [];
try {
    $people = $pdo->query("SELECT id, COALESCE(NULLIF(real_name, ''), username) AS n FROM users ORDER BY n")->fetchAll();
} catch (Throwable $e) {}

$query = static function (array $change) use ($f): string {
    $q = array_filter(array_merge($f, $change), static fn($v) => $v !== '' && $v !== 0 && $v !== null);
    if (($q['scope'] ?? '') === 'incident') { unset($q['scope']); }
    return app_url('/audit/index.php') . ($q ? '?' . http_build_query($q) : '');
};

render_header('Audit Log');
?>
<style>
.audit-filters{display:flex;flex-wrap:wrap;gap:10px;align-items:flex-end}
.audit-filters label{display:flex;flex-direction:column;font-size:13px}
.audit-table td.when{white-space:nowrap}
.audit-table td.cat{white-space:nowrap;font-size:13px}
.audit-pager{display:flex;gap:6px;flex-wrap:wrap;align-items:center;margin-top:10px}
</style>
<div class="card">
  <h2>Audit Log</h2>
  <p>Who did what and when: sign-ins, account changes, forms, settings and other recorded actions. Nothing here can be changed or deleted.</p>
  <form method="get" class="audit-filters no-print">
    <label>Person
      <select name="person"><option value="">Everyone</option>
        <?php foreach ($people as $p): ?><option value="<?= (int)$p['id'] ?>"<?= (int)$p['id'] === $f['person'] ? ' selected' : '' ?>><?= e((string)$p['n']) ?></option><?php endforeach; ?>
      </select></label>
    <label>Category
      <select name="cat"><option value="">All</option>
        <?php foreach ($categories as $k => $label): ?><option value="<?= e($k) ?>"<?= $k === $f['cat'] ? ' selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?>
      </select></label>
    <label>From<input type="date" name="from" value="<?= e($f['from']) ?>"></label>
    <label>To<input type="date" name="to" value="<?= e($f['to']) ?>"></label>
    <label>Contains<input type="search" name="q" value="<?= e($f['q']) ?>" maxlength="100"></label>
    <?php if ($isSysadmin): ?>
      <label>Incidents
        <select name="scope"><option value="incident">This incident</option><option value="all"<?= $f['scope'] === 'all' ? ' selected' : '' ?>>All incidents</option></select></label>
    <?php endif; ?>
    <label>Show
      <select name="per"><?php foreach (array_keys($pageSizes) as $k): ?><option value="<?= e($k) ?>"<?= $k === $f['per'] ? ' selected' : '' ?>><?= $k === 'all' ? 'All' : e($k) ?></option><?php endforeach; ?></select></label>
    <button type="submit" class="btn btn-primary">Filter</button>
    <a class="btn btn-secondary" href="<?= e(app_url('/audit/index.php')) ?>">Clear</a>
  </form>

  <p class="small"><?= number_format($total) ?> matching entr<?= $total === 1 ? 'y' : 'ies' ?><?php if ($perPage === 0 && $total > AUDIT_VIEW_ALL_CAP): ?>; showing the newest <?= number_format(AUDIT_VIEW_ALL_CAP) ?> (narrow the filters to see older ones)<?php endif; ?>.</p>
  <table class="table audit-table cb-cards">
    <thead><tr><th>Date/time</th><th>Person</th><th>Category</th><th>What happened</th><th>Record</th></tr></thead>
    <tbody>
      <?php if (!$rows): ?><tr><td colspan="5" class="muted">No entries match these filters.</td></tr><?php endif; ?>
      <?php foreach ($rows as $r):
          $actor = trim((string)($r['actor_name'] ?? '')) !== '' ? (string)$r['actor_name'] : 'System';
          $target = strtolower((string)$r['entity_type']) === 'user' ? ($targets[(int)$r['entity_id']] ?? '') : ''; ?>
        <tr>
          <td class="when"><?= e(date('Y-m-d H:i:s', (int)strtotime((string)$r['created_at']))) ?></td>
          <td><?= e($actor) ?></td>
          <td class="cat"><?= e($categories[(string)$r['cat']] ?? '') ?></td>
          <td title="<?= e((string)$r['action_type']) ?>"><?= e(ics214_audit_sentence($r, $actor, $target)) ?></td>
          <td class="small"><?= e((string)$r['entity_type']) ?><?= (int)$r['entity_id'] > 0 ? ' #' . (int)$r['entity_id'] : '' ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  <?php if ($perPage > 0 && $pages > 1): ?>
    <div class="audit-pager no-print">
      <?php if ($page > 1): ?><a class="btn btn-small" href="<?= e($query(['page' => 1])) ?>">&laquo; Newest</a><a class="btn btn-small" href="<?= e($query(['page' => $page - 1])) ?>">&lsaquo; Newer</a><?php endif; ?>
      <span>Page <?= $page ?> of <?= $pages ?></span>
      <?php if ($page < $pages): ?><a class="btn btn-small" href="<?= e($query(['page' => $page + 1])) ?>">Older &rsaquo;</a><a class="btn btn-small" href="<?= e($query(['page' => $pages])) ?>">Oldest &raquo;</a><?php endif; ?>
    </div>
  <?php endif; ?>
</div>
<?php render_footer(); ?>
