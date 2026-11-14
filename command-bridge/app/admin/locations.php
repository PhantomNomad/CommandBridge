<?php
declare(strict_types=1);

require_once __DIR__ . '/settings_helpers.php';
require_once __DIR__ . '/../includes/external_checkin_sync.php';

header('Cache-Control: no-store, private');
header('Pragma: no-cache');

$incident = active_incident();
$incidentId = (int)($incident['id'] ?? 0);

function locations_redirect(string $message): void {
    $_SESSION['locations_flash'] = $message;
    header('Location: ' . app_url('/admin/locations.php'));
    exit;
}

function locations_log(PDO $pdo, int $incidentId, string $type, array $data = []): void {
    $stmt = $pdo->prepare('INSERT INTO external_checkin_events (incident_id,event_type,event_payload,created_by) VALUES (?,?,?,?)');
    $stmt->execute([
        $incidentId,
        $type,
        json_encode($data, JSON_UNESCAPED_SLASHES),
        (int)(current_user()['id'] ?? 0) ?: null
    ]);
}

/*
 * Synchronization is secondary to saving the operational location. Queue the
 * snapshot only after the location transaction has committed so a sync queue
 * issue cannot roll back a location that was just created or updated.
 */
function locations_public_location_url(string $locationToken): string {
    // From /etc/commandbridge/external_checkin_sync.env; an Apache SetEnv value is still honoured for older setups.
    $baseUrl = trim((string)(external_checkin_sync_config()['public_entry_base_url'] ?? ''));
    if ($baseUrl === '') {
        $baseUrl = trim((string)getenv('EXTERNAL_CHECKIN_PUBLIC_ENTRY_BASE_URL'));
    }
    if ($baseUrl === '' || filter_var($baseUrl, FILTER_VALIDATE_URL) === false) {
        return '';
    }

    if (str_contains($baseUrl, '{location_token}')) {
        return str_replace('{location_token}', rawurlencode($locationToken), $baseUrl);
    }

    return $baseUrl . (str_contains($baseUrl, '?') ? '&' : '?')
        . 'location=' . rawurlencode($locationToken);
}

