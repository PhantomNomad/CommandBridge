<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/includes/security.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_login();
header('Content-Type: application/json');

// State-changing endpoint: POST only, with the CSRF token (form field csrf_token or X-CSRF-Token header).
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    echo json_encode(['ok' => false, 'error' => 'POST required']);
    exit;
}
if (!isset($_POST['csrf_token']) && isset($_SERVER['HTTP_X_CSRF_TOKEN'])) {
    $_POST['csrf_token'] = (string)$_SERVER['HTTP_X_CSRF_TOKEN'];
}
require_csrf_for_post();

$id = (int)($_POST['id'] ?? 0);
try {
    if ($id > 0) {
        // Only the user's own notifications; shared (user_id NULL) ones are left alone.
        $stmt = db()->prepare("UPDATE notifications SET is_read = 1, read_at = NOW() WHERE id = ? AND user_id = ?");
        $stmt->execute([$id, (int)(current_user()['id'] ?? 0)]);
    }
    echo json_encode(['ok' => true]);
} catch (Throwable $e) {
    echo json_encode(['ok' => false]);
}
