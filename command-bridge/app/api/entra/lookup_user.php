<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/includes/security.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';

header('Content-Type: application/json');

try {
    require_login();
    require_user_management_access();

    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        http_response_code(405);
        echo json_encode(['ok' => false, 'message' => 'Method not allowed.']);
        exit;
    }

    verify_csrf_token();

    $result = entra_lookup_user((string)($_POST['username'] ?? ''));
    if (empty($result['ok'])) {
        http_response_code(400);
    }
    echo json_encode($result);
} catch (Throwable $e) {
    security_log($e, 'entra_lookup_user');
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => 'Lookup failed. Check the server error log for details.']);
}
