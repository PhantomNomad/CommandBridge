<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';

$config = entra_config();
if (empty($config['enabled'])) {
    http_response_code(404);
    exit;
}

$tenantId = trim((string)($config['tenant_id'] ?? ''));
$clientId = trim((string)($config['client_id'] ?? ''));
$redirectUri = trim((string)($config['redirect_uri'] ?? ''));
$scopes = trim((string)($config['scopes'] ?? 'openid profile email User.Read'));

if ($tenantId === '' || $clientId === '' || $redirectUri === '') {
    http_response_code(500);
    echo 'Microsoft Entra sign-in is not fully configured. Please ask an administrator to check the Entra Tenant ID, Client ID, and Redirect URI settings.';
    exit;
}

$state = bin2hex(random_bytes(24));
$_SESSION['entra_oauth_state'] = $state;

$params = [
    'client_id' => $clientId,
    'response_type' => 'code',
    'redirect_uri' => $redirectUri,
    'response_mode' => 'query',
    'scope' => $scopes,
    'state' => $state,
];

header('Location: https://login.microsoftonline.com/' . rawurlencode($tenantId) . '/oauth2/v2.0/authorize?' . http_build_query($params));
exit;
