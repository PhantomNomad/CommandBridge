<?php
declare(strict_types=1);
function security_log(Throwable $e, string $context = ''): void {
    $prefix = $context !== '' ? '[' . $context . '] ' : '';
    error_log($prefix . get_class($e) . ': ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
}

function csrf_token(): string {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    if (empty($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_field(): string {
    return '<input type="hidden" name="csrf_token" value="' .
        htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') . '">';
}

function verify_csrf_token(): void {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    $submitted = $_POST['csrf_token'] ?? '';
    $expected = $_SESSION['csrf_token'] ?? '';
    if (!is_string($submitted) || !is_string($expected) || $submitted === '' || !hash_equals($expected, $submitted)) {
        http_response_code(403);
        exit('Invalid CSRF token.');
    }
}

function require_csrf_for_post(): void {
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        verify_csrf_token();
    }
}
