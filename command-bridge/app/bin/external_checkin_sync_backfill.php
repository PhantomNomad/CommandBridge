#!/usr/bin/env php
<?php
declare(strict_types=1);

/*
 * Queues current location and expected-resource snapshots after the Part 3
 * migration is installed. It is safe to run more than once because the queue
 * uses deterministic idempotency keys.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only.\n");
}

require_once dirname(__DIR__) . '/includes/external_checkin_sync.php';

try {
    $locationRows = db()->query(
        'SELECT id FROM external_checkin_locations
         WHERE lifecycle_status IN ("draft","active","closed","archived")
         ORDER BY id'
    )->fetchAll();

    $resourceRows = db()->query(
        'SELECT id FROM external_checkin_expected_resources
         WHERE publication_status IN ("draft","published","withdrawn","checked_in","demobilized")
         ORDER BY id'
    )->fetchAll();

    $locations = 0;
    foreach ($locationRows as $row) {
        external_checkin_enqueue_location_snapshot((int)$row['id']);
        $locations++;
    }

    $resources = 0;
    foreach ($resourceRows as $row) {
        external_checkin_enqueue_expected_resource_snapshot((int)$row['id']);
        $resources++;
    }

    $incidentRows = db()->query('SELECT id FROM incidents WHERE deleted_at IS NULL ORDER BY id')->fetchAll();
    $companionUsers = 0;
    foreach ($incidentRows as $row) {
        $companionUsers += external_checkin_enqueue_incident_companion_users((int)$row['id']);
    }

    echo 'locations_queued=' . $locations
        . ' expected_resources_queued=' . $resources
        . ' companion_users_queued=' . $companionUsers . PHP_EOL;
    exit(0);
} catch (Throwable $e) {
    security_log($e, 'external_checkin_sync_backfill');
    fwrite(STDERR, "External check-in synchronization backfill failed.\n");
    exit(1);
}
