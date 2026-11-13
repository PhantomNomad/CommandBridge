<?php
declare(strict_types=1);

/*
 * Public, read-only ECC display helpers.
 * The display intentionally excludes personnel names, contact details,
 * owner/agency fields, order numbers, and free-text resource remarks.
 */
require_once dirname(__DIR__) . '/includes/auth.php';

function ecc_display_setting(string $key, string $default = ''): string {
    try {
        return (string)(get_app_setting($key, $default) ?? $default);
    } catch (Throwable $e) {
        return $default;
    }
}

function ecc_display_source_url(string $value): string {
    $value = trim($value);
    if ($value === '') {
        return '';
    }
    $parts = parse_url($value);
    if (!is_array($parts) || strtolower((string)($parts['scheme'] ?? '')) !== 'https' || empty($parts['host'])) {
        return '';
    }
    return $value;
}

function ecc_display_map_image_name(): string {
    $storedName = trim(ecc_display_setting('ecc_display_map_image'));
    return preg_match('/^ecc-map-[a-f0-9]{32}\.(?:jpg|png|bmp)$/', $storedName) ? $storedName : '';
}

function ecc_display_map_image_configured(): bool {
    $storedName = ecc_display_map_image_name();
    if ($storedName === '') {
        return false;
    }
    return is_file(dirname(__DIR__) . '/uploads/ecc_display_maps/' . $storedName);
}

