<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';

$location = gateway_require_location_session();
$locationId = (int)$location['id'];
$status = gateway_checkin_status($locationId);

$stmt = gateway_db()->prepare('SELECT id, reference_number, provider_name, resource_type FROM gateway_expected_resources WHERE gateway_location_id=? AND publication_status="published" ORDER BY reference_number, provider_name, id');
$stmt->execute([$locationId]);

$resources = [];
foreach ($stmt->fetchAll() as $resource) {
    $resources[] = [
        'id' => (int)$resource['id'],
        'label' => trim(($resource['reference_number'] ?: 'Resource '.$resource['id']).' — '.$resource['provider_name'].' — '.$resource['resource_type']),
    ];
}

gateway_json(200, $status + [
    'manifest_updated_display' => gateway_format_local_datetime($status['manifest_updated_at']),
    'resources' => $resources,
]);
