<?php
declare(strict_types=1);

/*
 * PIN sign-in throttling for the Check-In Gateway.
 *
 * The gateway has no usernames: a PIN identifies the person, and access is
 * further limited to the location named in the QR/URL. Failures therefore
 * cannot be counted against an account, so they are counted against the
 * location and the source address instead. Three rules apply; the longest
 * remaining wait wins:
 *
 *   1. Per address / per guessed PIN: 5 failures in 15 minutes  -> wait for the
 *      oldest of those five to age out (the original rule, unchanged).
 *   2. Repeat offenders: 15 failures from one address at a location in 24 hours
 *      -> 60 minutes after the most recent failure.
 *   3. Location-wide: 40 failures from any addresses in 15 minutes -> wait for
 *      the oldest of those forty to age out. This stops a distributed guesser
 *      that rotates addresses. It can also pause genuine users at that
 *      location, which is the intended trade-off while someone is attacking it;
 *      an administrator can clear it at once with bin/clear_lockouts.php.
 *
 * Requests made while blocked are not recorded, so waiting always works and an
 * attacker cannot extend a block by hammering it.
 *
 * Functions take the PDO handle first so the logic can be tested without the
 * rest of the application. Times are read from the database clock (NOW()) so
 * they agree with the existing attempted_at values.
 */

const GATEWAY_THROTTLE_IP_MAX = 5;
const GATEWAY_THROTTLE_IP_WINDOW = 900;          // 15 minutes
const GATEWAY_THROTTLE_IP_DAY_MAX = 15;
const GATEWAY_THROTTLE_IP_DAY_WINDOW = 86400;    // 24 hours
const GATEWAY_THROTTLE_IP_DAY_BLOCK = 3600;      // 60 minutes
const GATEWAY_THROTTLE_LOCATION_MAX = 40;
const GATEWAY_THROTTLE_LOCATION_WINDOW = 900;    // 15 minutes
const GATEWAY_THROTTLE_RETENTION_DAYS = 30;

/** Database "now" as a timestamp. Date strings are read as UTC on both sides so DST cannot skew the arithmetic. */
function gateway_throttle_now(PDO $pdo): int
{
    return gateway_throttle_ts((string)$pdo->query('SELECT NOW()')->fetchColumn());
}

function gateway_throttle_ts(string $datetime): int
{
    return (new DateTimeImmutable($datetime, new DateTimeZone('UTC')))->getTimestamp();
}

function gateway_throttle_fmt(int $timestamp): string
{
    return gmdate('Y-m-d H:i:s', $timestamp);
}

/**
 * Newest-first failure times (as timestamps) matching the filter, newest first, at most $limit.
 *
 * @param array<string,mixed> $filter location_id (required), remote_ip, fingerprint (OR'd with remote_ip)
 * @return int[]
 */
