<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/security.php';
require_csrf_for_post();

require_once dirname(__DIR__) . '/includes/layout.php';

$step = (int)($_GET['step'] ?? 1);
if (!in_array($step, [1, 2, 3, 4], true)) {
    $step = 1;
}

if (tsc_admin_exists() && empty(current_user()['is_tsc_admin'])) {
    header('Location: ' . app_url('/login.php'));
    exit;
}

// The installer (or bin/create_admin.php) creates the first administrator. Step 1 is only
// available while no administrator exists; afterwards it skips ahead to the incident step.
if ($step === 1 && tsc_admin_exists()) {
    header('Location: ' . app_url('/setup/setup_tsc.php?step=2'));
    exit;
}

$setupNow = current_datetime_local_value();
$incidentForm = [
    'name' => '',
    'incident_number' => '',
    'incident_type' => '',
    'status' => 'Active',
    'start_datetime' => $setupNow,
    'op_start' => $setupNow,
    'op_end' => date('Y-m-d H:i', strtotime($setupNow . ' +12 hours')),
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $pdo = db();

    if ($step === 1) {
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';
        $passwordConfirm = $_POST['password_confirm'] ?? '';
        $realName = trim($_POST['real_name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $cellPhone = trim($_POST['cell_phone'] ?? '');

        if ($password !== $passwordConfirm) {
            flash('error', 'The Technical Services Coordinator passwords did not match. Please enter them again.');
            header('Location: ' . app_url('/setup/setup_tsc.php?step=1'));
            exit;
        }

        $stmt = $pdo->prepare("
            INSERT INTO users (username, password_hash, real_name, email, is_tsc_admin, created_at, updated_at)
            VALUES (?, ?, ?, ?, 1, NOW(), NOW())
        ");
        $stmt->execute([$username, password_hash($password, PASSWORD_DEFAULT), $realName, $email ?: null]);
        $userId = (int)$pdo->lastInsertId();

        login_user([
            'id' => $userId,
            'username' => $username,
            'real_name' => $realName,
            'email' => $email,
            'incident_id' => null,
            'is_tsc_admin' => 1,
            'role_code' => 'TSC',
        ]);

        save_contact_value($userId, 'email', $email);
        save_contact_value($userId, 'cell_phone', $cellPhone);

        flash('success', 'Technical Services Coordinator administrator created.');
        header('Location: ' . app_url('/setup/setup_tsc.php?step=2'));
        exit;
    }

    if ($step === 2) {
        $name = trim($_POST['name'] ?? '');
        $number = trim($_POST['incident_number'] ?? '');
        $type = trim($_POST['incident_type'] ?? '');
        $status = trim($_POST['status'] ?? 'Active');
        $start = trim($_POST['start_datetime'] ?? '');
        $opStartInput = trim($_POST['op_start'] ?? '');
        $opEndInput = trim($_POST['op_end'] ?? '');

        $incidentForm = [
            'name' => $name,
            'incident_number' => $number,
            'incident_type' => $type,
            'status' => $status ?: 'Active',
            'start_datetime' => $start,
            'op_start' => $opStartInput,
            'op_end' => $opEndInput,
        ];

        $incidentStartTimestamp = $start !== '' ? strtotime($start) : false;
        $opStartTimestamp = $opStartInput !== '' ? strtotime($opStartInput) : false;
        $opEndTimestamp = $opEndInput !== '' ? strtotime($opEndInput) : false;

        if ($name === '') {
            flash('error', 'Enter an incident name.');
        } elseif ($incidentStartTimestamp === false || $opStartTimestamp === false || $opEndTimestamp === false) {
            flash('error', 'Enter valid incident and operational period date/time values.');
        } elseif ($opStartTimestamp >= $opEndTimestamp) {
            flash('error', 'The initial operational period start date/time must be before its end date/time.');
        } else {
            $incidentStart = date('Y-m-d H:i:s', $incidentStartTimestamp);
            $opStart = date('Y-m-d H:i:s', $opStartTimestamp);
            $opEnd = date('Y-m-d H:i:s', $opEndTimestamp);

            $stmt = $pdo->prepare("
                INSERT INTO incidents (incident_name, incident_number, incident_type, status, started_at, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, NOW(), NOW())
            ");
            $stmt->execute([$name, $number ?: null, $type ?: null, $status ?: 'Active', $incidentStart]);
            $incidentId = (int)$pdo->lastInsertId();

            $stmt = $pdo->query("SELECT id FROM users WHERE is_tsc_admin = 1 AND deleted_at IS NULL ORDER BY id ASC LIMIT 1");
            $tscUserId = (int)(($stmt->fetch())['id'] ?? 0);

            $stmt = $pdo->query("SELECT id FROM roles WHERE role_code = 'TSC' LIMIT 1");
            $tscRoleId = (int)(($stmt->fetch())['id'] ?? 0);

            if ($tscUserId > 0 && $tscRoleId > 0) {
                sync_user_roles($incidentId, $tscUserId, [$tscRoleId]);
            }

            // Create Operational Period 1 before creating any ICS forms/check-in records.
            // ICS 211 and ICS 205A require an operational period.
            $opCheckStmt = $pdo->prepare("
                SELECT id
                FROM operational_periods
                WHERE incident_id = ?
                  AND period_number = 1
                LIMIT 1
            ");
            $opCheckStmt->execute([$incidentId]);

            if (!$opCheckStmt->fetchColumn()) {
                // Activate the initial Operational Period only when the current server time
                // falls within the start and end date/time entered during setup.
                $initialOperationalPeriodStatus = (
                    strtotime($opStart) <= time()
                    && time() <= strtotime($opEnd)
                ) ? 'active' : 'draft';

                $opInsertStmt = $pdo->prepare("
                    INSERT INTO operational_periods (
                        incident_id,
                        period_number,
                        period_name,
                        op_start,
                        op_end,
                        status,
                        created_by_user_id,
                        updated_by_user_id
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $opInsertStmt->execute([
                    $incidentId,
                    1,
                    'Operational Period 1',
                    $opStart,
                    $opEnd,
                    $initialOperationalPeriodStatus,
                    $tscUserId > 0 ? $tscUserId : null,
                    $tscUserId > 0 ? $tscUserId : null,
                ]);
                $operationalPeriodId = (int)$pdo->lastInsertId();
            } else {
                $operationalPeriodId = (int)$opCheckStmt->fetchColumn();
            }

            // Select the newly created period explicitly. This also supports a future
            // incident start time, where it would not otherwise be the current period.
            if ($operationalPeriodId > 0) {
                $_SESSION['selected_operational_period_id'] = $operationalPeriodId;
            }

            if ($tscUserId > 0 && $tscRoleId > 0) {
                sync_user_checkin_records($incidentId, $tscUserId, true);
            }

            flash('success', 'Incident and Initial Operational Period created. The Technical Services Coordinator has been added to ICS 211 and ICS 205A.');
            header('Location: ' . app_url('/setup/setup_tsc.php?step=3'));
            exit;
        }
    }


    if ($step === 3) {
        $incident = active_incident();
        $incidentId = (int)($incident['id'] ?? 0);
        $realName = trim($_POST['real_name'] ?? '');
        $username = trim($_POST['username'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $phone = trim($_POST['cell_phone'] ?? '');
        $checkInNow = !empty($_POST['check_in_now']);
        $pin = generate_pin();

        $roleStmt = $pdo->prepare("SELECT id FROM roles WHERE role_code = 'IC' LIMIT 1");
        $roleStmt->execute();
        $role = $roleStmt->fetch();

        $stmt = $pdo->prepare("
            INSERT INTO users (username, real_name, email, pin_encrypted, created_at, updated_at)
            VALUES (?, ?, ?, ?, NOW(), NOW())
        ");
        $stmt->execute([$username, $realName, $email ?: null, encrypt_pin($pin)]);
        $userId = (int)$pdo->lastInsertId();

        save_contact_value($userId, 'cell_phone', $phone);
        save_contact_value($userId, 'email', $email);

        if ($incidentId > 0 && !empty($role['id'])) {
            sync_user_roles($incidentId, $userId, [(int)$role['id']]);
        }

        if ($checkInNow && $incidentId > 0) {
            sync_user_checkin_records($incidentId, $userId, true);
        }

        $_SESSION['setup_generated_pin'] = [
            'real_name' => $realName,
            'role' => 'IC',
            'pin' => $pin,
            'checked_in' => $checkInNow ? 'Yes' : 'No',
        ];

        flash('success', $checkInNow
            ? 'Initial IC user created and checked in to ICS 211 / ICS 205A.'
            : 'Initial IC user created. You can check them in later from Users / Roles.'
        );
        header('Location: ' . app_url('/setup/setup_tsc.php?step=4'));
        exit;
    }

    if ($step === 4) {
        unset($_SESSION['setup_generated_pin']);
        flash('success', 'Setup complete. You can now sign in.');
        header('Location: ' . app_url('/login.php'));
        exit;
    }
}

render_header('First-Run Setup');
?>
<div class="card">
  <p>This setup flow prioritizes the minimum needed to start the website: Technical Services Coordinator admin, incident, and initial Incident Commander assignment. Microsoft Entra / M365 sign-in can be configured later from Settings &gt; Entra Settings after the first incident is created.</p>
</div>

<?php if ($step === 1): ?>
<div class="card">
  <h2>Create Technical Services Coordinator Administrator</h2>
  <form method="post">
    <?= csrf_field() ?>
    <label>Username</label><input name="username" required>
    <label>Password</label><input name="password" type="password" required>
    <label>Confirm Password</label><input name="password_confirm" type="password" required>
    <label>Real Name</label><input name="real_name" required>
    <label>Email</label><input name="email" type="email">
    <label>Cell Phone</label><input name="cell_phone">
    <div style="margin-top:16px;"><button type="submit">Save and Continue</button></div>
  </form>
</div>
<?php elseif ($step === 2): ?>
<div class="card">
  <h2>Create Incident and Initial Operational Period</h2>
  <p class="small">Set the initial operational period schedule before continuing to create the Incident Commander.</p>
  <form method="post">
    <?= csrf_field() ?>
    <label>Incident Name</label><input name="name" required value="<?= e($incidentForm['name']) ?>">
    <label>Incident Number</label><input name="incident_number" value="<?= e($incidentForm['incident_number']) ?>">
    <label>Incident Type</label><input name="incident_type" value="<?= e($incidentForm['incident_type']) ?>">
    <label>Status</label><input name="status" value="<?= e($incidentForm['status']) ?>">
    <div class="govai-dt-wrap" data-govai-datetime>
      <label>Incident Start Date/Time</label><input class="govai-dt-display" name="start_datetime" type="text" inputmode="numeric" pattern="[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}" placeholder="YYYY-MM-DD HH:mm" required value="<?= e($incidentForm['start_datetime']) ?>">
      <button type="button" class="btn btn-secondary govai-dt-button">Pick</button>
    </div>
    <h3>Initial Operational Period</h3>
    <p class="small">Operational Period 1 is created using the dates and times entered below.</p>
    <div class="govai-dt-wrap" data-govai-datetime>
      <label>Start Date/Time</label><input class="govai-dt-display" name="op_start" type="text" inputmode="numeric" pattern="[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}" placeholder="YYYY-MM-DD HH:mm" required value="<?= e($incidentForm['op_start']) ?>">
      <button type="button" class="btn btn-secondary govai-dt-button">Pick</button>
    </div>
    <div class="govai-dt-wrap" data-govai-datetime>
      <label>End Date/Time</label><input class="govai-dt-display" name="op_end" type="text" inputmode="numeric" pattern="[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}" placeholder="YYYY-MM-DD HH:mm" required value="<?= e($incidentForm['op_end']) ?>">
      <button type="button" class="btn btn-secondary govai-dt-button">Pick</button>
    </div>
    <div style="margin-top:16px;"><button type="submit">Save and Continue</button></div>
  </form>
</div>
<?php elseif ($step === 3): ?>
<div class="card">
  <h2>Create Initial IC User</h2>
  <p>Only the IC is required at this stage. Other ICS role assignments can be added or changed later through the Users / Roles area.</p>
  <form method="post">
    <?= csrf_field() ?>
    <label>Full Name</label><input name="real_name" required>
    <label>Username</label><input name="username" required>
    <label>Email</label><input name="email" type="email">
    <label>Cell Phone</label><input name="cell_phone">
    <label class="checkbox-row"><input type="checkbox" name="check_in_now" value="1" checked> Check In Now</label>
    <p class="small">When checked, the IC is added to the ICS 211 Check-In List and the ICS 205A Communications List immediately.</p>
    <div style="margin-top:16px;"><button type="submit">Create IC and Continue</button></div>
  </form>
</div>
<?php elseif ($step === 4): ?>
<div class="card">
  <h2>Setup Complete</h2>
  <?php $pinCard = $_SESSION['setup_generated_pin'] ?? null; ?>
  <?php if ($pinCard): ?>
    <p><strong>Name:</strong> <?= e($pinCard['real_name']) ?></p>
    <p><strong>Role:</strong> <?= e($pinCard['role']) ?></p>
    <p><strong>PIN:</strong> <?= e($pinCard['pin']) ?></p>
    <p><strong>Checked In Now:</strong> <?= e($pinCard['checked_in']) ?></p>
    <p class="small">Record this PIN now. The username is intentionally not shown on the PIN card.</p>
  <?php endif; ?>
  <p class="small">If Microsoft Entra / M365 sign-in is needed, configure it after sign-in from Settings &gt; Entra Settings.</p>
  <form method="post">
    <?= csrf_field() ?>
    <button type="submit">Finish and Go to Login</button>
  </form>
</div>
<?php endif; ?>
<?php render_footer(); ?>
