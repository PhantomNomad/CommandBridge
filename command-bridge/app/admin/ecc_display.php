<?php
declare(strict_types=1);

require_once __DIR__ . '/settings_helpers.php';
require_once dirname(__DIR__) . '/ecc_display/display_helpers.php';

function ecc_display_admin_coordinate(string $value, float $minimum, float $maximum): ?string {
    $value = trim($value);
    if ($value === '') {
        return '';
    }
    if (!is_numeric($value) || (float)$value < $minimum || (float)$value > $maximum) {
        return null;
    }
    return rtrim(rtrim(number_format((float)$value, 6, '.', ''), '0'), '.');
}

function ecc_display_admin_map_upload(array $file): array {
    $error = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($error === UPLOAD_ERR_NO_FILE) {
        return ['has_file' => false, 'error' => ''];
    }
    if ($error !== UPLOAD_ERR_OK) {
        return ['has_file' => false, 'error' => 'The map image could not be uploaded.'];
    }

    $temporaryName = (string)($file['tmp_name'] ?? '');
    $size = (int)($file['size'] ?? 0);
    if ($temporaryName === '' || !is_uploaded_file($temporaryName)) {
        return ['has_file' => false, 'error' => 'The map image upload is not valid.'];
    }
    if ($size < 1 || $size > 10 * 1024 * 1024) {
        return ['has_file' => false, 'error' => 'The map image must be no larger than 10 MB.'];
    }

    $imageInfo = @getimagesize($temporaryName);
    $allowedTypes = [
        IMAGETYPE_JPEG => ['extension' => 'jpg', 'mime_types' => ['image/jpeg']],
        IMAGETYPE_PNG => ['extension' => 'png', 'mime_types' => ['image/png']],
    ];
    if (defined('IMAGETYPE_BMP')) {
        $allowedTypes[(int)constant('IMAGETYPE_BMP')] = ['extension' => 'bmp', 'mime_types' => ['image/bmp', 'image/x-bmp', 'image/x-ms-bmp']];
    }

    $imageType = is_array($imageInfo) ? (int)($imageInfo[2] ?? 0) : 0;
    if (!isset($allowedTypes[$imageType])) {
        return ['has_file' => false, 'error' => 'The map image must be a JPEG, PNG, or BMP file.'];
    }

    $mimeType = '';
    if (class_exists('finfo')) {
        $mimeType = (string)(new finfo(FILEINFO_MIME_TYPE))->file($temporaryName);
    }
    if ($mimeType !== '' && !in_array($mimeType, $allowedTypes[$imageType]['mime_types'], true)) {
        return ['has_file' => false, 'error' => 'The map image file type could not be verified.'];
    }

    return [
        'has_file' => true,
        'error' => '',
        'temporary_name' => $temporaryName,
        'extension' => $allowedTypes[$imageType]['extension'],
    ];
}

function ecc_display_admin_map_file_path(string $storedName): ?string {
    if (!preg_match('/^ecc-map-[a-f0-9]{32}\.(?:jpg|png|bmp)$/', $storedName)) {
        return null;
    }
    return dirname(__DIR__) . '/uploads/ecc_display_maps/' . $storedName;
}

