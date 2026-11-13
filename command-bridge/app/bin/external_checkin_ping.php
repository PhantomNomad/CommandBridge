#!/usr/bin/env php
<?php
declare(strict_types=1);

/*
 * Test the connection to the Check-In Gateway without changing anything.
 *
 *   sudo -u www-data php bin/external_checkin_ping.php
 *
 * Uses the gateway URLs, key ID and shared secret from /etc/commandbridge/external_checkin_sync.env
 * (or the file named by EXTERNAL_CHECKIN_SYNC_ENV_PATH). It works whether or not sync is enabled, so the
 * setup and rotation scripts can test new settings before switching the timer on.
 *
 * Checks, in order: the settings are complete; the gateway answers over trusted HTTPS (health.php);
 * a signed "sync.ping" is accepted (same key ID and secret, clocks within 5 minutes). The ping reads
 * queue counts only; it never collects, changes or deletes check-ins.
 *
 * Prints one line per check ("ok", "warn" or "FAIL") and exits 0 when the connection works, 1 when it does not.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only.\n");
}

require_once dirname(__DIR__) . '/includes/external_checkin_sync.php';

function ping_line(string $level, string $text): void
{
    echo str_pad($level, 5) . $text . PHP_EOL;
}

function ping_fail(string $text): never
{
    ping_line('FAIL', $text);
    exit(1);
}

/** @return array{status:int, body:string, errno:int, error:string, date:?int} */
function ping_http(string $url, ?string $postBody, array $headers, array $config): array
{
    $date = null;
    $curl = curl_init($url);
    $options = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => $config['connect_timeout_seconds'],
        CURLOPT_TIMEOUT => $config['request_timeout_seconds'],
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$date): int {
            if (stripos($line, 'Date:') === 0) {
                $ts = strtotime(trim(substr($line, 5)));
                $date = $ts === false ? null : $ts;
            }
            return strlen($line);
        },
    ];
    if ($postBody !== null) {
        $options[CURLOPT_POST] = true;
        $options[CURLOPT_POSTFIELDS] = $postBody;
    }
    curl_setopt_array($curl, $options);
    $body = curl_exec($curl);
    $result = [
        'status' => (int)curl_getinfo($curl, CURLINFO_HTTP_CODE),
        'body' => is_string($body) ? $body : '',
        'errno' => curl_errno($curl),
        'error' => curl_error($curl),
        'date' => $date,
    ];
    curl_close($curl);
    return $result;
}

function ping_connection_problem(array $r, string $host): string
{
    return match (true) {
        $r['errno'] === CURLE_COULDNT_RESOLVE_HOST => "The gateway's name in {$host} could not be found (DNS). Check the gateway address and this server's DNS settings.",
        $r['errno'] === CURLE_COULDNT_CONNECT => "Could not connect to {$host}. Is the gateway running, and does a firewall allow outbound HTTPS from this server?",
        $r['errno'] === CURLE_OPERATION_TIMEDOUT => "Timed out reaching {$host}. A firewall may be silently dropping outbound HTTPS from this server.",
        in_array($r['errno'], [35, 51, 58, 60], true) => "The gateway's HTTPS certificate is not trusted ({$r['error']}). The gateway needs a certificate this server trusts, such as Let's Encrypt or your own wildcard certificate; a self-signed gateway certificate does not work.",
        default => 'Could not reach the gateway: ' . ($r['error'] !== '' ? $r['error'] : 'unknown error') . '.',
    };
}

if (!function_exists('curl_init')) {
    ping_fail('The PHP cURL extension is missing (apt install php-curl).');
}

// 1. Settings
$config = external_checkin_sync_config();
$pullUrl = $config['gateway_pull_url'];
if ($pullUrl === '' || $config['hmac_key_id'] === '' || $config['hmac_secret'] === '') {
    ping_fail('Check-in settings are incomplete (gateway URLs, key ID or shared secret missing). Run tools/setup_checkin.sh.');
}
$parts = parse_url($pullUrl);
if (!is_array($parts) || strtolower((string)($parts['scheme'] ?? '')) !== 'https' || empty($parts['host'])
    || !preg_match('#/pull\.php$#', (string)($parts['path'] ?? ''))) {
    ping_fail('EXTERNAL_CHECKIN_GATEWAY_PULL_URL must be an https:// address ending in /api/v1/sync/pull.php.');
}
if (strlen($config['hmac_secret']) < 32) {
    ping_fail('The shared secret is shorter than 32 characters.');
}
$host = (string)$parts['host'] . ':' . (isset($parts['port']) ? (int)$parts['port'] : 443);
$base = 'https://' . (string)$parts['host'] . (isset($parts['port']) ? ':' . (int)$parts['port'] : '');
$pingUrl = preg_replace('#/pull\.php$#', '/ping.php', $pullUrl);
ping_line('ok', 'Settings complete (gateway ' . $base . ', key ID ' . $config['hmac_key_id'] . ')'
    . ($config['enabled'] ? '' : ' - sync is not enabled yet'));

