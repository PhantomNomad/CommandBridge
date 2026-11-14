<?php
declare(strict_types=1);

/*
 * Creates the first SysAdmin (system administrator) from the
 * command line. Used by install.sh; also useful for recovery.
 *
 *   CB_ADMIN_PASSWORD='...' php bin/create_admin.php --username=admin --name="Full Name" --email=a@b.c
 *   printf '%s' 'password' | php bin/create_admin.php --username=admin --name="Full Name" --password-stdin
 *
 * The password is never accepted as a command-line argument (it would show in `ps`).
 * Run as the web server user so db.env is readable:
 *   sudo -u www-data php /var/www/commandbridge/app/bin/create_admin.php ...
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only.\n");
}

require_once dirname(__DIR__) . '/includes/db.php';

$opts = getopt('', ['username:', 'name:', 'email::', 'password-stdin']);
$username = trim((string)($opts['username'] ?? ''));
$name = trim((string)($opts['name'] ?? ''));
$email = trim((string)($opts['email'] ?? ''));
$password = isset($opts['password-stdin']) ? rtrim((string)stream_get_contents(STDIN), "\r\n") : (string)getenv('CB_ADMIN_PASSWORD');

if ($username === '' || $name === '' || $password === '') {
    fwrite(STDERR, "Usage: CB_ADMIN_PASSWORD=... php bin/create_admin.php --username=U --name=\"Full Name\" [--email=E]\n");
    exit(2);
}
if (!preg_match('/^[A-Za-z0-9._-]{3,64}$/', $username)) {
    fwrite(STDERR, "Username must be 3-64 characters: letters, digits, dot, underscore or hyphen.\n");
    exit(2);
}
if (strlen($password) < 12) {
    fwrite(STDERR, "Password must be at least 12 characters.\n");
    exit(2);
}
if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "Email address is not valid.\n");
    exit(2);
}

try {
    $pdo = db();
    $exists = $pdo->prepare('SELECT COUNT(*) FROM users WHERE username = ?');
    $exists->execute([$username]);
    if ((int)$exists->fetchColumn() > 0) {
        fwrite(STDERR, "A user named '$username' already exists.\n");
        exit(1);
    }
    $pdo->beginTransaction();
    $pdo->prepare('INSERT INTO users (username, password_hash, real_name, email, is_sysadmin, created_at, updated_at) VALUES (?, ?, ?, ?, 1, NOW(), NOW())')
        ->execute([$username, password_hash($password, PASSWORD_DEFAULT), $name, $email !== '' ? $email : null]);
    $id = (int)$pdo->lastInsertId();
    if ($email !== '') {
        $pdo->prepare("INSERT INTO user_contacts (user_id, contact_type, contact_value, is_primary, created_at, updated_at) VALUES (?, 'email', ?, 1, NOW(), NOW())")
            ->execute([$id, $email]);
    }
    $pdo->commit();
    echo "Administrator '$username' created.\n";
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) { $pdo->rollBack(); }
    fwrite(STDERR, 'Failed: ' . $e->getMessage() . "\n");
    exit(1);
}
