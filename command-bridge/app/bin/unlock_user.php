#!/usr/bin/env php
<?php
declare(strict_types=1);

/*
 * Console unlock for a locked-out account, for the case where no administrator
 * can sign in to use the Users page (for example the only TSC admin is locked).
 *
 *   php bin/unlock_user.php <username>             clear the lock only
 *   php bin/unlock_user.php <username> --reset     TSC admin accounts only: also
 *                                                  set a random temporary password
 *                                                  that must be changed at sign-in
 *
 * Run as a user that can read db.env, for example:
 *   sudo -u www-data php /var/www/commandbridge/bin/unlock_user.php admin --reset
 *
 * For a non-admin (PIN) user, use Reset PIN on the Users page so the new PIN is
 * also synchronized to the check-in gateway.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only.\n");
}

require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/login_throttle.php';

$username = '';
$reset = false;
foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--reset') {
        $reset = true;
    } elseif ($arg !== '' && $arg[0] !== '-' && $username === '') {
        $username = $arg;
    } else {
        fwrite(STDERR, "Usage: php bin/unlock_user.php <username> [--reset]\n");
        exit(2);
    }
}
if ($username === '') {
    fwrite(STDERR, "Usage: php bin/unlock_user.php <username> [--reset]\n");
    exit(2);
}

try {
    $pdo = db();
    $stmt = $pdo->prepare('SELECT id, username, is_tsc_admin, auth_provider FROM users WHERE username = ? AND deleted_at IS NULL LIMIT 1');
    $stmt->execute([$username]);
    $user = $stmt->fetch();
    if (!$user) {
        fwrite(STDERR, "No active user named \"{$username}\".\n");
        exit(1);
    }

    $userId = (int)$user['id'];
    $detail = 'Account unlocked from the server console';

    if ($reset) {
        if ((int)$user['is_tsc_admin'] !== 1) {
            fwrite(STDERR, "--reset only applies to TSC admin accounts. Use Reset PIN on the Users page for PIN users.\n");
            exit(1);
        }
        if (($user['auth_provider'] ?? 'local') === 'entra') {
            fwrite(STDERR, "That account signs in with Microsoft Entra; its password is managed there.\n");
            exit(1);
        }
        $alphabet = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $password = '';
        for ($i = 0; $i < 16; $i++) {
            $password .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }
        $stmt = $pdo->prepare('UPDATE users SET password_hash = ?, must_change_pin = 1 WHERE id = ?');
        $stmt->execute([password_hash($password, PASSWORD_DEFAULT), $userId]);
        try { $pdo->prepare('UPDATE users SET credentials_changed_at = UTC_TIMESTAMP() WHERE id = ?')->execute([$userId]); } catch (Throwable $e) { /* column not installed yet */ }
        $detail .= '; temporary password set, change required at next sign-in';
    }

    login_throttle_unlock($pdo, $userId);

    $stmt = $pdo->prepare(
        'INSERT INTO audit_log (incident_id, user_id, entity_type, entity_id, action_type, action_detail)
         VALUES (NULL, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([$userId, 'user', $userId, 'unlock_user', $detail]);

    echo "Unlocked {$user['username']}.\n";
    if ($reset) {
        echo "Temporary password (shown once): {$password}\n";
        echo "It must be changed at the next sign-in.\n";
    }
} catch (Throwable $e) {
    fwrite(STDERR, 'Unlock failed: ' . $e->getMessage() . "\n");
    exit(1);
}
