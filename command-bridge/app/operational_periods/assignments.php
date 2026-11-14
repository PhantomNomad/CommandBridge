<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/security.php';
require_csrf_for_post();

require_once dirname(__DIR__) . '/includes/layout.php';
require_login();

$incident = active_incident();
$incidentId = (int)($incident['id'] ?? 0);
require_operational_period_create_access($incidentId);

$currentOp = current_operational_period($incidentId);
$opId = (int)($_GET['op_id'] ?? $_POST['op_id'] ?? ($currentOp['id'] ?? 0));
if ($opId <= 0) {
    flash('error', 'No operational period is selected.');
    header('Location: ' . app_url('/dashboard/index.php'));
    exit;
}

if (!operational_period_assignment_table_exists()) {
    render_header('Operational Period Position Assignments');
    echo '<div class="card"><h2>Operational Period Position Assignments</h2><p class="flash error">The assignment table has not been installed. Run the included SQL patch first.</p></div>';
    render_footer();
    exit;
}

$users = operational_period_users_for_assignment_select();
$usersById = [];
foreach ($users as $user) {
    $usersById[(int)$user['id']] = [
        'name' => trim((string)($user['real_name'] ?: $user['username'])),
        'username' => (string)$user['username'],
    ];
}

/* Use the role catalogue for the Add Position Assignment selector. */
$positionOptions = [];
foreach (get_roles() as $role) {
    $code = strtoupper(trim((string)($role['code'] ?? '')));
    $title = trim((string)($role['name'] ?? ''));
    if ($code !== '' && $title !== '' && !isset($positionOptions[$code])) {
        $positionOptions[$code] = $title;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (isset($_POST['seed_assignments'])) {
            $copyFrom = (int)($_POST['copy_from_op_id'] ?? 0);
            $count = seed_operational_period_position_assignments($incidentId, $opId, $copyFrom > 0 ? $copyFrom : null);
            assign_review_required_forms_to_positions($incidentId, $opId);
            flash('success', 'Seeded ' . $count . ' position assignment(s) and refreshed copied-form responsibility.');
            header('Location: ' . app_url('/operational_periods/assignments.php?op_id=' . $opId));
            exit;
        }

        if (isset($_POST['save_assignments'])) {
            $pdo = db();
            $actorId = (int)(current_user()['id'] ?? 0) ?: null;
            $assignmentIds = $_POST['assignment_id'] ?? [];
            $userIds = $_POST['user_id'] ?? [];
            $assignedNames = $_POST['assigned_name'] ?? [];
            $statuses = $_POST['assignment_status'] ?? [];
            $agencies = $_POST['agency'] ?? [];
            $validStatuses = ['assigned', 'vacant', 'incoming', 'outgoing', 'relieved'];

            $pdo->beginTransaction();

            /*
             * Only rows displayed in the filled-assignment table are updated.
             * This preserves vacant/default rows that are intentionally hidden.
             */
            $existingStatement = $pdo->prepare(
                "SELECT id, user_id, assigned_name
                 FROM operational_period_position_assignments
                 WHERE id=? AND incident_id=? AND operational_period_id=? AND deleted_at IS NULL
                 LIMIT 1"
            );
            $updateStatement = $pdo->prepare(
                "UPDATE operational_period_position_assignments
                 SET user_id=?, assigned_name=?, assignment_status=?, agency=?, updated_at=NOW(), updated_by_user_id=?
                 WHERE id=? AND incident_id=? AND operational_period_id=? AND deleted_at IS NULL"
            );

            foreach ($assignmentIds as $i => $assignmentIdRaw) {
                $assignmentId = (int)$assignmentIdRaw;
                if ($assignmentId <= 0) {
                    continue;
                }

                $existingStatement->execute([$assignmentId, $incidentId, $opId]);
                $existing = $existingStatement->fetch(PDO::FETCH_ASSOC);
                if (!$existing) {
                    throw new RuntimeException('An assignment could not be found or is no longer available.');
                }

                $userId = (int)($userIds[$i] ?? 0);
                $assignedName = trim((string)($assignedNames[$i] ?? ''));
                if ($userId > 0) {
                    if (isset($usersById[$userId])) {
                        $assignedName = $usersById[$userId]['name'];
                    } elseif ($userId === (int)($existing['user_id'] ?? 0)) {
                        /* Retain an inactive user already assigned to the position. */
                        $assignedName = trim((string)($existing['assigned_name'] ?? $assignedName));
                    } else {
                        throw new RuntimeException('The selected user is not available for assignment.');
                    }
                } else {
                    $userId = 0;
                }

                $status = (string)($statuses[$i] ?? 'assigned');
                if (!in_array($status, $validStatuses, true)) {
                    $status = 'assigned';
                }
                if ($assignedName === '') {
                    $status = 'vacant';
                }

                $agency = trim((string)($agencies[$i] ?? ''));
                $updateStatement->execute([
                    $userId > 0 ? $userId : null,
                    $assignedName !== '' ? $assignedName : null,
                    $status,
                    $agency !== '' ? $agency : null,
                    $actorId,
                    $assignmentId,
                    $incidentId,
                    $opId,
                ]);
            }

            /*
             * Add a selected position, or fill an existing vacant row for it.
             * A filled position is never silently overwritten.
             */
            $newPositionCode = strtoupper(trim((string)($_POST['add_position_code'] ?? '')));
            if ($newPositionCode !== '') {
                if (!isset($positionOptions[$newPositionCode])) {
                    throw new RuntimeException('Select a valid position from the position list.');
                }

                $newUserId = (int)($_POST['add_user_id'] ?? 0);
                $newAssignedName = trim((string)($_POST['add_assigned_name'] ?? ''));
                if ($newUserId > 0) {
                    if (!isset($usersById[$newUserId])) {
                        throw new RuntimeException('The selected user is not available for assignment.');
                    }
                    $newAssignedName = $usersById[$newUserId]['name'];
                } else {
                    $newUserId = 0;
                }

                $newStatus = (string)($_POST['add_assignment_status'] ?? 'assigned');
                if (!in_array($newStatus, $validStatuses, true)) {
                    $newStatus = 'assigned';
                }
                if ($newAssignedName === '') {
                    $newStatus = 'vacant';
                }
                $newAgency = trim((string)($_POST['add_agency'] ?? ''));

                $samePositionStatement = $pdo->prepare(
                    "SELECT id, user_id, assigned_name
                     FROM operational_period_position_assignments
                     WHERE incident_id=? AND operational_period_id=? AND position_code=? AND deleted_at IS NULL
                     ORDER BY id ASC"
                );
                $samePositionStatement->execute([$incidentId, $opId, $newPositionCode]);
                $samePositionRows = $samePositionStatement->fetchAll(PDO::FETCH_ASSOC);

                $vacantAssignmentId = 0;
                foreach ($samePositionRows as $samePositionRow) {
                    $hasAssignment = (int)($samePositionRow['user_id'] ?? 0) > 0
                        || trim((string)($samePositionRow['assigned_name'] ?? '')) !== '';
                    if ($hasAssignment) {
                        throw new RuntimeException('That position already has a filled assignment for this operational period.');
                    }
                    if ($vacantAssignmentId <= 0) {
                        $vacantAssignmentId = (int)$samePositionRow['id'];
                    }
                }

                if ($vacantAssignmentId > 0) {
                    $fillVacantStatement = $pdo->prepare(
                        "UPDATE operational_period_position_assignments
                         SET position_title=?, user_id=?, assigned_name=?, assignment_status=?, agency=?,
                             updated_at=NOW(), updated_by_user_id=?
                         WHERE id=? AND incident_id=? AND operational_period_id=? AND deleted_at IS NULL"
                    );
                    $fillVacantStatement->execute([
                        $positionOptions[$newPositionCode],
                        $newUserId > 0 ? $newUserId : null,
                        $newAssignedName !== '' ? $newAssignedName : null,
                        $newStatus,
                        $newAgency !== '' ? $newAgency : null,
                        $actorId,
                        $vacantAssignmentId,
                        $incidentId,
                        $opId,
                    ]);
                } else {
                    $insertStatement = $pdo->prepare(
                        "INSERT INTO operational_period_position_assignments
                         (incident_id, operational_period_id, position_code, position_title, user_id, assigned_name,
                          assignment_status, agency, starts_at, updated_at, updated_by_user_id)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW(), ?)"
                    );
                    $insertStatement->execute([
                        $incidentId,
                        $opId,
                        $newPositionCode,
                        $positionOptions[$newPositionCode],
                        $newUserId > 0 ? $newUserId : null,
                        $newAssignedName !== '' ? $newAssignedName : null,
                        $newStatus,
                        $newAgency !== '' ? $newAgency : null,
                        $actorId,
                    ]);
                }
            }

            $pdo->commit();
            assign_review_required_forms_to_positions($incidentId, $opId);
            audit_log((int)(current_user()['id'] ?? 0) ?: null, 'operational_period_assignments_updated', 'operational_period', $opId, 'Updated position assignments');
            flash('success', 'Position assignments saved.');
            header('Location: ' . app_url('/operational_periods/assignments.php?op_id=' . $opId));
            exit;
        }
    } catch (Throwable $e) {
        if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        flash('error', $e->getMessage());
    }
}