function locations_enqueue_location_snapshot(int $locationId): ?string {
    try {
        external_checkin_enqueue_location_snapshot($locationId);

        $locationStmt = db()->prepare('SELECT incident_id FROM external_checkin_locations WHERE id = ? LIMIT 1');
        $locationStmt->execute([$locationId]);
        $incidentId = (int)$locationStmt->fetchColumn();
        if ($incidentId > 0) {
            external_checkin_enqueue_incident_companion_users($incidentId);
        }
        return null;
    } catch (Throwable $e) {
        error_log('CommandBridge external check-in location synchronization could not be queued for location ID ' . $locationId . '.');
        return ' The location change was saved, but it could not be queued for external check-in synchronization.';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf_for_post();

    if ($incidentId <= 0) {
        throw new RuntimeException('An active incident is required to manage locations.');
    }

    $action = (string)($_POST['action'] ?? '');
    $pdo = db();
    $userId = (int)(current_user()['id'] ?? 0);

    try {
        $pdo->beginTransaction();

        if ($action === 'location') {
            $name = trim((string)($_POST['name'] ?? ''));
            $type = trim((string)($_POST['type'] ?? 'Staging Area'));
            $address = trim((string)($_POST['address'] ?? ''));

            if ($name === '') {
                throw new RuntimeException('Location name is required.');
            }

            /*
             * access_code_hash remains non-null in the current schema. Store an
             * unrecoverable placeholder while Companion Check-In uses each
             * authorized user's PIN instead of a location access code.
             */
            $legacyAccessCodeHash = password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT);
            $stmt = $pdo->prepare('INSERT INTO external_checkin_locations
                (incident_id,location_name,location_type,street_address,location_token,access_code_hash,lifecycle_status,created_by,updated_by)
                VALUES (?,?,?,?,?,?,"draft",?,?)');
            $stmt->execute([
                $incidentId,
                $name,
                $type !== '' ? $type : 'Staging Area',
                $address,
                bin2hex(random_bytes(16)),
                $legacyAccessCodeHash,
                $userId,
                $userId
            ]);

            $locationId = (int)$pdo->lastInsertId();
            locations_log($pdo, $incidentId, 'location_created', ['location_id' => $locationId]);
            $pdo->commit();
            $syncWarning = locations_enqueue_location_snapshot($locationId);
            locations_redirect('Draft location created.' . ($syncWarning ?? ''));
        }

        if ($action === 'status') {
            $status = (string)($_POST['status'] ?? 'draft');
            if (!in_array($status, ['draft', 'active', 'closed', 'archived'], true)) {
                throw new RuntimeException('Invalid location status.');
            }

            $stmt = $pdo->prepare('UPDATE external_checkin_locations
                SET lifecycle_status=?, updated_by=?, updated_at=NOW()
                WHERE id=? AND incident_id=?');
            $stmt->execute([$status, $userId, (int)($_POST['location_id'] ?? 0), $incidentId]);

            if ($stmt->rowCount() < 1) {
                throw new RuntimeException('Location not found for the active incident.');
            }

            $locationId = (int)($_POST['location_id'] ?? 0);
            locations_log($pdo, $incidentId, 'location_status_changed', [
                'location_id' => $locationId,
                'status' => $status
            ]);
            $pdo->commit();
            $syncWarning = locations_enqueue_location_snapshot($locationId);
            locations_redirect('Location status updated.' . ($syncWarning ?? ''));
        }

        throw new RuntimeException('Unsupported request.');
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        locations_redirect('No changes saved: ' . $e->getMessage());
    }
}

$flash = $_SESSION['locations_flash'] ?? null;
unset($_SESSION['locations_flash']);
$locations = [];
if ($incidentId > 0) {
    $stmt = db()->prepare('SELECT * FROM external_checkin_locations WHERE incident_id=? ORDER BY location_name');
    $stmt->execute([$incidentId]);
    $locations = $stmt->fetchAll();
}

render_header('Locations');
?>
<script src="<?= e(app_url('/assets/js/external_checkin_qr.js')) ?>" defer></script>
<div class="page">
  <h1>Locations</h1>
  <p class="muted">Create and manage receiving, staging, and other approved locations for the active incident.</p>

  <?php if ($flash): ?><div class="flash"><?= e((string)$flash) ?></div><?php endif; ?>

  <?php if ($incidentId <= 0): ?>
    <div class="card"><p>No active incident is selected.</p></div>
  <?php else: ?>
    <section class="card">
      <h2>Create Location</h2>
      <form method="post" class="form-grid">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="location">
        <label>Name <input required name="name"></label>
        <label>Type <input required name="type" value="Staging Area"></label>
        <label class="span-2">Address <input name="address"></label>
        <div class="span-2 form-actions"><button class="btn primary">Create Draft</button></div>
      </form>
    </section>

    <section class="card">
      <h2>Locations</h2>
      <?php if (!$locations): ?>
        <p>No locations have been created for this incident.</p>
      <?php else: ?>
        <table class="cb-cards">
          <thead><tr><th>Name</th><th>Type</th><th>Address</th><th>Status</th><th>Public access</th><th>Update</th></tr></thead>
          <tbody>
          <?php foreach ($locations as $location): ?>
            <?php
              $locationId = (int)$location['id'];
              $locationStatus = (string)$location['lifecycle_status'];
              $publicUrl = locations_public_location_url((string)$location['location_token']);
              $qrCanvasId = 'external-checkin-qr-' . $locationId;
            ?>
            <tr>
              <td><?= e((string)$location['location_name']) ?></td>
              <td><?= e((string)$location['location_type']) ?></td>
              <td><?= e((string)($location['street_address'] ?? '')) ?></td>
              <td><?= e($locationStatus) ?></td>
              <td class="external-checkin-access-cell">
                <details>
                  <summary>Public link and QR</summary>
                  <?php if ($locationStatus !== 'active'): ?>
                    <p class="small">Public check-in is unavailable until this location is set to Active.</p>
                  <?php elseif ($publicUrl === ''): ?>
                    <p class="small">A public entry URL could not be determined. Connect the Check-In Gateway on the server with <code>sudo tools/setup_checkin.sh</code> (it sets <code>EXTERNAL_CHECKIN_PUBLIC_ENTRY_BASE_URL</code>).</p>
                  <?php else: ?>
                    <p><a href="<?= e($publicUrl) ?>" target="_blank" rel="noopener noreferrer">Open public check-in page</a></p>
                    <label class="small" for="external-checkin-url-<?= $locationId ?>">Public check-in URL</label>
                    <div class="external-checkin-url-row">
                      <input id="external-checkin-url-<?= $locationId ?>" readonly value="<?= e($publicUrl) ?>" aria-label="Public check-in URL">
                      <button type="button" class="btn btn-secondary btn-small" data-external-checkin-copy="<?= e($publicUrl) ?>">Copy link</button>
                    </div>
                    <canvas
                      id="<?= e($qrCanvasId) ?>"
                      class="external-checkin-qr"
                      data-external-checkin-qr="<?= e($publicUrl) ?>"
                      aria-label="QR code for <?= e((string)$location['location_name']) ?>"
                    ></canvas>
                    <div class="external-checkin-qr-actions">
                      <button
                        type="button"
                        class="btn btn-secondary btn-small"
                        data-external-checkin-download="<?= e($qrCanvasId) ?>"
                        data-location-name="<?= e((string)$location['location_name']) ?>"
                      >Download QR code</button>
                    </div>
                  <?php endif; ?>

                </details>
              </td>
              <td>
                <form method="post">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="status">
                  <input type="hidden" name="location_id" value="<?= $locationId ?>">
                  <select name="status">
                    <?php foreach (['draft', 'active', 'closed', 'archived'] as $status): ?>
                      <option value="<?= e($status) ?>" <?= $status === $locationStatus ? 'selected' : '' ?>><?= e(ucfirst($status)) ?></option>
                    <?php endforeach; ?>
                  </select>
                  <button>Save</button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </section>
  <?php endif; ?>
</div>
<?php render_footer(); ?>