function ecc_display_admin_save_map_upload(array $upload): ?string {
    $directory = dirname(__DIR__) . '/uploads/ecc_display_maps';
    if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
        return null;
    }

    try {
        $storedName = 'ecc-map-' . bin2hex(random_bytes(16)) . '.' . $upload['extension'];
    } catch (Throwable $e) {
        return null;
    }
    $destination = $directory . '/' . $storedName;
    return move_uploaded_file($upload['temporary_name'], $destination) ? $storedName : null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf_token();

    $announcementTitle = trim((string)($_POST['announcement_title'] ?? ''));
    $announcementBody = trim((string)($_POST['announcement_body'] ?? ''));
    $announcementLevel = strtolower(trim((string)($_POST['announcement_level'] ?? 'information')));
    $mapTitle = trim((string)($_POST['map_title'] ?? 'Map'));
    $roadsTitle = trim((string)($_POST['roads_title'] ?? 'Road Closures'));
    $roadsText = trim((string)($_POST['roads_text'] ?? ''));
    $weatherTitle = trim((string)($_POST['weather_title'] ?? 'Weather'));
    $weatherLocation = trim((string)($_POST['weather_location'] ?? ''));
    $weatherLatitude = ecc_display_admin_coordinate((string)($_POST['weather_latitude'] ?? ''), -90, 90);
    $weatherLongitude = ecc_display_admin_coordinate((string)($_POST['weather_longitude'] ?? ''), -180, 180);
    $weatherApiKey = trim((string)($_POST['weather_api_key'] ?? ''));
    $clearWeatherApiKey = !empty($_POST['clear_weather_api_key']);
    $removeMapImage = !empty($_POST['remove_map_image']);
    $mapUpload = ecc_display_admin_map_upload(is_array($_FILES['map_image'] ?? null) ? $_FILES['map_image'] : []);

    if (!in_array($announcementLevel, ['information', 'priority', 'critical'], true)) {
        $announcementLevel = 'information';
    }

    if (strlen($announcementTitle) > 160 || strlen($announcementBody) > 2000 || strlen($mapTitle) > 80 || strlen($roadsTitle) > 80 || strlen($roadsText) > 2000 || strlen($weatherTitle) > 80 || strlen($weatherLocation) > 160 || strlen($weatherApiKey) > 200) {
        flash('error', 'One or more ECC display values are too long.');
    } elseif ($weatherLatitude === null || $weatherLongitude === null) {
        flash('error', 'Weather latitude or longitude is outside the allowed range.');
    } elseif (($weatherLatitude === '') !== ($weatherLongitude === '')) {
        flash('error', 'Enter both weather coordinates or leave both blank.');
    } elseif ($mapUpload['error'] !== '') {
        flash('error', $mapUpload['error']);
    } else {
        $previousMapImage = ecc_display_setting('ecc_display_map_image');
        $mapImage = $removeMapImage ? '' : $previousMapImage;
        if ($mapUpload['has_file']) {
            $mapImage = ecc_display_admin_save_map_upload($mapUpload);
            if ($mapImage === null) {
                flash('error', 'The map image could not be saved. Check the uploads directory permissions.');
                $mapImage = $previousMapImage;
            }
        }

        if (!empty($mapUpload['has_file']) && $mapImage === $previousMapImage) {
            // The upload failed after validation; do not save any partial settings.
        } else {
            save_app_setting('ecc_display_announcement_title', $announcementTitle);
            save_app_setting('ecc_display_announcement_body', $announcementBody);
            save_app_setting('ecc_display_announcement_level', $announcementLevel);
            save_app_setting('ecc_display_map_title', $mapTitle);
            save_app_setting('ecc_display_map_image', $mapImage);
            save_app_setting('ecc_display_roads_title', $roadsTitle);
            save_app_setting('ecc_display_roads_text', $roadsText);
            save_app_setting('ecc_display_weather_title', $weatherTitle);
            save_app_setting('ecc_display_weather_location', $weatherLocation);
            save_app_setting('ecc_display_weather_latitude', $weatherLatitude);
            save_app_setting('ecc_display_weather_longitude', $weatherLongitude);
            if ($clearWeatherApiKey) {
                save_app_setting('ecc_display_weather_api_key', '');
            } elseif ($weatherApiKey !== '') {
                save_app_setting('ecc_display_weather_api_key', $weatherApiKey);
            }
            save_app_setting('ecc_display_weather_cache', '');
            save_app_setting('ecc_display_weather_cache_updated_at', '0');

            if ($previousMapImage !== '' && $previousMapImage !== $mapImage) {
                $previousMapPath = ecc_display_admin_map_file_path($previousMapImage);
                if ($previousMapPath !== null && is_file($previousMapPath)) {
                    @unlink($previousMapPath);
                }
            }

            audit_log((int)(current_user()['id'] ?? 0), 'ecc_display_settings_updated', 'settings', 0, 'ECC display settings updated');
            flash('success', 'ECC display settings saved.');
            header('Location: ' . app_url('/admin/ecc_display.php'));
            exit;
        }
    }
}

$settings = [
    'announcement_title' => ecc_display_setting('ecc_display_announcement_title'),
    'announcement_body' => ecc_display_setting('ecc_display_announcement_body'),
    'announcement_level' => ecc_display_setting('ecc_display_announcement_level', 'information'),
    'map_title' => ecc_display_setting('ecc_display_map_title', 'Map'),
    'map_image_configured' => ecc_display_admin_map_file_path(ecc_display_setting('ecc_display_map_image')) !== null,
    'weather_title' => ecc_display_setting('ecc_display_weather_title', 'Weather'),
    'weather_location' => ecc_display_setting('ecc_display_weather_location', ''),
    'weather_latitude' => ecc_display_setting('ecc_display_weather_latitude'),
    'weather_longitude' => ecc_display_setting('ecc_display_weather_longitude'),
    'weather_api_key_configured' => ecc_display_setting('ecc_display_weather_api_key') !== '',
    'roads_title' => ecc_display_setting('ecc_display_roads_title', 'Road Closures'),
    'roads_text' => ecc_display_setting('ecc_display_roads_text'),
];

