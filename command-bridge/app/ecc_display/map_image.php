<?php
declare(strict_types=1);

require_once __DIR__ . '/display_helpers.php';

$storedName = ecc_display_map_image_name();
$filePath = $storedName === '' ? null : dirname(__DIR__) . '/uploads/ecc_display_maps/' . $storedName;

if ($filePath === null || !is_file($filePath) || !is_readable($filePath)) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Map image not found.';
    exit;
}

$extension = strtolower(pathinfo($storedName, PATHINFO_EXTENSION));
$mimeTypes = [
    'jpg' => 'image/jpeg',
    'png' => 'image/png',
    'bmp' => 'image/bmp',
];
if (!isset($mimeTypes[$extension])) {
    http_response_code(404);
    exit;
}

header('Content-Type: ' . $mimeTypes[$extension]);
header('Content-Length: ' . (string)filesize($filePath));
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');
header('X-Robots-Tag: noindex, nofollow');
readfile($filePath);
