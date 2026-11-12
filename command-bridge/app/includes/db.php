<?php
declare(strict_types=1);
/**
 * Where the database settings live: $DB_ENV_PATH if set, else /etc/commandbridge/db.env (installed by install.sh,
 * outside the web folder), else db.env next to the application (older installs).
 */
function cb_db_env_path(): string {
    $configured = getenv('DB_ENV_PATH');
    if (is_string($configured) && $configured !== '') { return $configured; }
    if (is_file('/etc/commandbridge/db.env')) { return '/etc/commandbridge/db.env'; }
    return dirname(__DIR__) . '/db.env';
}
define('DB_ENV_PATH', cb_db_env_path());

function load_db_env(string $path = DB_ENV_PATH): array {
    if (!file_exists($path)) {
        throw new RuntimeException("DB env file not found at $path");
    }

    if (!is_readable($path)) {
        throw new RuntimeException("DB env file is not readable at $path");
    }

    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        throw new RuntimeException("Failed to read DB env file at $path");
    }

    $vars = [];
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
            continue;
        }
        [$k, $v] = explode('=', $line, 2);
        $vars[trim($k)] = trim($v);
    }

    return $vars;
}

function db(): PDO {
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $env = load_db_env();

    $host = $env['DB_HOST'] ?? '127.0.0.1';
    $dbname = $env['DB_NAME'] ?? 'commandbridge';
    $user = $env['DB_APP_USER'] ?? '';
    $pass = $env['DB_APP_PASSWORD'] ?? '';

    if ($user === '' || $pass === '') {
        throw new RuntimeException('Database credentials are missing from db.env');
    }

    $dsn = "mysql:host={$host};dbname={$dbname};charset=utf8mb4";

    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    return $pdo;
}
