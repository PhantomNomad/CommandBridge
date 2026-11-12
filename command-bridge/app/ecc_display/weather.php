<?php
declare(strict_types=1);

require_once __DIR__ . '/display_helpers.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('X-Robots-Tag: noindex, nofollow');

function ecc_weather_reply(array $data): void {
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function ecc_weather_json(string $url): ?array {
    if (!function_exists('curl_init')) {
        return null;
    }

    $request = curl_init($url);
    curl_setopt_array($request, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_HTTPHEADER => ['Accept: application/json'],
        CURLOPT_USERAGENT => 'CommandBridge-ECC-Weather/1.0',
    ]);
    $body = curl_exec($request);
    $status = (int)curl_getinfo($request, CURLINFO_RESPONSE_CODE);
    curl_close($request);

    if (!is_string($body) || $status !== 200) {
        return null;
    }

    $data = json_decode($body, true);
    return is_array($data) ? $data : null;
}

function ecc_weather_float(string $value, float $minimum, float $maximum): ?float {
    $value = trim($value);
    if ($value === '' || !is_numeric($value)) {
        return null;
    }
    $number = (float)$value;
    return $number >= $minimum && $number <= $maximum ? $number : null;
}

function ecc_weather_cached(): ?array {
    $updated = (int)ecc_display_setting('ecc_display_weather_cache_updated_at', '0');
    $cached = json_decode(ecc_display_setting('ecc_display_weather_cache'), true);
    if ($updated > 0 && (time() - $updated) <= 300 && is_array($cached) && !empty($cached['current']) && !empty($cached['hourly'])) {
        $cached['cached'] = true;
        return $cached;
    }
    return null;
}

function ecc_weather_save_cache(array $weather): void {
    try {
        save_app_setting('ecc_display_weather_cache', json_encode($weather, JSON_UNESCAPED_SLASHES));
        save_app_setting('ecc_display_weather_cache_updated_at', (string)time());
    } catch (Throwable $e) {
        // Weather remains available even where the cache cannot be saved.
    }
}

$cached = ecc_weather_cached();
if ($cached !== null) {
    ecc_weather_reply(['ok' => true, 'weather' => $cached]);
}

$apiKey = trim(ecc_display_setting('ecc_display_weather_api_key'));
$location = trim(ecc_display_setting('ecc_display_weather_location', ''));
$latitude = ecc_weather_float(ecc_display_setting('ecc_display_weather_latitude'), -90, 90);
$longitude = ecc_weather_float(ecc_display_setting('ecc_display_weather_longitude'), -180, 180);

if ($apiKey === '' || ($latitude === null || $longitude === null) && $location === '') {
    ecc_weather_reply(['ok' => false, 'message' => 'Weather has not been configured.']);
}

$locationLabel = $location !== '' ? $location : 'Configured location';
if ($latitude === null || $longitude === null) {
    $geo = ecc_weather_json('https://api.openweathermap.org/geo/1.0/direct?' . http_build_query([
        'q' => $location,
        'limit' => 1,
        'appid' => $apiKey,
    ], '', '&', PHP_QUERY_RFC3986));
    if (!is_array($geo) || empty($geo[0]) || !isset($geo[0]['lat'], $geo[0]['lon'])) {
        ecc_weather_reply(['ok' => false, 'message' => 'Weather service is unavailable.']);
    }
    $latitude = (float)$geo[0]['lat'];
    $longitude = (float)$geo[0]['lon'];
    $labelParts = array_filter([(string)($geo[0]['name'] ?? ''), (string)($geo[0]['state'] ?? ''), (string)($geo[0]['country'] ?? '')]);
    if ($labelParts) {
        $locationLabel = implode(', ', $labelParts);
    }
}

$forecast = ecc_weather_json('https://api.openweathermap.org/data/3.0/onecall?' . http_build_query([
    'lat' => $latitude,
    'lon' => $longitude,
    'exclude' => 'minutely,daily,alerts',
    'units' => 'metric',
    'appid' => $apiKey,
], '', '&', PHP_QUERY_RFC3986));

if (!is_array($forecast) || empty($forecast['current']) || !is_array($forecast['hourly'] ?? null)) {
    ecc_weather_reply(['ok' => false, 'message' => 'Weather service is unavailable.']);
}

$current = $forecast['current'];
$currentWeather = is_array($current['weather'][0] ?? null) ? $current['weather'][0] : [];
$hours = [];
foreach (array_slice($forecast['hourly'], 1, 12) as $hour) {
    if (!is_array($hour)) {
        continue;
    }
    $hourWeather = is_array($hour['weather'][0] ?? null) ? $hour['weather'][0] : [];
    $hours[] = [
        'time' => (int)($hour['dt'] ?? 0),
        'temperature' => (float)($hour['temp'] ?? 0),
        'icon' => (string)($hourWeather['icon'] ?? ''),
        'description' => (string)($hourWeather['description'] ?? ''),
    ];
}

if (!$hours) {
    ecc_weather_reply(['ok' => false, 'message' => 'Weather service is unavailable.']);
}

$weather = [
    'location' => $locationLabel,
    'timezone_offset' => (int)($forecast['timezone_offset'] ?? 0),
    'current' => [
        'time' => (int)($current['dt'] ?? time()),
        'temperature' => (float)($current['temp'] ?? 0),
        'feels_like' => (float)($current['feels_like'] ?? 0),
        'humidity' => (int)($current['humidity'] ?? 0),
        'wind_kph' => round(((float)($current['wind_speed'] ?? 0)) * 3.6),
        'icon' => (string)($currentWeather['icon'] ?? ''),
        'description' => (string)($currentWeather['description'] ?? ''),
    ],
    'hourly' => $hours,
    'cached' => false,
];

ecc_weather_save_cache($weather);
ecc_weather_reply(['ok' => true, 'weather' => $weather]);