function ecc_display_current_op(int $incidentId): ?array {
    if ($incidentId <= 0) {
        return null;
    }
    $stmt = db()->prepare("
        SELECT *
        FROM operational_periods
        WHERE incident_id=? AND deleted_at IS NULL
        ORDER BY CASE WHEN status='active' THEN 0 ELSE 1 END, op_start DESC, id DESC
        LIMIT 1
    ");
    $stmt->execute([$incidentId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function ecc_display_data(): array {
    $incident = active_incident();
    $incidentId = (int)($incident['id'] ?? 0);
    $op = ecc_display_current_op($incidentId);
    $opId = (int)($op['id'] ?? 0);
    $resources = [];
    $meetings = [];

    if ($incidentId > 0 && $opId > 0) {
        try {
            $stmt = db()->prepare("
                SELECT e.id, e.kind, e.type, e.location, e.status
                FROM form_218_entries e
                INNER JOIN form_218_headers h ON h.id=e.header_id
                INNER JOIN form_records f ON f.id=h.form_record_id
                WHERE f.incident_id=? AND f.operational_period_id=?
                  AND UPPER(f.form_type)='ICS218' AND f.is_deleted=0
                  AND e.deleted_at IS NULL
                ORDER BY e.id ASC
            ");
            $stmt->execute([$incidentId, $opId]);
            $resources = $stmt->fetchAll() ?: [];
        } catch (Throwable $e) {
            $resources = [];
        }

        try {
            $stmt = db()->prepare("
                SELECT m.id, m.title, m.scheduled_at, m.location, m.status,
                       COALESCE(NULLIF(mt.name,''), m.title) AS display_title
                FROM operational_period_meetings m
                LEFT JOIN meeting_types mt ON mt.id=m.meeting_type_id
                WHERE m.incident_id=? AND m.operational_period_id=? AND m.deleted_at IS NULL
                ORDER BY m.scheduled_at ASC, m.display_order ASC, m.id ASC
            ");
            $stmt->execute([$incidentId, $opId]);
            $meetings = $stmt->fetchAll() ?: [];
        } catch (Throwable $e) {
            $meetings = [];
        }
    }

    $announcementTitle = trim(ecc_display_setting('ecc_display_announcement_title'));
    $announcementBody = trim(ecc_display_setting('ecc_display_announcement_body'));
    $announcementLevel = strtolower(trim(ecc_display_setting('ecc_display_announcement_level', 'information')));
    if (!in_array($announcementLevel, ['information', 'priority', 'critical'], true)) {
        $announcementLevel = 'information';
    }

    return [
        'incident' => $incident,
        'operational_period' => $op,
        'resources' => $resources,
        'meetings' => $meetings,
        'announcement' => [
            'title' => $announcementTitle,
            'body' => $announcementBody,
            'level' => $announcementLevel,
        ],
        'sources' => [
            'map' => [
                'title' => ecc_display_setting('ecc_display_map_title', 'Map'),
                'has_image' => ecc_display_map_image_configured(),
            ],
            'weather' => [
                'title' => ecc_display_setting('ecc_display_weather_title', 'Weather'),
            ],
            'roads' => [
                'title' => ecc_display_setting('ecc_display_roads_title', 'Road Closures'),
                'text' => trim(ecc_display_setting('ecc_display_roads_text')),
            ],
        ],
    ];
}

function ecc_display_status_label(string $status): string {
    return ucwords(str_replace('_', ' ', trim($status) ?: 'unknown'));
}

function ecc_display_resource_label(array $resource): string {
    $label = trim((string)($resource['kind'] ?? '') . ' / ' . (string)($resource['type'] ?? ''), ' /');
    return $label !== '' ? $label : 'Resource #' . (int)($resource['id'] ?? 0);
}

function ecc_display_operational_period_label(?array $op): string {
    if (!$op) {
        return 'No active operational period';
    }
    $label = trim((string)($op['period_name'] ?? ''));
    if ($label !== '') {
        return $label;
    }
    return 'Operational Period ' . (int)($op['id'] ?? 0);
}

function ecc_display_render_page(string $orientation): void {
    $orientation = $orientation === 'portrait' ? 'portrait' : 'landscape';
    $data = ecc_display_data();
    $branding = branding_settings();
    $incidentName = (string)($data['incident']['name'] ?? 'No active incident');
    $incidentNumber = trim((string)($data['incident']['incident_number'] ?? ''));
    $title = $orientation === 'portrait' ? 'ECC Display - Portrait' : 'ECC Display - Landscape';

    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('X-Robots-Tag: noindex, nofollow');
    ?>
<!doctype html>
<html lang="en" class="ecc-display ecc-display-<?= e($orientation) ?>">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= e($title) ?> - <?= e($branding['application_name']) ?></title>
  <link rel="stylesheet" href="<?= e(app_url('/assets/css/ecc_display.css')) ?>">
</head>
<body data-ecc-default-tab="resl">
<main class="ecc-shell">
  <header class="ecc-header">
    <div class="ecc-heading">
      <div class="ecc-app-name"><?= e($branding['application_name']) ?></div>
      <div class="ecc-incident"><?= e($incidentName) ?><?= $incidentNumber !== '' ? ' (' . e($incidentNumber) . ')' : '' ?></div>
      <div class="ecc-op"><?= e(ecc_display_operational_period_label($data['operational_period'])) ?></div>
    </div>
    <div class="ecc-header-weather" data-ecc-header-weather hidden aria-live="polite">
      <span class="ecc-header-weather-label">Current Weather</span>
      <span class="ecc-header-weather-value" data-ecc-header-weather-value></span>
    </div>
    <div class="ecc-clock" aria-live="polite"></div>
  </header>

  <nav class="ecc-tabs" aria-label="ECC display sections">
    <button type="button" class="ecc-tab is-active" data-ecc-tab="resl">RESL</button>
    <button type="button" class="ecc-tab" data-ecc-tab="meetings">Meeting Schedule</button>
    <button type="button" class="ecc-tab" data-ecc-tab="announcements">Announcements</button>
    <button type="button" class="ecc-tab" data-ecc-tab="map"><?= e($data['sources']['map']['title'] !== '' ? $data['sources']['map']['title'] : 'Map') ?></button>
    <button type="button" class="ecc-tab" data-ecc-tab="weather"><?= e($data['sources']['weather']['title'] !== '' ? $data['sources']['weather']['title'] : 'Weather') ?></button>
    <button type="button" class="ecc-tab" data-ecc-tab="roads"><?= e($data['sources']['roads']['title'] !== '' ? $data['sources']['roads']['title'] : 'Road Closures') ?></button>
  </nav>

  <section class="ecc-pane is-active" data-ecc-pane="resl">
    <div class="ecc-pane-heading">
      <h1>RESL Resource Status</h1>
      <span><?= count($data['resources']) ?> resource<?= count($data['resources']) === 1 ? '' : 's' ?></span>
    </div>
    <?php if ($data['resources']): ?>
      <div class="ecc-resource-grid">
        <?php foreach ($data['resources'] as $resource): ?>
          <article class="ecc-resource-card">
            <div class="ecc-resource-status status-<?= e(strtolower(str_replace('_', '-', (string)($resource['status'] ?? 'unknown')))) ?>"><?= e(ecc_display_status_label((string)($resource['status'] ?? ''))) ?></div>
            <h2><?= e(ecc_display_resource_label($resource)) ?></h2>
            <p><span>Location</span><?= e((string)($resource['location'] ?? 'Not recorded')) ?></p>
          </article>
        <?php endforeach; ?>
      </div>
    <?php else: ?>
      <div class="ecc-empty">No non-sensitive ICS 218 resource data is available for the active operational period.</div>
    <?php endif; ?>
  </section>

  <section class="ecc-pane" data-ecc-pane="meetings" hidden>
    <div class="ecc-pane-heading"><h1>Meeting Schedule</h1><span>Current operational period</span></div>
    <?php if ($data['meetings']): ?>
      <?php
        // The last meeting marked completed stays visible at the top; older ones are dimmed above it.
        $lastHeldIndex = -1;
        foreach (array_values($data['meetings']) as $mi => $m) {
            if (strtolower((string)($m['status'] ?? '')) === 'completed') { $lastHeldIndex = $mi; }
        }
      ?>
      <div class="ecc-meeting-list">
        <?php foreach (array_values($data['meetings']) as $mi => $meeting): ?>
          <article class="ecc-meeting-card<?= $mi < $lastHeldIndex ? ' is-earlier' : '' ?><?= $mi === $lastHeldIndex ? ' is-last-held' : '' ?>"<?= $mi === $lastHeldIndex ? ' data-ecc-last-held' : '' ?>>
            <time datetime="<?= e((string)($meeting['scheduled_at'] ?? '')) ?>"><?= e(date('D, M j · H:i', strtotime((string)($meeting['scheduled_at'] ?? '')) ?: time())) ?></time>
            <div>
              <h2><?= e((string)($meeting['display_title'] ?: 'Meeting')) ?></h2>
              <p><?= e((string)($meeting['location'] ?: 'Location to be confirmed')) ?></p>
            </div>
            <div class="ecc-meeting-status"><?= $mi === $lastHeldIndex ? 'Last meeting held' : e(ecc_display_status_label((string)($meeting['status'] ?? 'scheduled'))) ?></div>
          </article>
        <?php endforeach; ?>
      </div>
    <?php else: ?>
      <div class="ecc-empty">No meetings have been scheduled for the active operational period.</div>
    <?php endif; ?>
  </section>

  <section class="ecc-pane" data-ecc-pane="announcements" hidden>
    <div class="ecc-pane-heading"><h1>Announcements</h1><span>ECC display notices</span></div>
    <?php if ($data['announcement']['title'] !== '' || $data['announcement']['body'] !== ''): ?>
      <article class="ecc-announcement level-<?= e($data['announcement']['level']) ?>">
        <?php if ($data['announcement']['title'] !== ''): ?><h2><?= e($data['announcement']['title']) ?></h2><?php endif; ?>
        <?php if ($data['announcement']['body'] !== ''): ?><p><?= nl2br(e($data['announcement']['body'])) ?></p><?php endif; ?>
      </article>
    <?php else: ?>
      <div class="ecc-empty">No active ECC display announcement has been posted.</div>
    <?php endif; ?>
  </section>

  <section class="ecc-pane ecc-source-pane ecc-map-pane" data-ecc-pane="map" hidden>
    <div class="ecc-pane-heading"><h1><?= e($data['sources']['map']['title'] !== '' ? $data['sources']['map']['title'] : 'Map') ?></h1></div>
    <?php if (!empty($data['sources']['map']['has_image'])): ?>
      <article class="ecc-source-card ecc-map-card">
        <div class="ecc-map-panel">
          <img class="ecc-map-image" src="<?= e(app_url('/ecc_display/map_image.php')) ?>" alt="<?= e($data['sources']['map']['title']) ?>">
        </div>
      </article>
    <?php else: ?>
      <div class="ecc-empty">No map has been configured.</div>
    <?php endif; ?>
  </section>

  <section class="ecc-pane ecc-source-pane" data-ecc-pane="weather" hidden>
    <div class="ecc-pane-heading"><h1><?= e($data['sources']['weather']['title'] !== '' ? $data['sources']['weather']['title'] : 'Weather') ?></h1></div>
    <article class="ecc-source-card ecc-weather-card">
      <div class="ecc-weather-panel" data-ecc-weather-url="<?= e(app_url('/ecc_display/weather.php')) ?>">
        <p class="ecc-weather-loading">Loading current conditions and 12-hour forecast…</p>
      </div>
    </article>
  </section>

  <section class="ecc-pane ecc-source-pane" data-ecc-pane="roads" hidden>
    <div class="ecc-pane-heading"><h1><?= e($data['sources']['roads']['title'] !== '' ? $data['sources']['roads']['title'] : 'Road Closures') ?></h1></div>
    <?php if (($data['sources']['roads']['text'] ?? '') !== ''): ?>
      <article class="ecc-source-card ecc-roads-card">
        <div class="ecc-road-closures"><?= nl2br(e((string)$data['sources']['roads']['text'])) ?></div>
      </article>
    <?php else: ?>
      <div class="ecc-empty">No road closure information has been configured.</div>
    <?php endif; ?>
  </section>
</main>
<script src="<?= e(app_url('/assets/js/ecc_display.js')) ?>"></script>
<script>
(function () {
  function initialiseHeaderWeather() {
    var headerWeather = document.querySelector('[data-ecc-header-weather]');
    var headerWeatherValue = document.querySelector('[data-ecc-header-weather-value]');
    var weatherPanel = document.querySelector('.ecc-weather-panel');
    if (!headerWeather || !headerWeatherValue || !weatherPanel) {
      return;
    }

    function updateHeaderWeather() {
      var temperature = weatherPanel.querySelector('.ecc-weather-temperature');
      var condition = weatherPanel.querySelector('.ecc-weather-condition');
      if (!temperature || !condition) {
        return;
      }

      var temperatureText = (temperature.textContent || '').trim();
      var conditionText = (condition.textContent || '').trim();
      if (temperatureText === '' || conditionText === '') {
        return;
      }

      headerWeatherValue.textContent = temperatureText + ' · ' + conditionText;
      headerWeather.hidden = false;
    }

    updateHeaderWeather();
    new MutationObserver(updateHeaderWeather).observe(weatherPanel, {
      childList: true,
      subtree: true,
      characterData: true
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initialiseHeaderWeather);
  } else {
    initialiseHeaderWeather();
  }
}());
</script>
</body>
</html>
<?php
}
