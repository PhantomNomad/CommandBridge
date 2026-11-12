<?php
declare(strict_types=1);

/*
 * Login throttling and temporary account lockout.
 *
 * Design notes
 *  - Locks expire by themselves, so a bad actor who knows a username cannot
 *    keep the Incident Commander out for the length of an incident. An
 *    administrator can unlock instantly (admin/users.php or bin/unlock_user.php).
 *  - Account lock: LOGIN_MAX_FAILURES wrong secrets in a row lock the account.
 *    Each repeat lock (without a successful sign-in in between) is longer,
 *    following LOGIN_LOCK_MINUTES. The level decays after
 *    LOGIN_LEVEL_DECAY_HOURS without a failure.
 *  - Network throttle: LOGIN_IP_MAX_FAILURES failures from one address inside
 *    LOGIN_IP_WINDOW_MINUTES pause sign-in from that address. This catches one
 *    PIN being sprayed across many usernames. It is deliberately generous
 *    because a whole ECC may share one public address.
 *  - All timestamps written by this file are UTC ("Y-m-d H:i:s") and compared
 *    in PHP, so they do not depend on the database or PHP time zone and are
 *    unaffected by daylight saving changes.
 *  - Every function takes the PDO handle as its first argument so the logic
 *    can be tested without the rest of the application.
 */

const LOGIN_MAX_FAILURES = 5;
const LOGIN_LOCK_MINUTES = [5, 15, 60];
const LOGIN_LEVEL_DECAY_HOURS = 24;
const LOGIN_IP_MAX_FAILURES = 30;
const LOGIN_IP_WINDOW_MINUTES = 15;
const LOGIN_ATTEMPT_RETENTION_DAYS = 30;

/* Valid bcrypt hash of a throwaway string. Verified against when the username
 * is unknown or the account is locked so those paths take as long as a real
 * check and do not reveal which usernames exist. */
const LOGIN_DUMMY_HASH = '$2y$10$4R7uEyGoCRZSOBKPh4YUMOK9P7PQie3X9Zxk3rYCdIrZ6JZiceUM2';

function login_throttle_utc(int $timestamp): string
{
    return gmdate('Y-m-d H:i:s', $timestamp);
}

function login_throttle_parse_utc(?string $value): ?int
{
    if ($value === null || trim($value) === '') {
        return null;
    }
    $ts = strtotime($value . ' UTC');
    return $ts === false ? null : $ts;
}

function login_throttle_client_ip(): string
{
    return substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
}

/** Burn roughly the time of one real check. */
function login_throttle_dummy_verify(string $secret): void
{
    password_verify($secret, LOGIN_DUMMY_HASH);
}

/** Timestamp the account is locked until, or null when it is not locked. */
function login_throttle_user_locked_until(array $user, ?int $now = null): ?int
{
    $now ??= time();
    $until = login_throttle_parse_utc($user['locked_until'] ?? null);
    return ($until !== null && $until > $now) ? $until : null;
}

/** True when this address has used up its recent failure allowance. */
function login_throttle_ip_blocked(PDO $pdo, string $ip, ?int $now = null): bool
{
    if ($ip === '') {
        return false;
    }
    $now ??= time();
    $cutoff = login_throttle_utc($now - LOGIN_IP_WINDOW_MINUTES * 60);
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM login_attempts
          WHERE remote_ip = ? AND succeeded = 0 AND attempted_at > ?'
    );
    $stmt->execute([$ip, $cutoff]);
    return (int)$stmt->fetchColumn() >= LOGIN_IP_MAX_FAILURES;
}

/**
 * Record a failed attempt. Pass $user only when the failure should count
 * against that account (known user, not already locked). Returns
 * ['locked' => bool, 'locked_until' => ?int, 'level' => int].
 */