// 2. Reachability, trusted HTTPS and the gateway's database (unsigned)
$health = ping_http($base . '/health.php', null, ['Accept: application/json'], $config);
if ($health['errno'] !== 0) {
    ping_fail(ping_connection_problem($health, $host));
}
if ($health['status'] !== 200 || !str_contains($health['body'], '"ok":true')) {
    ping_fail("The gateway answered but reports a problem (health.php returned HTTP {$health['status']}). Check its database and /var/log/apache2/commandbridge-gateway-error.log on the gateway.");
}
ping_line('ok', 'Gateway reachable over trusted HTTPS, and its database is up');

$offset = $health['date'] !== null ? $health['date'] - time() : null;
if ($offset !== null && abs($offset) > 60) {
    ping_line(abs($offset) > $config['request_ttl_seconds'] ? 'FAIL' : 'warn',
        'Clocks differ by about ' . abs($offset) . ' seconds (' . ($offset > 0 ? 'gateway ahead' : 'gateway behind')
        . '). Signed requests are refused beyond ' . $config['request_ttl_seconds'] . ' seconds; check "timedatectl status" on both servers.');
}

// 3. Signed ping: same key ID and secret, clocks in step, gateway sync switched on
$body = external_checkin_json_encode([
    'protocol_version' => EXTERNAL_CHECKIN_PROTOCOL_VERSION,
    'request_id' => bin2hex(random_bytes(24)),
    'action' => 'sync.ping',
]);
$timestamp = (string)time();
$nonce = external_checkin_generate_nonce();
$ping = ping_http($pingUrl, $body, [
    'Content-Type: application/json',
    'Accept: application/json',
    'X-CommandBridge-Key-Id: ' . $config['hmac_key_id'],
    'X-CommandBridge-Timestamp: ' . $timestamp,
    'X-CommandBridge-Nonce: ' . $nonce,
    'X-CommandBridge-Signature: ' . external_checkin_request_signature($config['hmac_secret'], $timestamp, $nonce, $body),
], $config);

if ($ping['errno'] !== 0) {
    ping_fail(ping_connection_problem($ping, $host));
}
switch ($ping['status']) {
    case 200:
        break;
    case 401:
        if ($offset !== null && abs($offset) > $config['request_ttl_seconds']) {
            ping_fail('The gateway refused the signed request because the two clocks are too far apart. Fix the time on both servers ("timedatectl status").');
        }
        ping_fail('The gateway refused the signed request: the key ID or shared secret does not match the gateway\'s.'
            . ' Compare EXTERNAL_CHECKIN_HMAC_KEY_ID and EXTERNAL_CHECKIN_HMAC_SECRET in /etc/commandbridge/checkin_gateway.env on the gateway.');
    case 503:
        ping_fail('The gateway is not accepting sync yet. On the gateway, set GATEWAY_SYNC_ENABLED=1 in /etc/commandbridge/checkin_gateway.env'
            . ' (and check its shared secret is at least 32 characters).');
    case 404:
        ping_fail('The gateway has no connection-test page (ping.php). Update the Check-In Gateway to 0.9.0-beta.2 or newer.');
    default:
        ping_fail("The gateway answered the signed request with HTTP {$ping['status']}.");
}

try {
    $answer = json_decode($ping['body'], true, 8, JSON_THROW_ON_ERROR);
} catch (JsonException) {
    ping_fail('The gateway answered with something that is not JSON. Is the address really the Check-In Gateway?');
}
if (!is_array($answer) || empty($answer['ok'])) {
    ping_fail('The gateway did not accept the connection test.');
}

ping_line('ok', 'Signed connection test accepted: same key ID and shared secret on both servers (gateway '
    . (string)($answer['gateway_version'] ?? '?') . ')');
if (($answer['delivery_mode'] ?? '') !== 'pull') {
    ping_line('warn', 'The gateway is set to "' . (string)($answer['delivery_mode'] ?? '?') . '" delivery. Command Bridge polls it,'
        . ' so set GATEWAY_DELIVERY_MODE=pull in /etc/commandbridge/checkin_gateway.env on the gateway.');
}
$waiting = (int)($answer['checkins_waiting'] ?? 0);
$stuck = (int)($answer['checkins_stuck'] ?? 0);
ping_line('ok', $waiting . ' check-in(s) waiting on the gateway to be collected');
if ($stuck > 0) {
    ping_line('warn', $stuck . ' check-in(s) on the gateway stopped retrying. On the gateway, review them with'
        . ' "sudo php <gateway folder>/bin/requeue_checkins.php --list" and re-send with --all.');
}
exit(0);