render_header('ECC Display');
settings_menu('ecc_display');
?>
<div class="card">
  <h2>ECC Display</h2>
  <p class="small">These two display pages do not require a login. Only enter information suitable for a non-sensitive ECC screen. Do not include names, contact details, credentials, personnel assignments, or protected incident information.</p>
  <p><a class="btn" href="<?= e(app_url('/ecc_display/landscape.php')) ?>" target="_blank" rel="noopener">Open landscape display</a>
     <a class="btn" href="<?= e(app_url('/ecc_display/portrait.php')) ?>" target="_blank" rel="noopener">Open portrait display</a></p>
</div>

<div class="card">
  <form method="post" enctype="multipart/form-data">
    <?= csrf_field() ?>
    <h3>Announcement</h3>
    <label>Title<input name="announcement_title" maxlength="160" value="<?= e($settings['announcement_title']) ?>"></label>
    <label>Display level<select name="announcement_level">
      <option value="information" <?= $settings['announcement_level'] === 'information' ? 'selected' : '' ?>>Information</option>
      <option value="priority" <?= $settings['announcement_level'] === 'priority' ? 'selected' : '' ?>>Priority</option>
      <option value="critical" <?= $settings['announcement_level'] === 'critical' ? 'selected' : '' ?>>Critical</option>
    </select></label>
    <label>Message<textarea name="announcement_body" rows="5" maxlength="2000"><?= e($settings['announcement_body']) ?></textarea></label>

    <h3>Maps, Weather, and Road Closures</h3>
    <div class="form-grid">
      <label>Map panel title<input name="map_title" maxlength="80" value="<?= e($settings['map_title']) ?>"></label>
      <label>Map image (JPEG, PNG, or BMP; maximum 10 MB)<input name="map_image" type="file" accept=".jpg,.jpeg,.png,.bmp,image/jpeg,image/png,image/bmp"></label>
      <label>Weather panel title<input name="weather_title" maxlength="80" value="<?= e($settings['weather_title']) ?>"></label>
      <label>Weather location name / display label<input name="weather_location" maxlength="160" placeholder="City, Province" value="<?= e($settings['weather_location']) ?>"></label>
      <label>Weather latitude (optional)<input name="weather_latitude" type="number" step="any" min="-90" max="90" value="<?= e($settings['weather_latitude']) ?>"></label>
      <label>Weather longitude (optional)<input name="weather_longitude" type="number" step="any" min="-180" max="180" value="<?= e($settings['weather_longitude']) ?>"></label>
      <label>OpenWeather API key<input name="weather_api_key" type="password" autocomplete="new-password" maxlength="200" placeholder="<?= $settings['weather_api_key_configured'] ? 'An API key is already configured' : 'Enter API key' ?>"></label>
      <label>Road-closure panel title<input name="roads_title" maxlength="80" value="<?= e($settings['roads_title']) ?>"></label>
    </div>
    <label>Road-closure message<textarea name="roads_text" rows="7" maxlength="2000" placeholder="Enter current closures, restrictions, detours, and advisories."><?= e($settings['roads_text']) ?></textarea></label>
    <?php if ($settings['map_image_configured']): ?>
      <label><input type="checkbox" name="remove_map_image" value="1"> Remove the current map image</label>
    <?php endif; ?>
    <p class="small">The map image is scaled to fit its display area without changing its aspect ratio. Use a location name to have OpenWeather locate it, or enter both coordinates for a precise location. Coordinates take precedence. Leave the API key blank to keep the configured key. The public display never receives the key.</p>
    <?php if ($settings['weather_api_key_configured']): ?>
      <label><input type="checkbox" name="clear_weather_api_key" value="1"> Remove the configured OpenWeather API key</label>
    <?php endif; ?>
    <button class="btn" type="submit">Save ECC display settings</button>
  </form>
</div>
<?php render_footer(); ?>