function gateway_throttle_recent(PDO $pdo, array $filter, int $since, int $limit): array
{
    $sql = 'SELECT attempted_at FROM gateway_access_attempts WHERE location_id = ? AND attempted_at > ?';
    $params = [(int)$filter['location_id'], gateway_throttle_fmt($since)];

    if (isset($filter['remote_ip'], $filter['fingerprint'])) {
        $sql .= ' AND (remote_ip = ? OR attempted_pin_fingerprint = ?)';
        $params[] = $filter['remote_ip'];
        $params[] = $filter['fingerprint'];
    } elseif (isset($filter['remote_ip'])) {
        $sql .= ' AND remote_ip = ?';
        $params[] = $filter['remote_ip'];
    }

    $sql .= ' ORDER BY attempted_at DESC LIMIT ' . max(1, $limit);
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return array_map('gateway_throttle_ts', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

/**
 * @return array{blocked:bool, retry_after:int, reason:string}
 *   reason is '', 'address', 'repeat', or 'location'
 */
function gateway_throttle_check(PDO $pdo, int $locationId, string $remoteIp, string $fingerprint): array
{
    $now = gateway_throttle_now($pdo);
    $best = ['blocked' => false, 'retry_after' => 0, 'reason' => ''];
    $consider = static function (int $until, string $reason) use (&$best, $now): void {
        if ($until > $now && ($until - $now) > $best['retry_after']) {
            $best = ['blocked' => true, 'retry_after' => $until - $now, 'reason' => $reason];
        }
    };

    // Rule 1: this address, or this exact PIN guess, 5 in 15 minutes.
    $rows = gateway_throttle_recent(
        $pdo,
        ['location_id' => $locationId, 'remote_ip' => $remoteIp, 'fingerprint' => $fingerprint],
        $now - GATEWAY_THROTTLE_IP_WINDOW,
        GATEWAY_THROTTLE_IP_MAX
    );
    if (count($rows) >= GATEWAY_THROTTLE_IP_MAX) {
        $consider($rows[GATEWAY_THROTTLE_IP_MAX - 1] + GATEWAY_THROTTLE_IP_WINDOW, 'address');
    }

    // Rule 2: this address, 15 in 24 hours -> 60 minutes after the latest failure.
    $rows = gateway_throttle_recent(
        $pdo,
        ['location_id' => $locationId, 'remote_ip' => $remoteIp],
        $now - GATEWAY_THROTTLE_IP_DAY_WINDOW,
        GATEWAY_THROTTLE_IP_DAY_MAX
    );
    if (count($rows) >= GATEWAY_THROTTLE_IP_DAY_MAX) {
        $consider($rows[0] + GATEWAY_THROTTLE_IP_DAY_BLOCK, 'repeat');
    }

    // Rule 3: whole location, 40 in 15 minutes.
    $rows = gateway_throttle_recent(
        $pdo,
        ['location_id' => $locationId],
        $now - GATEWAY_THROTTLE_LOCATION_WINDOW,
        GATEWAY_THROTTLE_LOCATION_MAX
    );
    if (count($rows) >= GATEWAY_THROTTLE_LOCATION_MAX) {
        $consider($rows[GATEWAY_THROTTLE_LOCATION_MAX - 1] + GATEWAY_THROTTLE_LOCATION_WINDOW, 'location');
    }

    return $best;
}

function gateway_throttle_record_failure(PDO $pdo, int $locationId, string $remoteIp, string $fingerprint): void
{
    $stmt = $pdo->prepare(
        'INSERT INTO gateway_access_attempts (location_id, remote_ip, attempted_pin_fingerprint, attempted_at)
         VALUES (?, ?, ?, NOW())'
    );
    $stmt->execute([$locationId, $remoteIp, $fingerprint]);

    // Housekeeping: now and then drop attempts older than the retention period.
    if (random_int(1, 50) === 1) {
        $stmt = $pdo->prepare('DELETE FROM gateway_access_attempts WHERE attempted_at < ?');
        $stmt->execute([gateway_throttle_fmt(gateway_throttle_now($pdo) - GATEWAY_THROTTLE_RETENTION_DAYS * 86400)]);
    }
}

/** "about 7 minutes", "about 1 minute", "less than a minute". */
function gateway_throttle_wait_text(int $seconds): string
{
    if ($seconds < 60) {
        return 'less than a minute';
    }
    $minutes = (int)ceil($seconds / 60);
    return 'about ' . $minutes . ' minute' . ($minutes === 1 ? '' : 's');
}

/**
 * Delete failure records so sign-in works again immediately.
 * Filters are optional and combined; with none, everything is cleared.
 * Returns the number of rows removed.
 */
function gateway_throttle_clear(PDO $pdo, ?int $locationId = null, ?string $remoteIp = null): int
{
    $sql = 'DELETE FROM gateway_access_attempts WHERE 1 = 1';
    $params = [];
    if ($locationId !== null) {
        $sql .= ' AND location_id = ?';
        $params[] = $locationId;
    }
    if ($remoteIp !== null) {
        $sql .= ' AND remote_ip = ?';
        $params[] = $remoteIp;
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->rowCount();
}