function login_throttle_record_failure(PDO $pdo, ?array $user, string $username, string $ip, ?int $now = null): array
{
    $now ??= time();
    $nowStr = login_throttle_utc($now);
    $result = ['locked' => false, 'locked_until' => null, 'level' => 0];

    $stmt = $pdo->prepare(
        'INSERT INTO login_attempts (user_id, username_attempted, remote_ip, succeeded, attempted_at)
         VALUES (?, ?, ?, 0, ?)'
    );
    $stmt->execute([$user ? (int)$user['id'] : null, mb_substr($username, 0, 100), $ip, $nowStr]);

    if (!$user) {
        return $result;
    }

    $userId = (int)$user['id'];
    $level = (int)($user['lockout_level'] ?? 0);
    $lastFailed = login_throttle_parse_utc($user['last_failed_login_at'] ?? null);
    if ($lastFailed !== null && ($now - $lastFailed) > LOGIN_LEVEL_DECAY_HOURS * 3600) {
        $level = 0;
    }

    // The (possibly decayed) level is saved now so the decay is not lost on the next failure.
    $stmt = $pdo->prepare(
        'UPDATE users SET failed_login_count = failed_login_count + 1, last_failed_login_at = ?, lockout_level = ? WHERE id = ?'
    );
    $stmt->execute([$nowStr, $level, $userId]);

    $stmt = $pdo->prepare('SELECT failed_login_count FROM users WHERE id = ?');
    $stmt->execute([$userId]);
    $count = (int)$stmt->fetchColumn();
    $result['level'] = $level;

    if ($count >= LOGIN_MAX_FAILURES) {
        $minutes = LOGIN_LOCK_MINUTES[min($level, count(LOGIN_LOCK_MINUTES) - 1)];
        $until = $now + $minutes * 60;
        // The count condition means two simultaneous requests cannot lock twice.
        $stmt = $pdo->prepare(
            'UPDATE users SET locked_until = ?, lockout_level = ?, failed_login_count = 0
              WHERE id = ? AND failed_login_count >= ?'
        );
        $stmt->execute([login_throttle_utc($until), $level + 1, $userId, LOGIN_MAX_FAILURES]);
        if ($stmt->rowCount() > 0) {
            $result = ['locked' => true, 'locked_until' => $until, 'level' => $level + 1];
        }
    }

    return $result;
}

/** Clear the account's counters after a successful sign-in and log it. */
function login_throttle_record_success(PDO $pdo, array $user, string $ip, ?int $now = null): void
{
    $now ??= time();
    $userId = (int)$user['id'];

    $stmt = $pdo->prepare(
        'UPDATE users SET failed_login_count = 0, lockout_level = 0, locked_until = NULL WHERE id = ?'
    );
    $stmt->execute([$userId]);

    $stmt = $pdo->prepare(
        'INSERT INTO login_attempts (user_id, username_attempted, remote_ip, succeeded, attempted_at)
         VALUES (?, ?, ?, 1, ?)'
    );
    $stmt->execute([$userId, mb_substr((string)($user['username'] ?? ''), 0, 100), $ip, login_throttle_utc($now)]);

    // Housekeeping: now and then drop attempts older than the retention period.
    if (random_int(1, 50) === 1) {
        $stmt = $pdo->prepare('DELETE FROM login_attempts WHERE attempted_at < ?');
        $stmt->execute([login_throttle_utc($now - LOGIN_ATTEMPT_RETENTION_DAYS * 86400)]);
    }
}

/** Administrator unlock: clears the lock and the failure counters. */
function login_throttle_unlock(PDO $pdo, int $userId): void
{
    $stmt = $pdo->prepare(
        'UPDATE users SET failed_login_count = 0, lockout_level = 0, locked_until = NULL WHERE id = ?'
    );
    $stmt->execute([$userId]);
}

function login_throttle_count_locked(PDO $pdo, ?int $now = null): int
{
    $now ??= time();
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM users WHERE deleted_at IS NULL AND locked_until > ?');
    $stmt->execute([login_throttle_utc($now)]);
    return (int)$stmt->fetchColumn();
}

/** "5 minutes", "1 minute", "less than a minute". */
function login_throttle_format_remaining(int $until, ?int $now = null): string
{
    $now ??= time();
    $seconds = max(0, $until - $now);
    if ($seconds < 60) {
        return 'less than a minute';
    }
    $minutes = (int)ceil($seconds / 60);
    return $minutes . ' minute' . ($minutes === 1 ? '' : 's');
}

/** Obvious PINs people pick that a short lockout cannot protect. */
function pin_is_too_obvious(string $pin): bool
{
    if (!preg_match('/^\d{6}$/', $pin)) {
        return false;
    }
    if (count(array_unique(str_split($pin))) === 1) {
        return true; // 000000, 111111 ...
    }
    if (str_contains('01234567890', $pin) || str_contains('09876543210', $pin)) {
        return true; // 123456, 654321 ...
    }
    return in_array($pin, ['121212', '123123', '112233', '696969', '202020', '123321'], true);
}
