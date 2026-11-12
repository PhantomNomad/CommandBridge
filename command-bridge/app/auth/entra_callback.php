<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/layout.php';
$config = entra_config();

if (empty($config['enabled'])) { http_response_code(404); exit; }
if (($_GET['state'] ?? '') !== ($_SESSION['entra_oauth_state'] ?? '')) { http_response_code(400); echo 'Invalid sign-in state.'; exit; }
unset($_SESSION['entra_oauth_state']);
$code = $_GET['code'] ?? '';
if ($code === '') { http_response_code(400); echo 'Missing authorization code.'; exit; }


$tenantId = trim((string)($config['tenant_id'] ?? ''));
$clientId = trim((string)($config['client_id'] ?? ''));
$clientSecret = trim((string)($config['client_secret'] ?? ''));
$redirectUri = trim((string)($config['redirect_uri'] ?? ''));
$scopes = trim((string)($config['scopes'] ?? 'openid profile email User.Read'));

if ($tenantId === '' || $clientId === '' || $clientSecret === '' || $redirectUri === '') {
    http_response_code(500);
    echo 'Microsoft Entra sign-in is not fully configured. Please ask an administrator to check the Entra Tenant ID, Client ID, Client Secret, and Redirect URI settings.';
    exit;
}

$tokenUrl = 'https://login.microsoftonline.com/' . rawurlencode($tenantId) . '/oauth2/v2.0/token';
$post = http_build_query([
  'client_id' => $clientId,
  'scope' => $scopes,
  'code' => $code,
  'redirect_uri' => $redirectUri,
  'grant_type' => 'authorization_code',
  'client_secret' => $clientSecret,
]);
$ch = curl_init($tokenUrl);
curl_setopt_array($ch, [CURLOPT_POST=>true, CURLOPT_POSTFIELDS=>$post, CURLOPT_RETURNTRANSFER=>true, CURLOPT_HTTPHEADER=>['Content-Type: application/x-www-form-urlencoded']]);
$response = curl_exec($ch);
$status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
$data = json_decode((string)$response, true);
if ($status >= 400 || empty($data['id_token'])) { http_response_code(401); echo 'Microsoft sign-in failed.'; exit; }

$parts = explode('.', $data['id_token']);
$claims = json_decode(base64_decode(strtr($parts[1] ?? '', '-_', '+/')), true);
$oid = $claims['oid'] ?? '';
$upn = $claims['preferred_username'] ?? ($claims['email'] ?? '');
if ($oid === '') { http_response_code(401); echo 'Microsoft sign-in did not return a user ID.'; exit; }

$user = fetch_user_by_entra_object_id($oid);
if (!$user || empty($user['is_active'])) { http_response_code(403); echo 'Your Microsoft account is not assigned to this ICS application. Contact an administrator.'; exit; }

login_user($user);
audit_log((int)$user['id'], 'login', 'user', (int)$user['id'], 'Successful Entra login: ' . $upn);
header('Location: ' . app_url('/dashboard/index.php'));
exit;
