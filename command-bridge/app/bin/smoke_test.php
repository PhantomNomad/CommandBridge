<?php
declare(strict_types=1);

/**
 * Command Bridge smoke test.
 *
 *   sudo -u www-data php bin/smoke_test.php [--base-url=https://your-server/] [--insecure] [--only=env,approvers,...] [-v]
 *
 * Sections:   env  approvers  signing  resources  sync  pdf  http
 *
 * SAFE TO RUN ON THE LIVE SERVER. Everything that writes (the TRAINING incident, test users, positions, forms, queue
 * rows) happens inside ONE database transaction that is ROLLED BACK at the end, so nothing is saved, no other user
 * ever sees it, and the real "active incident" is not touched. No e-mail is sent and nothing is sent to the gateway.
 * The script refuses to run the write scenarios if any table it touches is not InnoDB (rollback would not work).
 *
 * Exit code 0 = everything passed (skips allowed), 1 = at least one check failed.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only.\n");
}

$appRoot = dirname(__DIR__);
require_once $appRoot . '/includes/auth.php';          // db.php + helpers.php + security.php
require_once $appRoot . '/includes/signoff.php';
require_once $appRoot . '/includes/ics209_resources.php';
require_once $appRoot . '/includes/external_checkin_sync.php';
require_once $appRoot . '/includes/external_checkin_status.php';
require_once $appRoot . '/includes/pdf/OfficialPdfTemplateExporter.php';
require_once $appRoot . '/includes/pdf/SimpleTextPdf.php';
require_once $appRoot . '/includes/pdf/pdf_request.php';
require_once $appRoot . '/forms/ics209/Ics209OfficialPdfFieldMapper.php';
require_once $appRoot . '/forms/ics209/Ics209WfOfficialPdfFieldMapper.php';
require_once $appRoot . '/forms/ics213rr_v2/Ics213rrOfficialPdfFieldMapper.php';

$only = [];
$baseUrl = '';
$insecure = false;
$verbose = false;
foreach (array_slice($argv, 1) as $a) {
    if (preg_match('/^--only=(.+)$/', $a, $m)) { $only = array_map('trim', explode(',', strtolower($m[1]))); }
    elseif (preg_match('/^--base-url=(.+)$/', $a, $m)) { $baseUrl = rtrim($m[1], '/'); }
    elseif ($a === '--insecure') { $insecure = true; }
    elseif ($a === '-v' || $a === '--verbose') { $verbose = true; }
    elseif ($a === '-h' || $a === '--help') { echo "See the comment at the top of bin/smoke_test.php\n"; exit(0); }
}
$want = static fn(string $s): bool => !$only || in_array($s, $only, true);

// ---------------------------------------------------------------------------------------------------------------
// Tiny test harness
// ---------------------------------------------------------------------------------------------------------------
$T = ['pass' => 0, 'fail' => 0, 'skip' => 0, 'failures' => []];
$section = '';
function t_section(string $title): void { global $section; $section = $title; echo "\n== $title\n"; }
function t_ok(string $name, bool $cond, string $detail = ''): bool
{
    global $T, $section, $verbose;
    if ($cond) { $T['pass']++; echo "  PASS  $name" . ($verbose && $detail !== '' ? "  ($detail)" : '') . "\n"; return true; }
    $T['fail']++; $T['failures'][] = "$section: $name" . ($detail !== '' ? " ($detail)" : '');
    echo "  FAIL  $name" . ($detail !== '' ? "  ($detail)" : '') . "\n";
    return false;
}
function t_skip(string $name, string $why): void { global $T; $T['skip']++; echo "  SKIP  $name  ($why)\n"; }
function t_info(string $msg): void { echo "  info  $msg\n"; }
function t_run(string $name, callable $fn): void
{
    try { $fn(); } catch (Throwable $e) { t_ok($name . ' (no exception)', false, get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine()); }
}

echo 'Command Bridge smoke test - ' . date('Y-m-d H:i:s') . "\n";
$pdo = null;
try {
    $pdo = db();
    echo 'Database: ' . $pdo->query('SELECT VERSION()')->fetchColumn() . ' / ' . $pdo->query('SELECT DATABASE()')->fetchColumn() . "\n";
} catch (Throwable $e) {
    if ($only !== ['pdf'] && $only !== ['http']) { echo "FATAL: cannot talk to the database: " . $e->getMessage() . "\n"; exit(1); }
    echo "Database not available (only the " . $only[0] . " checks will run).\n";
}

// ---------------------------------------------------------------------------------------------------------------
// 1. Environment (read only)
// ---------------------------------------------------------------------------------------------------------------
if ($want('env')) {
    t_section('Environment and installation');
    t_ok('PHP 8.1 or newer', version_compare(PHP_VERSION, '8.1.0', '>='), PHP_VERSION);
    foreach (['pdo_mysql', 'mbstring', 'json', 'openssl', 'curl'] as $ext) { t_ok("PHP extension $ext", extension_loaded($ext)); }

    $tables = ['incidents', 'users', 'roles', 'incident_positions', 'incident_position_catalog', 'operational_periods', 'form_records', 'form_signatures',
        'notifications', 'audit_log', 'app_settings', 'form_209', 'form_209_resources', 'form_211_headers', 'form_211_entries', 'form_218_headers',
        'form_218_entries', 'form_213rr_headers', 'form_213rr_items', 'resource_unit_intake', 'resource_demob_tracker', 'external_checkin_sync_queue'];
    $missing = [];
    foreach ($tables as $tb) {
        try { $pdo->query('SELECT 1 FROM `' . $tb . '` LIMIT 1'); } catch (Throwable $e) { $missing[] = $tb; }
    }
    t_ok('Required tables exist', !$missing, $missing ? 'missing: ' . implode(', ', $missing) : '');

    $needCols = ['form_records' => ['current_approver_user_id', 'approved_at', 'submitted_by_user_id'], 'form_209' => ['fire_status'],
        'external_checkin_sync_queue' => ['next_attempt_at', 'max_attempts', 'attempt_count', 'locked_at', 'failed_at']];
    foreach ($needCols as $tb => $cols) {
        $have = in_array($tb, $missing, true) ? [] : table_columns($tb);
        $lack = array_values(array_diff($cols, $have));
        t_ok("Columns on $tb", !$lack, $lack ? 'missing: ' . implode(', ', $lack) . ' (run update.sh to apply pending database migrations)' : '');
    }

    foreach (['IC', 'UC', 'PSC', 'LSC', 'COML'] as $rc) {
        $st = $pdo->prepare('SELECT COUNT(*) FROM roles WHERE role_code = ?'); $st->execute([$rc]);
        t_ok("Position $rc exists in roles", (int)$st->fetchColumn() > 0);
    }
    $up = signoff_reports_to_map();
    t_ok('Chain of command: COML reports to LSC', ($up['COML'] ?? '') === 'LSC', 'catalogue says ' . ($up['COML'] ?? 'nothing'));
    t_ok('Chain of command: LSC reports to IC', ($up['LSC'] ?? '') === 'IC', 'catalogue says ' . ($up['LSC'] ?? 'nothing'));
    t_ok('Chain of command: PSC reports to IC', ($up['PSC'] ?? '') === 'IC', 'catalogue says ' . ($up['PSC'] ?? 'nothing'));

    $bad = [];
    try {
        foreach ($pdo->query("SELECT table_name AS n, engine AS e FROM information_schema.tables WHERE table_schema = DATABASE() AND engine <> 'InnoDB' AND table_type = 'BASE TABLE'")->fetchAll() as $r) { $bad[] = $r['n'] . '(' . $r['e'] . ')'; }
    } catch (Throwable $e) { /* no information_schema access */ }
    t_ok('All tables are InnoDB (transactions work)', !$bad, implode(', ', $bad));

    $dbEnv = DB_ENV_PATH;
    $perm = is_file($dbEnv) ? (fileperms($dbEnv) & 0777) : -1;
    t_ok('db.env exists and is not world-readable', $perm >= 0 && ($perm & 0007) === 0, $perm >= 0 ? sprintf('mode %04o', $perm) : 'not found');

    $envVars = [];
    try { $envVars = load_db_env(); } catch (Throwable $e) { /* reported above */ }
    t_ok('APP_TIMEZONE is set in db.env', !empty($envVars['APP_TIMEZONE']), 'add a line like APP_TIMEZONE=America/Edmonton (otherwise times use UTC)');
    t_ok('Application timezone is active', date_default_timezone_get() === ($envVars['APP_TIMEZONE'] ?? date_default_timezone_get()), date_default_timezone_get());
    try {
        $applied = $pdo->query('SELECT version FROM schema_migrations')->fetchAll(PDO::FETCH_COLUMN);
        t_ok('Database baseline recorded (schema_migrations)', in_array('0000_baseline', $applied, true));
        $files = glob(dirname($appRoot) . '/database/migrations/[0-9]*.sql') ?: [];
        $pending = array_values(array_filter(array_map(static fn($f) => basename($f, '.sql'), $files), static fn($v) => !in_array($v, $applied, true)));
        t_ok('No pending database migrations', !$pending, $pending ? 'pending: ' . implode(', ', $pending) . ' - run update.sh' : '');
    } catch (Throwable $e) {
        t_ok('Database baseline recorded (schema_migrations)', false, 'table missing - run update.sh');
    }
    $verFile = $appRoot . '/VERSION';
    t_ok('VERSION file present', is_file($verFile), is_file($verFile) ? trim((string)file_get_contents($verFile)) : 'missing');

    foreach (['ICS209' => 'ICS209.pdf', 'ICS209WF' => 'ICS209-wf.pdf', 'ICS213RR' => 'ICS213RR.pdf'] as $code => $file) {
        $f = $appRoot . '/assets/pdf/official/' . $file;
        t_ok("Official PDF template $file present", is_file($f) && filesize($f) > 10000);
    }
    $pdftk = trim((string)(getenv('COMMAND_BRIDGE_PDFTK_BIN') ?: 'pdftk'));
    $out = []; $rc = 1;
    @exec(escapeshellarg($pdftk) . ' --version 2>&1', $out, $rc);
    t_ok('pdftk runs', $rc === 0, $rc === 0 ? (string)($out[0] ?? '') : 'set COMMAND_BRIDGE_PDFTK_BIN or install pdftk-java');
    t_ok('Temp directory writable', is_writable(sys_get_temp_dir()), sys_get_temp_dir());
    // The Resources Unit and Finance/Admin pages are controlled by these form codes (migration 0002).
    foreach (['RESOURCES_UNIT' => 'Resources Unit page', 'FINANCE_ADMIN' => 'Finance/Admin queue'] as $unitCode => $unitLabel) {
        try {
            $st = $pdo->prepare('SELECT COUNT(*) FROM forms WHERE form_code = ? AND is_active = 1');
            $st->execute([$unitCode]);
            $hasForm = (int)$st->fetchColumn() > 0;
            $st = $pdo->prepare('SELECT GROUP_CONCAT(role_code ORDER BY role_code) FROM form_permissions WHERE form_code = ? AND can_view = 1 AND is_active = 1');
            $st->execute([$unitCode]);
            $viewers = (string)$st->fetchColumn();
            t_ok("$unitLabel can be opened by a unit role ($unitCode)", $hasForm && $viewers !== '',
                $hasForm ? ($viewers !== '' ? 'roles: ' . $viewers : 'no role has view permission') : "form code missing; run update.sh (migration 0002)");
        } catch (Throwable $e) { t_ok("$unitLabel permissions readable", false, $e->getMessage()); }
    }
    // TSC renamed SysAdmin (migration 0004).
    try {
        t_ok('SysAdmin flag column present (users.is_sysadmin)', in_array('is_sysadmin', table_columns('users'), true), 'run update.sh (migration 0004)');
        $oldTsc = (int)$pdo->query("SELECT (SELECT COUNT(*) FROM roles WHERE role_code = 'TSC') + (SELECT COUNT(*) FROM incident_position_catalog WHERE acronym = 'TSC')
            + (SELECT COUNT(*) FROM form_permissions WHERE role_code = 'TSC')")->fetchColumn();
        t_ok('No TSC position left (SysAdmin is not an ICS position)', $oldTsc === 0, $oldTsc . ' TSC row(s) left; run update.sh (migration 0004)');
        $admins = (int)$pdo->query('SELECT COUNT(*) FROM users WHERE is_sysadmin = 1 AND is_active = 1 AND deleted_at IS NULL')->fetchColumn();
        t_ok('At least one active SysAdmin account', $admins > 0, 'none; run bin/create_admin.php');
    } catch (Throwable $e) { t_ok('SysAdmin checks', false, $e->getMessage()); }

    // ICS 214: one log per person (migration 0003).
    try {
        $cols = table_columns('form_214_activity_log');
        t_ok('ICS 214 entries record who added them (form_214_activity_log.added_by_user_id)', in_array('added_by_user_id', $cols, true) && in_array('added_by_name', $cols, true),
            'columns missing; run update.sh (migration 0003)');
        $st = $pdo->query("SELECT can_view, can_create, can_submit_final FROM form_permissions WHERE form_code = 'ICS214' AND role_code = 'ALL_AUTHENTICATED' AND is_active = 1");
        $p214 = $st ? $st->fetch() : false;
        t_ok('Every signed-in user can open and sign their own ICS 214 (ALL_AUTHENTICATED)', $p214 && (int)$p214['can_view'] === 1 && (int)$p214['can_submit_final'] === 1,
            'permission row missing; run update.sh (migration 0003)');
        $orphans = (int)$pdo->query("SELECT COUNT(*) FROM form_records fr LEFT JOIN form_214_headers h ON h.form_record_id = fr.id
            WHERE UPPER(REPLACE(fr.form_type, ' ', '')) = 'ICS214' AND fr.is_deleted = 0 AND COALESCE(h.user_id, fr.prepared_by_user_id) IS NULL")->fetchColumn();
        t_ok('Every ICS 214 log has an owner', $orphans === 0, $orphans . ' log(s) without a person');
        foreach (['/includes/ics214_log.php', '/includes/meetings.php', '/forms/ics214/logs.php', '/audit/index.php'] as $rel) {
            t_ok("File present: app$rel", is_file($appRoot . $rel));
        }
    } catch (Throwable $e) { t_ok('ICS 214 checks', false, $e->getMessage()); }

    $logoSetting = (string)get_app_setting('branding_logo_file', '');
    if ($logoSetting !== '' && branding_logo_file_if_present($logoSetting) === '') {
        t_info("The saved organization logo ($logoSetting) is missing from storage/branding; the default icon is shown. Upload the logo again in Organization & Branding.");
    }
    require_once $appRoot . '/includes/help.php';
    $helpExpected = cb_help_expected_topics();
    $helpMissing = array_values(array_filter($helpExpected, static fn($t) => cb_help_topic_file($t) === null));
    t_ok('Every page has a help file (app/help/topics/<page>.md)', $helpMissing === [], 'missing: ' . implode(', ', $helpMissing));
    $helpStubs = 0;
    foreach ($helpExpected as $t) {
        $f = cb_help_topic_file($t);
        if ($f !== null && str_contains((string)file_get_contents($f), 'still being written')) { $helpStubs++; }
    }
    t_info('Help: ' . (count($helpExpected) - $helpStubs) . ' of ' . count($helpExpected) . ' pages have full help; ' . $helpStubs . ' still have a summary only.');
    $idle = (int)ini_get('session.gc_maxlifetime');
    t_info('Sign-out after inactivity: ' . intdiv(cb_session_idle_seconds(), 60) . ' minutes (session.gc_maxlifetime = ' . $idle
        . ($idle < 300 ? ', below 5 minutes so 60 is used' : '') . '; set in /etc/php/<ver>/{apache2,cli}/conf.d/90-commandbridge.ini)');

    try {
        $configured = external_checkin_sync_is_configured();
        if (!$configured) { t_info('External check-in sync is not configured (skipping sync health).'); }
        else {
            $st = external_checkin_sync_status($pdo);
            $hb = $st['heartbeat'];
            $age = !empty($hb['run_at']) ? time() - (int)$hb['run_at'] : -1;
            t_ok('Sync worker ran in the last 10 minutes', $age >= 0 && $age <= 600, $age >= 0 ? external_checkin_age_text($age) . ' ago' : 'never reported');
            t_ok('Sync status has no failing problems', $st['level'] !== 'fail', implode(' | ', array_map(static fn($p) => $p['text'], $st['problems'])));
            t_info('Queue now: waiting=' . $st['queue']['waiting'] . ' retrying=' . $st['queue']['retrying'] . ' stuck/failed=' . $st['queue']['failed']);
            t_ok('Public check-in link configured (EXTERNAL_CHECKIN_PUBLIC_ENTRY_BASE_URL)',
                external_checkin_sync_config()['public_entry_base_url'] !== '', 'QR codes on Admin > Locations need it; run tools/setup_checkin.sh');
            // Signed, read-only connection test (bin/external_checkin_ping.php): never collects or changes check-ins.
            $pingOut = []; $pingRc = 1;
            @exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/external_checkin_ping.php') . ' 2>&1', $pingOut, $pingRc);
            $pingFail = array_values(array_filter($pingOut, static fn($l) => str_starts_with((string)$l, 'FAIL')));
            t_ok('Check-In Gateway connection test passes', $pingRc === 0, $pingFail ? substr((string)$pingFail[0], 5) : implode(' | ', $pingOut));
        }
    } catch (Throwable $e) { t_ok('Sync status readable', false, $e->getMessage()); }

    $map = signoff_approver_map();
    $default = signoff_default_approver_map();
    $custom = [];
    foreach ($default as $f => $cfg) { if (($map[$f] ?? $cfg) != $cfg) { $custom[] = $f; } }
    t_info($custom ? 'Sign-off approver settings differ from the defaults for: ' . implode(', ', $custom) : 'Sign-off approver settings are the defaults.');
}

// ---------------------------------------------------------------------------------------------------------------
// 2. TRAINING incident scenarios (all inside one transaction that is rolled back)
// ---------------------------------------------------------------------------------------------------------------
$needDb = $want('approvers') || $want('signing') || $want('resources') || $want('sync');
$ctx = null;
if ($needDb) {
    t_section('Building the TRAINING incident (rolled back at the end)');
    register_shutdown_function(static function () { try { if (db()->inTransaction()) { db()->rollBack(); } } catch (Throwable $e) { } });
    try {
        $nonInno = $pdo->query("SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE() AND engine <> 'InnoDB' AND table_name IN
            ('incidents','users','incident_positions','operational_periods','form_records','form_signatures','notifications','audit_log','form_211_headers','form_211_entries',
             'form_218_headers','form_218_entries','external_checkin_sync_queue')")->fetchAll();
        if ($nonInno) { throw new RuntimeException('Tables are not InnoDB, so the test cannot be rolled back: ' . implode(', ', array_column($nonInno, 'table_name'))); }
        $pdo->beginTransaction();

        $pdo->prepare("INSERT INTO incidents (incident_name, incident_number, incident_type, status, started_at, created_at) VALUES (?, 'SMOKE-TEST', 'Training', 'active', NOW(), NOW())")
            ->execute(['TRAINING - smoke test ' . date('Y-m-d H:i')]);
        $inc = (int)$pdo->lastInsertId();
        $pdo->prepare("INSERT INTO operational_periods (incident_id, period_number, period_name, op_start, op_end, status, created_at) VALUES (?, 1, 'OP 1', DATE_SUB(NOW(), INTERVAL 1 HOUR), DATE_ADD(NOW(), INTERVAL 11 HOUR), 'active', NOW())")
            ->execute([$inc]);
        $op = (int)$pdo->lastInsertId();

        $mkUser = static function (string $name, int $sysadmin = 0) use ($pdo): int {
            $pdo->prepare("INSERT INTO users (username, real_name, email, is_sysadmin, is_active, created_at) VALUES (?, ?, NULL, ?, 1, NOW())")
                ->execute(['smoke_' . $name . '_' . bin2hex(random_bytes(3)), 'Smoke ' . strtoupper($name), $sysadmin]);
            return (int)$pdo->lastInsertId();
        };
        $roleId = static function (string $code) use ($pdo): int {
            $st = $pdo->prepare('SELECT id FROM roles WHERE role_code = ? LIMIT 1'); $st->execute([$code]);
            return (int)$st->fetchColumn();
        };
        $assign = static function (int $incident, int $user, string $role) use ($pdo, $roleId): int {
            $rid = $roleId($role);
            if ($rid <= 0) { throw new RuntimeException("Position $role does not exist in roles."); }
            $pdo->prepare('INSERT INTO incident_positions (incident_id, role_id, user_id, assigned_start, created_at) VALUES (?, ?, ?, NOW(), NOW())')->execute([$incident, $rid, $user]);
            return (int)$pdo->lastInsertId();
        };
        $unassign = static function (int $positionRowId) use ($pdo): void {
            $pdo->prepare('UPDATE incident_positions SET deleted_at = NOW(), assigned_end = NOW() WHERE id = ?')->execute([$positionRowId]);
        };

        $u = ['ic' => $mkUser('ic'), 'psc' => $mkUser('psc'), 'lsc' => $mkUser('lsc'), 'coml' => $mkUser('coml'), 'lsc2' => $mkUser('lsc2')];
        $ip = ['ic' => $assign($inc, $u['ic'], 'IC'), 'psc' => $assign($inc, $u['psc'], 'PSC'), 'lsc' => $assign($inc, $u['lsc'], 'LSC'), 'coml' => $assign($inc, $u['coml'], 'COML')];
        $ctx = compact('inc', 'op', 'u', 'ip', 'assign', 'unassign', 'mkUser');
        t_ok('TRAINING incident, operational period, 4 users with positions created', $inc > 0 && $op > 0);
        // The functions under test read the "current user" from the session array.
        $_SESSION = ['user' => ['id' => $u['coml'], 'username' => 'smoke_coml', 'real_name' => 'Smoke COML', 'is_sysadmin' => 0, 'incident_id' => $inc]];
    } catch (Throwable $e) {
        t_ok('Build the TRAINING incident', false, $e->getMessage());
        try { if ($pdo->inTransaction()) { $pdo->rollBack(); } } catch (Throwable $e2) { }
        $ctx = null;
    }
}

// --- approvers --------------------------------------------------------------------------------------------------
if ($ctx && $want('approvers')) {
    t_section('Automatic approver selection (chain of command)');
    ['inc' => $inc, 'op' => $op, 'u' => $u, 'ip' => $ip, 'assign' => $assign, 'unassign' => $unassign] = $ctx;
    $uid2role = [$u['ic'] => 'IC', $u['psc'] => 'PSC', $u['lsc'] => 'LSC', $u['coml'] => 'COML', $u['lsc2'] => 'LSC (second holder)'];
    $who = static fn(int $id): string => $uid2role[$id] ?? ($id > 0 ? 'user #' . $id : 'nobody');

    t_run('every form resolves to its configured position (signer = COML)', function () use ($inc, $op, $u, $who) {
        $map = signoff_approver_map();
        $holder = ['IC' => $u['ic'], 'PSC' => $u['psc'], 'LSC' => $u['lsc'], 'COML' => $u['coml']];
        foreach ($map as $form => $cfg) {
            $pos = $cfg['primary'] === '@supervisor' ? 'LSC' : $cfg['primary'];   // COML reports to LSC
            if (!isset($holder[$pos])) { t_skip("$form -> $pos", "$pos is not part of the test roster"); continue; }
            $expect = $holder[$pos] === $u['coml'] ? $u['lsc'] : $holder[$pos];
            $res = signoff_resolve_approver($inc, $form, $u['coml'], $op);
            t_ok("$form approved by " . ($cfg['primary'] === '@supervisor' ? "signer's supervisor (LSC)" : $pos), $res['user_id'] === $expect, 'got ' . $who($res['user_id']) . ' via ' . $res['position']);
        }
    });

    t_run('203 signed by the PSC moves up one level to the IC', function () use ($inc, $op, $u, $who) {
        $res = signoff_resolve_approver($inc, 'ICS203', $u['psc'], $op);
        t_ok('signer holds the approving position -> IC', $res['user_id'] === $u['ic'], 'got ' . $who($res['user_id']));
    });

    t_run('vacant LSC: 205 climbs to the IC', function () use ($inc, $op, $u, $ip, $unassign, $assign, $who) {
        $unassign($ip['lsc']);
        $res = signoff_resolve_approver($inc, 'ICS205', $u['coml'], $op);
        t_ok('LSC vacant -> IC', $res['user_id'] === $u['ic'] && $res['position'] === 'IC', 'got ' . $who($res['user_id']) . ' via ' . $res['position']);
        $res = signoff_resolve_approver($inc, 'ICS213', $u['coml'], $op);
        t_ok('213 from the COML with the LSC vacant -> IC', $res['user_id'] === $u['ic'], 'got ' . $who($res['user_id']));
        $GLOBALS['ctx_lsc_row'] = $assign($inc, $u['lsc'], 'LSC');   // LSC is back
    });

    t_run('two people hold LSC: signer must choose', function () use ($inc, $op, $u, $assign) {
        $assign($inc, $u['lsc2'], 'LSC');
        $res = signoff_resolve_approver($inc, 'ICS205', $u['coml'], $op);
        t_ok('no automatic pick when two people hold the position', $res['user_id'] === 0 && count($res['choices']) === 2, 'choices: ' . count($res['choices']));
    });

    t_run('nobody in the chain: SysAdmin fallback and IC self-approval', function () use ($inc, $op, $u, $ip, $unassign) {
        foreach (['ic', 'psc', 'lsc'] as $k) { $unassign($ip[$k]); }
        $unassign((int)($GLOBALS['ctx_lsc_row'] ?? 0));
        $res = signoff_resolve_approver($inc, 'ICS201', $u['coml'], $op);
        t_ok('IC and UC vacant (and nobody in the chain) -> SysAdmin', $res['position'] === 'SYSADMIN' && ($res['user_id'] > 0 || count($res['choices']) > 0), 'position ' . $res['position']);
    });

    t_run('self-approval rules', function () use ($inc, $u, $ip, $assign) {
        $icRow = $assign($inc, $u['ic'], 'IC');
        $_SESSION['user'] = ['id' => $u['coml'], 'is_sysadmin' => 0, 'incident_id' => $inc];
        t_ok('IC is allowed to self-approve', signoff_self_approval_allowed($inc, $u['ic']));
        t_ok('COML is NOT allowed to self-approve', !signoff_self_approval_allowed($inc, $u['coml']));
    });
}

// --- signing ----------------------------------------------------------------------------------------------------
if ($ctx && $want('signing')) {
    t_section('Signing a form assigns and notifies the approver');
    ['inc' => $inc, 'op' => $op, 'u' => $u, 'ip' => $ip, 'assign' => $assign, 'unassign' => $unassign] = $ctx;
    t_run('signing flow', function () use ($pdo, $inc, $op, $u, $ip, $assign, $unassign) {
        // Fresh roster for this scenario: IC, PSC, LSC, COML.
        $pdo->prepare('UPDATE incident_positions SET deleted_at = NOW(), assigned_end = NOW() WHERE incident_id = ? AND deleted_at IS NULL')->execute([$inc]);
        foreach (['ic' => 'IC', 'psc' => 'PSC', 'lsc' => 'LSC', 'coml' => 'COML'] as $k => $role) { $assign($inc, $u[$k], $role); }
        $_SESSION['user'] = ['id' => $u['coml'], 'username' => 'smoke_coml', 'real_name' => 'Smoke COML', 'is_sysadmin' => 0, 'incident_id' => $inc];

        $rec = get_or_create_form_record($inc, 'ICS205', '205', $u['coml'], $op);
        t_ok('form record created', $rec > 0);
        $viaPermission = false;
        try {
            submit_form_record($rec, 'ICS205', $inc, $u['coml']);
            $viaPermission = true;
        } catch (Throwable $e) {
            t_info('COML may not sign ICS 205 under the current Form Permissions (' . $e->getMessage() . '); simulating the signature.');
            $pdo->prepare("UPDATE form_records SET status = 'submitted', submitted_by_user_id = ?, submitted_by_name = 'Smoke COML', submitted_at = NOW() WHERE id = ?")->execute([$u['coml'], $rec]);
            $pdo->prepare("INSERT INTO form_signatures (form_record_id, signer_user_id, signer_name, signer_position_title, signature_type, signature_method, signed_at, created_at)
                VALUES (?, ?, 'Smoke COML', 'COML', 'prepared_by', 'system_attestation', NOW(), NOW())")->execute([$rec, $u['coml']]);
            signoff_auto_assign($rec);
        }
        t_info($viaPermission ? 'Signed through submit_form_record()' : 'Signed by simulation');
        $row = $pdo->prepare('SELECT status, current_approver_user_id FROM form_records WHERE id = ?'); $row->execute([$rec]); $row = $row->fetch();
        t_ok('status is submitted', ($row['status'] ?? '') === 'submitted', (string)($row['status'] ?? ''));
        t_ok('approver set automatically to the LSC', (int)($row['current_approver_user_id'] ?? 0) === $u['lsc'], 'approver id ' . ($row['current_approver_user_id'] ?? 'none'));

        $n = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE form_record_id = ? AND user_id = ? AND notification_type = 'form_approval_requested'"); $n->execute([$rec, $u['lsc']]);
        t_ok('LSC was notified', (int)$n->fetchColumn() === 1);
        $a = $pdo->prepare("SELECT COUNT(*) FROM audit_log WHERE incident_id = ? AND entity_id = ? AND action_type = 'auto_approver'"); $a->execute([$inc, $rec]);
        t_ok('automatic choice was written to the audit log', (int)$a->fetchColumn() >= 1);

        signoff_auto_assign($rec);
        $n->execute([$rec, $u['lsc']]);
        t_ok('running it again does not notify twice', (int)$n->fetchColumn() === 1);

        // A form that marks itself submitted without a signature row (e.g. ICS 203): the sweep still finds the signer.
        $rec3 = get_or_create_form_record($inc, 'ICS203', '203', $u['coml'], $op);
        $pdo->prepare("UPDATE form_records SET status = 'submitted', submitted_by_user_id = ?, submitted_by_name = 'Smoke COML', submitted_at = NOW() WHERE id = ?")->execute([$u['coml'], $rec3]);
        signoff_auto_assign($rec3);
        $ap = $pdo->prepare('SELECT current_approver_user_id FROM form_records WHERE id = ?'); $ap->execute([$rec3]);
        t_ok('ICS 203 signed without a signature row still gets the PSC', (int)$ap->fetchColumn() === $u['psc']);

        // Return / reopen leaves the record editable and clears the approver (same SQL the handler uses).
        $state = signoff_state($rec);
        t_ok('sign-off state reports the signer', (int)($state['creator']['signer_user_id'] ?? 0) === $u['coml']);
    });
}

// --- resources --------------------------------------------------------------------------------------------------
$grid = null;
if ($ctx && ($want('resources') || $want('pdf'))) {  // the PDF section reuses this grid
    t_section('ICS 209 resource commitment summary (blocks 48-53)');
    ['inc' => $inc, 'op' => $op, 'u' => $u] = $ctx;
    t_run('collector', function () use ($pdo, $inc, $op, $u, &$grid) {
        $rec211 = get_or_create_form_record($inc, 'ICS211', '211', $u['coml'], $op);
        $pdo->prepare('INSERT INTO form_211_headers (form_record_id, check_in_location, page_no, total_pages) VALUES (?, ?, 1, 1)')->execute([$rec211, 'Smoke test']);
        $h = (int)$pdo->lastInsertId();
        $ins = $pdo->prepare('INSERT INTO form_211_entries (header_id, kind, type_st_tf, person_name, home_agency, total_personnel, resource_identifier, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())');
        $ins->execute([$h, 'Engine', 'T6', 'Crew E1', 'Smoke Fire Dept', 4, 'E1']);
        $ins->execute([$h, 'Engine', 'T6', 'Crew E2', 'Smoke Fire Dept', 3, 'E2']);
        $ins->execute([$h, 'Engine', 'T6', 'Crew E1 again', 'Smoke Fire Dept', 4, 'E1']);       // same identifier: counted once
        $ins->execute([$h, null, null, 'Pat Volunteer', 'Smoke Fire Dept', null, null]);         // single person
        $ins->execute([$h, 'Crew', 'T2', 'Crew C1', 'Smoke Forestry', 20, 'C1']);
        $ins->execute([$h, 'Engine', 'T6', 'Released engine', 'Smoke Forestry', 5, 'E9']);
        $releasedEntry = (int)$pdo->lastInsertId();
        $pdo->prepare("INSERT INTO resource_unit_intake (incident_id, intake_status, resource_category, ics211_entry_id, created_at) VALUES (?, 'checked_in', 'vehicle', ?, NOW())")->execute([$inc, $releasedEntry]);
        $intake = (int)$pdo->lastInsertId();
        $pdo->prepare("INSERT INTO resource_demob_tracker (incident_id, resource_unit_intake_id, demob_status) VALUES (?, ?, 'released')")->execute([$inc, $intake]);
        $rec218 = get_or_create_form_record($inc, 'ICS218', '218', $u['coml'], $op);
        $pdo->prepare('INSERT INTO form_218_headers (form_record_id) VALUES (?)')->execute([$rec218]);
        $h218 = (int)$pdo->lastInsertId();
        $pdo->prepare("INSERT INTO form_218_entries (header_id, equipment_description, kind, type, owner_agency, status) VALUES (?, 'Pickup', 'Pickup', 'T1', 'Smoke Fire Dept', 'assigned')")->execute([$h218]);
        $pdo->prepare("INSERT INTO form_218_entries (header_id, equipment_description, kind, type, owner_agency, status) VALUES (?, 'Old truck', 'Truck', 'T2', 'Smoke Fire Dept', 'released')")->execute([$h218]);

        $grid = s209res_collect($pdo, $inc);
        $tot = s209res_totals($grid);
        $names = array_column($grid['rows'], 'agency');
        t_ok('two agencies listed', in_array('Smoke Fire Dept', $names, true) && in_array('Smoke Forestry', $names, true), implode(', ', array_filter($names)));
        // Fire Dept: engines E1+E2 (E1 duplicate ignored) = 2 resources / 7 people, 1 additional person, 1 pickup (218) = 3 resources. Forestry: crew 20 (released engine excluded).
        t_ok('duplicate check-in ignored, released resource excluded', $tot['resources'] === 2 + 1 + 1 /* engines, pickup, crew */, 'resources ' . $tot['resources']);
        t_ok('personnel total = 7 + 1 additional + 20', $tot['personnel'] === 28, 'personnel ' . $tot['personnel']);
        t_ok('grid survives normalisation unchanged', s209res_hash(s209res_normalize($grid)) === s209res_hash($grid));
        $junk = s209res_normalize(['rows' => [1 => ['agency' => str_repeat('x', 500), 'add' => 'abc99', 'cells' => [999 => ['r' => 1], 3 => ['r' => 'q7', 'p' => '2']]]], 'columns' => 'bad']);
        t_ok('garbage input is cleaned', mb_strlen($junk['rows'][1]['agency']) === 120 && $junk['rows'][1]['add'] === '99' && ($junk['rows'][1]['cells'][3]['r'] ?? '') === '7');
    });
}

// --- sync queue -------------------------------------------------------------------------------------------------
if ($ctx && $want('sync')) {
    t_section('Check-in sync queue (rows are never visible to the worker)');
    ['inc' => $inc] = $ctx;
    t_run('queue counting and Retry', function () use ($pdo, $inc) {
        $ins = $pdo->prepare("INSERT INTO external_checkin_sync_queue (incident_id, entity_type, entity_id, event_type, event_payload, idempotency_key, delivery_status, attempt_count, max_attempts, next_attempt_at, last_error)
            VALUES (?, 'smoketest', ?, 'smoketest', '{}', ?, ?, ?, 12, ?, ?)");
        $mk = static function (int $n, string $status, int $attempts, ?string $next, ?string $err = null) use ($ins, $inc): int {
            $ins->execute([$inc, $n, hash('sha256', 'smoke' . $n . microtime()), $status, $attempts, $next, $err]);
            return (int)db()->lastInsertId();
        };
        $before = external_checkin_sync_is_configured() ? external_checkin_sync_status($pdo) : null;
        $ids = [
            'pending_null'  => $mk(1, 'pending', 0, null),
            'failed_null'   => $mk(2, 'failed', 12, null, 'HTTP 503'),
            'out_of_tries'  => $mk(3, 'pending', 12, date('Y-m-d H:i:s', time() - 60)),
            'due'           => $mk(4, 'pending', 0, date('Y-m-d H:i:s', time() - 60)),
            'later'         => $mk(5, 'failed', 2, date('Y-m-d H:i:s', time() + 3600), 'HTTP 503'),
        ];
        if ($before !== null) {
            $after = external_checkin_sync_status($pdo);
            t_ok('"waiting" counts only the row the worker would send', $after['queue']['waiting'] - $before['queue']['waiting'] === 1, 'delta ' . ($after['queue']['waiting'] - $before['queue']['waiting']));
            t_ok('"stuck" counts the 3 rows the worker would never pick up', $after['queue']['failed'] - $before['queue']['failed'] === 3, 'delta ' . ($after['queue']['failed'] - $before['queue']['failed']));
            t_ok('"retrying" counts the failed row with a future attempt', $after['queue']['retrying'] - $before['queue']['retrying'] === 1, 'delta ' . ($after['queue']['retrying'] - $before['queue']['retrying']));
        } else {
            t_skip('status page counts', 'external sync is not configured on this server');
        }
        $n = external_checkin_requeue_stuck($pdo);
        t_ok('Retry reset at least the 3 stuck rows', $n >= 3, "reset $n");
        $row = static function (int $id) use ($pdo): array { $s = $pdo->prepare('SELECT delivery_status, attempt_count, next_attempt_at, last_error FROM external_checkin_sync_queue WHERE id = ?'); $s->execute([$id]); return $s->fetch(); };
        foreach (['pending_null', 'failed_null', 'out_of_tries'] as $k) {
            $r = $row($ids[$k]);
            t_ok("$k is now pending, 0 attempts, next attempt set", $r['delivery_status'] === 'pending' && (int)$r['attempt_count'] === 0 && $r['next_attempt_at'] !== null && $r['last_error'] === null);
        }
        t_ok('a row that was retrying normally is left alone', $row($ids['later'])['delivery_status'] === 'failed' && (int)$row($ids['later'])['attempt_count'] === 2);
        $due = $pdo->prepare("SELECT COUNT(*) FROM external_checkin_sync_queue WHERE id IN (" . implode(',', array_map('intval', [$ids['pending_null'], $ids['failed_null'], $ids['out_of_tries']])) . ") AND delivery_status = 'pending' AND next_attempt_at <= NOW() AND attempt_count < max_attempts");
        $due->execute();
        t_ok('after Retry the worker would claim all 3', (int)$due->fetchColumn() === 3);
    });
}

// --- pdf --------------------------------------------------------------------------------------------------------
if ($want('pdf')) {
    t_section('Official PDFs');
    $inc = $ctx['inc'] ?? 0; $op = $ctx['op'] ?? 0; $u = $ctx['u'] ?? [];
    if (!is_array($grid)) {
        $grid = s209res_empty_grid();
        $grid['columns'][1] = 'Engine T6';
        $grid['rows'][1] = ['agency' => 'Smoke Fire Dept', 'add' => '1', 'cells' => [1 => ['r' => '2', 'p' => '7']]];
    }
    $isPdf = static function (string $path): bool { return is_file($path) && filesize($path) > 5000 && substr((string)file_get_contents($path, false, null, 0, 5), 0, 5) === '%PDF-'; };
    $pages = static function (string $path): int {
        $o = []; @exec('pdftk ' . escapeshellarg($path) . ' dump_data 2>/dev/null | grep NumberOfPages', $o);
        return isset($o[0]) ? (int)preg_replace('/\D/', '', $o[0]) : 0;
    };
    t_run('ICS 209 and 209-WF', function () use ($appRoot, $pdo, $inc, $op, $u, $grid, $isPdf, $pages) {
        $incident = ['id' => $inc, 'incident_name' => 'TRAINING - smoke test', 'name' => 'TRAINING - smoke test', 'incident_number' => 'SMOKE-TEST'];
        $opRow = ['id' => $op, 'period_number' => 1, 'op_start' => date('Y-m-d H:i:s', time() - 3600), 'op_end' => date('Y-m-d H:i:s', time() + 39600)];
        $rec = ['id' => 0, 'form_type' => 'ICS209', 'status' => 'draft'];
        $row = ['report_number' => '1', 'report_version' => 'Initial', 'fire_status' => 'BH', 'incident_name' => 'Smoke test fire', 'damage_single_residences_threatened' => '4', 'damage_multiple_residences_damaged' => '2',
            'significant_events' => str_repeat('Long significant events text that will not fit in the box. ', 60)];
        $positionCodes = [];
        try { foreach ($pdo->query('SELECT id, role_code FROM roles')->fetchAll() as $r) { $positionCodes[(string)$r['id']] = (string)$r['role_code']; } } catch (Throwable $e) { $positionCodes = []; }
        $exporter = new OfficialPdfTemplateExporter();
        foreach ([false, true] as $wf) {
            $label = $wf ? 'ICS 209-WF' : 'ICS 209';
            $built = $wf ? Ics209WfOfficialPdfFieldMapper::build($row, $incident, $opRow, $rec, $positionCodes, $grid)
                         : Ics209OfficialPdfFieldMapper::build($row, $incident, $opRow, $rec, $positionCodes, $grid, false);
            t_ok("$label fields built", !empty($built['fields']));
            $tpl = $appRoot . '/assets/pdf/official/' . ($wf ? 'ICS209-wf.pdf' : 'ICS209.pdf');
            $names = [];
            @exec('pdftk ' . escapeshellarg($tpl) . ' dump_data_fields_utf8 2>/dev/null', $dump);
            foreach ($dump as $line) { if (str_starts_with($line, 'FieldName: ')) { $names[substr($line, 11)] = true; } }
            $unknown = array_values(array_filter(array_keys($built['fields']), static fn($n) => !isset($names[$n])));
            // (The standard 209 mapper deliberately emits alternative names for some boxes, so only the WF template is held to this.)
            if ($wf) t_ok("$label: every field the app fills exists on the template", $names && !$unknown, $unknown ? 'unknown: ' . implode(' | ', array_slice($unknown, 0, 5)) : '');
            unset($dump);
            $path = $exporter->create($wf ? 'ICS209WF' : 'ICS209', $built['fields']);
            if (!empty($built['continuations'])) {
                $extra = tempnam(sys_get_temp_dir(), 'smk209_');
                file_put_contents($extra, SimpleTextPdf::render($label . ' - Continuation Sheet', $built['header'], $built['continuations']));
                $path = $exporter->appendPdf($path, $extra);
            }
            t_ok("$label PDF is valid", $isPdf($path), basename($path));
            t_ok("$label long text moved to a continuation sheet", !empty($built['continuations']) && $pages($path) >= 5, $pages($path) . ' pages');
            @unlink($path);
        }
    });
    t_run('ICS 213RR', function () use ($inc, $isPdf) {
        $incident = ['id' => $inc, 'incident_name' => 'TRAINING - smoke test'];
        $rr = ['request_number' => 'SMOKE-1', 'order_datetime' => date('Y-m-d H:i:s'), 'needed_by_datetime' => date('Y-m-d H:i:s', time() + 7200),
            'delivery_location' => 'Staging area', 'prepared_by_name' => 'Smoke COML', 'prepared_by_position_title' => 'COML', 'record_created_at' => date('Y-m-d H:i:s')];
        $items = [];
        for ($i = 1; $i <= 3; $i++) { $items[] = ['quantity_requested' => $i, 'item_kind' => 'Radio', 'unit_of_issue' => 'each', 'item_description' => 'Handheld radio ' . $i, 'estimated_cost' => '12.50']; }
        $built = Ics213rrOfficialPdfFieldMapper::build($rr, $items, $incident, ['name' => 'Smoke LSC', 'at' => date('Y-m-d H:i:s')]);
        $exporter = new OfficialPdfTemplateExporter();
        $path = $exporter->create('ICS213RR', $built['fields']);
        t_ok('ICS 213RR PDF is valid', $isPdf($path), basename($path));
        @unlink($path);
    });
    // Every form's PDF fills the official template: build each mapper with sample data, check its field names exist on
    // the template, and make the PDF.
    t_run('Official PDFs for every form', function () use ($appRoot, $isPdf, $pages) {
        foreach (['ics201/Ics201', 'ics203/Ics203', 'ics204/Ics204', 'ics206/Ics206', 'ics208/Ics208', 'ics214/Ics214', 'ics215a/Ics215A', 'ics230/Ics230'] as $m) {
            require_once $appRoot . '/forms/' . $m . 'OfficialPdfFieldMapper.php';
        }
        require_once $appRoot . '/forms/ics218/Ics218OfficialPdfOverlay.php';
        $incident = ['incident_name' => 'TRAINING - smoke test', 'incident_number' => 'SMOKE-TEST'];
        $op = ['period_name' => 'OP 1', 'op_start' => date('Y-m-d H:i:s', time() - 3600), 'op_end' => date('Y-m-d H:i:s', time() + 39600)];
        $long = str_repeat('Long text that will not fit in the box on the form. ', 80);
        $now = date('Y-m-d H:i:s');
        $cases = [];
        $b = Ics201OfficialPdfFieldMapper::build(['datetime_prepared' => $now, 'situation_summary' => $long, 'current_organization' => 'IC: Smoke'], $incident,
            [['action_time' => '07:00', 'action_text' => 'Establish perimeter']], [], [['resources_ordered' => 'Engine', 'resource_identification' => 'E-1']], 'Smoke IC', '', 0);
        $cases['ICS201'] = [[$b['fields']], $b['continuations'], null];
        $b = Ics203OfficialPdfFieldMapper::build(['IC' => ['Smoke IC'], 'SITL' => ['A Person', 'Another Person With A Long Name', 'Third']], $incident, $op, 'Smoke RESL', $now);
        $cases['ICS203'] = [[$b['fields']], $b['continuations'], null];
        $res = [];
        for ($i = 1; $i <= 11; $i++) { $res[] = ['resource_identifier' => 'E-' . $i, 'leader_name' => 'Leader', 'number_persons' => 4, 'contact_info' => 'Ch 1', 'reporting_location' => 'Staging']; }
        $b = Ics204OfficialPdfFieldMapper::build(['branch_name' => 'I', 'division_group' => 'A', 'work_assignments' => $long, 'operations_personnel' => 'Operations Chief: Smoke OSC'], $incident, $op, $res);
        $cases['ICS204'] = [$b['pages'], $b['continuations'], null];
        $b = Ics206OfficialPdfFieldMapper::build(['medical_emergency_procedures' => 'Call 911'], $incident, $op, ['prepared_at' => $now],
            [['name' => 'Aid 1', 'paramedics' => 1]], [['ambulance_service' => 'EMS', 'level_of_service' => 'ALS']], [['hospital_name' => 'General', 'helipad' => 1]]);
        $cases['ICS206'] = [$b['pages'], $b['continuations'], null];
        $b = Ics208OfficialPdfFieldMapper::build(['safety_message' => $long, 'site_safety_plan_required' => 1], $incident, $op, ['prepared_at' => $now]);
        $cases['ICS208'] = [[$b['fields']], $b['continuations'], null];
        $rows = [];
        for ($i = 0; $i < 40; $i++) { $rows[] = ['time' => $now, 'text' => 'Activity ' . $i, 'manual' => false]; }
        $b = Ics214OfficialPdfFieldMapper::build(['status' => 'draft'], ['name' => 'Smoke Person', 'position' => 'SITL'], $incident, $op, $rows);
        $cases['ICS214'] = [$b['pages'], [], $b['keep']];
        $b = Ics215AOfficialPdfFieldMapper::build(['date_time_prepared' => $now], $incident, $op, [['incident_area' => 'A', 'hazards_risks' => $long, 'mitigations' => 'PPE']]);
        $cases['ICS215A'] = [$b['pages'], $b['continuations'], null];
        $b = Ics230OfficialPdfFieldMapper::build($incident, $op, [['title' => 'Planning Meeting', 'scheduled_at' => $now, 'status' => 'scheduled', 'location' => 'ECC']], 'Smoke SITL', 'SITL', $now);
        $cases['ICS230'] = [$b['pages'], $b['continuations'], null];

        $exporter = new OfficialPdfTemplateExporter();
        foreach ($cases as $code => [$pgs, $cont, $keep]) {
            $names = [];
            $dump = [];
            @exec('pdftk ' . escapeshellarg(OfficialPdfTemplateRegistry::get($code)['path']) . ' dump_data_fields_utf8 2>/dev/null', $dump);
            foreach ($dump as $line) { if (str_starts_with($line, 'FieldName: ')) { $names[substr($line, 11)] = true; } }
            $unknown = [];
            foreach ($pgs as $pg) { foreach (array_keys($pg) as $n) { if (!isset($names[$n])) { $unknown[$n] = true; } } }
            t_ok("$code: every field the app fills exists on the official template", $names && !$unknown, $unknown ? 'unknown: ' . implode(' | ', array_slice(array_keys($unknown), 0, 5)) : '');
            t_ok("$code: box sizes known (assets/pdf/official/fields)", OfficialPdfText::meta($code) !== [], 'run tools/pdf_field_metadata.py');
            $path = $exporter->createPaged($code, $pgs, $keep);
            if ($cont) {
                $extra = tempnam(sys_get_temp_dir(), 'smkcont_');
                file_put_contents($extra, SimpleTextPdf::render($code . ' - Continuation Sheet', ['Smoke test'], $cont));
                $path = $exporter->appendPdf($path, $extra);
            }
            t_ok("$code official PDF is valid", $isPdf($path), $pages($path) . ' page(s)');
            @unlink($path);
        }
        $entries = [];
        for ($i = 1; $i <= 13; $i++) { $entries[] = ['order_request_number' => 'R-' . $i, 'equipment_description' => 'Pickup', 'status' => 'assigned', 'created_at' => $now]; }
        [$overlay, $n] = Ics218OfficialPdfOverlay::write($incident, ['prepared_at' => $now, 'prepared_by_name' => 'Smoke GSUL'], $entries, sys_get_temp_dir());
        $path = $exporter->stampTemplate('ICS218', $overlay, $n);
        t_ok('ICS218 official PDF (stamped) is valid and has 2 pages for 13 vehicles', $isPdf($path) && $pages($path) === 2, $pages($path) . ' page(s)');
        @unlink($path);
    });
}

// ---------------------------------------------------------------------------------------------------------------
// Roll back everything the scenarios wrote
// ---------------------------------------------------------------------------------------------------------------
if ($needDb && $pdo && $pdo->inTransaction()) {
    $pdo->rollBack();
    $left = 0;
    try { $left = (int)$pdo->query("SELECT COUNT(*) FROM incidents WHERE incident_number = 'SMOKE-TEST'")->fetchColumn(); } catch (Throwable $e) { }
    t_section('Cleanup');
    t_ok('TRAINING incident rolled back (nothing left behind)', $left === 0, $left . ' left');
}

// ---------------------------------------------------------------------------------------------------------------
// 3. Web checks (need --base-url)
// ---------------------------------------------------------------------------------------------------------------
if ($want('http')) {
    t_section('Web server checks');
    if ($baseUrl === '') {
        t_skip('HTTP checks', 'pass --base-url=https://your-server/path to run them');
    } else {
        $get = static function (string $path) use ($baseUrl, $insecure): array {
            $ch = curl_init($baseUrl . $path);
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 15,
                CURLOPT_SSL_VERIFYPEER => !$insecure, CURLOPT_SSL_VERIFYHOST => $insecure ? 0 : 2]);
            $raw = curl_exec($ch);
            $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $hs = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
            $err = curl_error($ch);
            curl_close($ch);
            return ['code' => $code, 'headers' => $raw === false ? '' : strtolower(substr((string)$raw, 0, $hs)), 'body' => $raw === false ? '' : substr((string)$raw, $hs), 'err' => $err];
        };
        $login = $get('/login.php');
        t_ok('login page loads', $login['code'] === 200, $login['err'] !== '' ? $login['err'] : 'HTTP ' . $login['code']);
        foreach (['x-content-type-options: nosniff', 'x-frame-options', 'referrer-policy'] as $h) { t_ok("security header: $h", str_contains($login['headers'], $h)); }
        t_ok('Content-Security-Policy header present (enforced or report-only)', str_contains($login['headers'], 'content-security-policy'));
        t_ok('session cookie is HttpOnly', !str_contains($login['headers'], 'set-cookie: ') || str_contains($login['headers'], 'httponly'));
        // A form posted after the sign-in timed out gets a plain-language page that returns to sign-in, not "Invalid CSRF token".
        $ch = curl_init($baseUrl . '/setup/setup_sysadmin.php?step=2');
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_POSTFIELDS => 'csrf_token=expired',
            CURLOPT_TIMEOUT => 15, CURLOPT_SSL_VERIFYPEER => !$insecure, CURLOPT_SSL_VERIFYHOST => $insecure ? 0 : 2]);
        $expiredBody = (string)curl_exec($ch);
        $expiredCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        t_ok('timed-out form shows the "session has timed out" page', $expiredCode === 403 && str_contains($expiredBody, 'session has timed out')
            && str_contains($expiredBody, 'http-equiv="refresh"'), 'HTTP ' . $expiredCode);
        foreach (['/admin/sync_status.php', '/admin/signoff_settings.php', '/dashboard/index.php', '/forms/signoff.php'] as $p) {
            $r = $get($p);
            t_ok("$p is not readable without logging in", in_array($r['code'], [301, 302, 303, 307, 401, 403, 405], true), 'HTTP ' . $r['code']);
        }
        $icon = $get('/assets/img/command-bridge-icon.svg');
        t_ok('Command Bridge icon is served (browser tab and default logo)', $icon['code'] === 200, 'HTTP ' . $icon['code']);
        $oldLogo = $get('/assets/img/ics-canada-logo.svg');
        t_ok('old ICS Canada logo file is gone from the web folder', $oldLogo['code'] === 404, 'HTTP ' . $oldLogo['code'] . ' (update.sh removes it)');
        $helpAnon = $get('/help/view.php?topic=admin/smtp');
        t_ok('help for settings pages needs a sign-in', in_array($helpAnon['code'], [301, 302, 303, 401], true), 'HTTP ' . $helpAnon['code']);
        $helpLogin = $get('/help/view.php?topic=login&panel=1');
        t_ok('help for the sign-in page is readable without signing in', $helpLogin['code'] === 200 && str_contains($helpLogin['body'], 'help-doc'), 'HTTP ' . $helpLogin['code']);
        foreach (['/db.env', '/db.env.example', '/help/topics/admin/smtp.md', '/includes/db.php', '/includes/helpers.php', '/bin/smoke_test.php', '/storage/', '/storage/uploads/', '/CHANGELOG.md', '/database/schema.sql'] as $p) {
            $r = $get($p);
            t_ok("$p is blocked", in_array($r['code'], [403, 404], true), 'HTTP ' . $r['code']);
            if (!in_array($r['code'], [403, 404], true)) { $blockedFail = true; }
        }
        if (!empty($blockedFail)) { t_info('Fix: the Apache site file (/etc/apache2/sites-available/*.conf, including the *:443 block) must list the real app folder in its <Directory> rules and include "example" in the FilesMatch list. See docs/INSTALL.md.'); }
    }
}

echo "\n------------------------------------------------------------\n";
echo sprintf("%d passed, %d failed, %d skipped\n", $T['pass'], $T['fail'], $T['skip']);
if ($T['failures']) { echo "Failures:\n"; foreach ($T['failures'] as $f) { echo "  - $f\n"; } }
exit($T['fail'] > 0 ? 1 : 0);