$assignments = operational_period_position_assignments($incidentId, $opId);
$filledAssignments = array_values(array_filter($assignments, static function (array $assignment): bool {
    return (int)($assignment['user_id'] ?? 0) > 0
        || trim((string)($assignment['assigned_name'] ?? '')) !== '';
}));

$periods = get_operational_periods($incidentId);
render_header('Operational Period Position Assignments');
?>
<div class="card">
    <h2>Operational Period Position Assignments</h2>
    <p class="muted">Manage the positions filled for this operational period. Use the form below to add a position when needed.</p>
    <p><a class="button secondary" href="<?= e(app_url('/operational_periods/index.php?op_id=' . $opId)) ?>">Back to Operational Period</a></p>

    <form method="post" style="margin-bottom:1rem;">
        <?= csrf_field() ?>
        <input type="hidden" name="op_id" value="<?= (int)$opId ?>">
        <label>Seed from another operational period
            <select name="copy_from_op_id">
                <option value="">Use current incident position assignments</option>
                <?php foreach ($periods as $period): if ((int)$period['id'] === $opId) { continue; } ?>
                    <option value="<?= (int)$period['id'] ?>"><?= e($period['period_name'] ?: ('OP ' . $period['period_number'])) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <button type="submit" name="seed_assignments" value="1" class="button secondary">Seed Position Assignments</button>
    </form>

    <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="op_id" value="<?= (int)$opId ?>">

        <h3>Filled Position Assignments</h3>
        <?php if ($filledAssignments): ?>
            <div style="overflow:auto;">
                <table class="cb-cards">
                    <thead>
                        <tr>
                            <th>Position</th>
                            <th>Title</th>
                            <th>User</th>
                            <th>Assigned Name</th>
                            <th>Agency</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($filledAssignments as $i => $assignment):
                        $nameInputId = 'assigned_name_' . (int)$assignment['id'];
                        $assignedUserId = (int)($assignment['user_id'] ?? 0);
                        $activeUserExists = isset($usersById[$assignedUserId]);
                    ?>
                        <tr>
                            <td>
                                <?= e((string)$assignment['position_code']) ?>
                                <input type="hidden" name="assignment_id[]" value="<?= (int)$assignment['id'] ?>">
                            </td>
                            <td><?= e((string)$assignment['position_title']) ?></td>
                            <td>
                                <select name="user_id[]" class="assignment-user-select" data-name-target="<?= e($nameInputId) ?>">
                                    <option value="0">No application user / external</option>
                                    <?php if ($assignedUserId > 0 && !$activeUserExists):
                                        $inactiveName = trim((string)($assignment['user_real_name'] ?? ''));
                                        if ($inactiveName === '') {
                                            $inactiveName = trim((string)($assignment['assigned_name'] ?? ''));
                                        }
                                        if ($inactiveName === '') {
                                            $inactiveName = trim((string)($assignment['username'] ?? ''));
                                        }
                                    ?>
                                        <option value="<?= $assignedUserId ?>" selected>Inactive user: <?= e($inactiveName) ?></option>
                                    <?php endif; ?>
                                    <?php foreach ($users as $user):
                                        $fullName = trim((string)($user['real_name'] ?: $user['username']));
                                    ?>
                                        <option value="<?= (int)$user['id'] ?>" data-full-name="<?= e($fullName) ?>" <?= $assignedUserId === (int)$user['id'] ? 'selected' : '' ?>>
                                            <?= e($fullName . ' (' . $user['username'] . ')') ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                            <td><input id="<?= e($nameInputId) ?>" type="text" name="assigned_name[]" value="<?= e((string)($assignment['assigned_name'] ?? '')) ?>"></td>
                            <td><input type="text" name="agency[]" value="<?= e((string)($assignment['agency'] ?? '')) ?>"></td>
                            <td>
                                <select name="assignment_status[]">
                                    <?php foreach (['assigned', 'vacant', 'incoming', 'outgoing', 'relieved'] as $status): ?>
                                        <option value="<?= e($status) ?>" <?= ($assignment['assignment_status'] ?? '') === $status ? 'selected' : '' ?>><?= e(ucfirst($status)) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <p class="muted">No filled positions have been assigned for this operational period.</p>
        <?php endif; ?>

        <h3 style="margin-top:1.5rem;">Add Position Assignment</h3>
        <div style="overflow:auto;">
            <table class="cb-cards">
                <thead>
                    <tr>
                        <th>Position</th>
                        <th>Position Title</th>
                        <th>User</th>
                        <th>Assigned Name</th>
                        <th>Agency</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td>
                            <select id="add_position_code" name="add_position_code">
                                <option value="">Select a position</option>
                                <?php foreach ($positionOptions as $code => $title): ?>
                                    <option value="<?= e($code) ?>" data-position-title="<?= e($title) ?>"><?= e($code . ' — ' . $title) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                        <td><input id="add_position_title" type="text" value="" readonly></td>
                        <td>
                            <select id="add_user_id" name="add_user_id" class="assignment-user-select" data-name-target="add_assigned_name">
                                <option value="0">No application user / external</option>
                                <?php foreach ($users as $user):
                                    $fullName = trim((string)($user['real_name'] ?: $user['username']));
                                ?>
                                    <option value="<?= (int)$user['id'] ?>" data-full-name="<?= e($fullName) ?>"><?= e($fullName . ' (' . $user['username'] . ')') ?></option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                        <td><input id="add_assigned_name" type="text" name="add_assigned_name" value=""></td>
                        <td><input type="text" name="add_agency" value=""></td>
                        <td>
                            <select name="add_assignment_status">
                                <?php foreach (['assigned', 'vacant', 'incoming', 'outgoing', 'relieved'] as $status): ?>
                                    <option value="<?= e($status) ?>" <?= $status === 'assigned' ? 'selected' : '' ?>><?= e(ucfirst($status)) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <p><button type="submit" name="save_assignments" value="1" class="button">Save Position Assignments</button></p>
    </form>
</div>

<script>
(function () {
    function updatePositionTitle() {
        var positionSelect = document.getElementById('add_position_code');
        var titleInput = document.getElementById('add_position_title');
        if (!positionSelect || !titleInput) {
            return;
        }
        var option = positionSelect.options[positionSelect.selectedIndex];
        titleInput.value = option && option.dataset.positionTitle ? option.dataset.positionTitle : '';
    }

    document.querySelectorAll('.assignment-user-select').forEach(function (select) {
        select.addEventListener('change', function () {
            var target = document.getElementById(select.dataset.nameTarget || '');
            var option = select.options[select.selectedIndex];
            if (target && option && option.dataset.fullName) {
                target.value = option.dataset.fullName;
            }
        });
    });

    var addPositionSelect = document.getElementById('add_position_code');
    if (addPositionSelect) {
        addPositionSelect.addEventListener('change', updatePositionTitle);
        updatePositionTitle();
    }
}());
</script>
<?php render_footer(); ?>
