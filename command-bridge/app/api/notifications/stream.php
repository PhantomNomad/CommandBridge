<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_login();

header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('X-Accel-Buffering: no');

$user = current_user();
$userId = (int)($user['id'] ?? 0);
$incidentId = (int)($user['incident_id'] ?? 0);
if ($incidentId <= 0) {
    $incidentId = (int)(active_incident()['id'] ?? 0);
}
$lastId = isset($_SERVER['HTTP_LAST_EVENT_ID']) ? (int)$_SERVER['HTTP_LAST_EVENT_ID'] : (int)($_GET['last_id'] ?? 0);

$roleCodes = [];
try { $roleCodes = get_user_role_codes($incidentId, $userId); } catch (Throwable $e) {}
$roleCodes[] = 'ALL_AUTHENTICATED';
$roleCodes = array_values(array_unique(array_filter(array_map('strtoupper', $roleCodes))));

for ($i = 0; $i < 6; $i++) {
    try {
        $params = [$incidentId, $lastId, $userId];
        $roleSql = '';
        if ($roleCodes) {
            $roleSql = ' OR role_code IN (' . implode(',', array_fill(0, count($roleCodes), '?')) . ')';
            $params = array_merge($params, $roleCodes);
        }
        $stmt = db()->prepare("
            SELECT id, title, body, link_url
            FROM notifications
            WHERE incident_id = ?
              AND id > ?
              AND is_read = 0
              AND (user_id = ? $roleSql)
            ORDER BY id ASC
            LIMIT 5
        ");
        $stmt->execute($params);
        $rows = $stmt->fetchAll();
        foreach ($rows as $row) {
            $lastId = (int)$row['id'];
            echo "id: {$lastId}\n";
            echo "event: notification\n";
            echo 'data: ' . json_encode($row, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n\n";
        }
        echo ": heartbeat\n\n";
        @ob_flush(); @flush();
    } catch (Throwable $e) {
        echo "event: error\n";
        echo 'data: {"message":"Notification stream temporarily unavailable"}' . "\n\n";
        @ob_flush(); @flush();
        break;
    }
    sleep(5);
}
