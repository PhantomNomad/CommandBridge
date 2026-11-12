<?php
/**
 * Command Bridge email delivery service.
 * Uses one active delivery method selected in Admin > Settings > Email Delivery.
 * PHP's local mail() transport is intentionally not used.
 */
require_once __DIR__ . '/auth.php';

function cb_email_config(): array {
    $get = static fn($key, $default = '') => get_app_setting($key, $default);
    $method = $get('email_delivery_method', 'smtp_password');
    if (!in_array($method, ['smtp_none','smtp_password','smtp_oauth2','graph'], true)) $method = 'smtp_password';
    return [
        'method' => $method,
        'host' => trim($get('smtp_host')),
        'port' => max(1, min(65535, (int)$get('smtp_port', '587'))),
        'encryption' => $get('smtp_encryption', 'tls'),
        'username' => trim($get('smtp_username')),
        'password' => (string)$get('smtp_password'),
        'sender_address' => trim($get('smtp_sender_address', MAIL_FROM_ADDRESS)),
        'sender_name' => trim($get('smtp_sender_name', MAIL_FROM_NAME)),
        'oauth_tenant' => trim($get('email_oauth_tenant_id')),
        'oauth_client_id' => trim($get('email_oauth_client_id')),
        'oauth_secret' => (string)$get('email_oauth_client_secret'),
        'oauth_scope' => trim($get('email_oauth_scope', 'https://outlook.office365.com/.default')),
        'graph_tenant' => trim($get('email_graph_tenant_id')),
        'graph_client_id' => trim($get('email_graph_client_id')),
        'graph_secret' => (string)$get('email_graph_client_secret'),
        'graph_mailbox' => trim($get('email_graph_sender_mailbox')),
    ];
}
function cb_email_error(string $message): array { error_log('Command Bridge email: ' . $message); return ['ok'=>false,'message'=>$message]; }
function cb_email_clean(string $value): string { return trim(str_replace(["\r","\n"], '', $value)); }
function cb_token(string $tenant, string $client, string $secret, string $scope): array {
    if (!function_exists('curl_init')) return cb_email_error('The PHP cURL extension is required for OAuth2 and Microsoft Graph.');
    $ch=curl_init('https://login.microsoftonline.com/'.rawurlencode($tenant).'/oauth2/v2.0/token');
    curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>http_build_query(['client_id'=>$client,'client_secret'=>$secret,'scope'=>$scope,'grant_type'=>'client_credentials']),CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>20]);
    $body=curl_exec($ch); $code=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE); curl_close($ch);
    $json=json_decode((string)$body,true);
    return ($code>=200 && $code<300 && !empty($json['access_token'])) ? ['ok'=>true,'token'=>$json['access_token']] : cb_email_error('Microsoft token request failed (HTTP '.$code.').');
}
function cb_smtp_read($socket): array {
    $line=''; $code=0;
    do { $line=fgets($socket, 4096); if ($line===false) return [0,'No response from SMTP relay.']; $code=(int)substr($line,0,3); } while (isset($line[3]) && $line[3]==='-');
    return [$code,trim($line)];
}
function cb_smtp_cmd($socket, string $command, array $expected): array {
    if (fwrite($socket,$command."\r\n")===false) return [false,'Unable to write to SMTP relay.'];
    [$code,$line]=cb_smtp_read($socket);
    return [in_array($code,$expected,true),'SMTP relay rejected the request ('.$code.').'];
}
function cb_smtp_send(array $c,string $to,string $subject,string $body): array {
    if ($c['host']==='' || !filter_var($c['sender_address'],FILTER_VALIDATE_EMAIL)) return cb_email_error('SMTP host or sender address is not configured.');
    $scheme=$c['encryption']==='ssl'?'ssl://':'tcp://';
    $ctx=stream_context_create(['ssl'=>['verify_peer'=>true,'verify_peer_name'=>true,'allow_self_signed'=>false]]);
    $err=''; $socket=@stream_socket_client($scheme.$c['host'].':'.$c['port'],$errno,$err,20,STREAM_CLIENT_CONNECT,$ctx);
    if (!$socket) return cb_email_error('Unable to connect to the configured SMTP relay.');
    stream_set_timeout($socket,20); [$code]=cb_smtp_read($socket); if($code!==220){fclose($socket);return cb_email_error('SMTP relay did not accept the connection.');}
    $hello=gethostname() ?: 'command-bridge';
    [$ok,$msg]=cb_smtp_cmd($socket,'EHLO '.$hello,[250]); if(!$ok){fclose($socket);return cb_email_error($msg);}
    if($c['encryption']==='tls'){[$ok,$msg]=cb_smtp_cmd($socket,'STARTTLS',[220]); if(!$ok){fclose($socket);return cb_email_error($msg);} if(!@stream_socket_enable_crypto($socket,true,STREAM_CRYPTO_METHOD_TLS_CLIENT)){fclose($socket);return cb_email_error('Unable to establish TLS with SMTP relay.');} [$ok,$msg]=cb_smtp_cmd($socket,'EHLO '.$hello,[250]); if(!$ok){fclose($socket);return cb_email_error($msg);}}
    if($c['method']==='smtp_password'){
        [$ok,$msg]=cb_smtp_cmd($socket,'AUTH LOGIN',[334]); if(!$ok){fclose($socket);return cb_email_error($msg);}
        [$ok,$msg]=cb_smtp_cmd($socket,base64_encode($c['username']),[334]); if(!$ok){fclose($socket);return cb_email_error($msg);}
        [$ok,$msg]=cb_smtp_cmd($socket,base64_encode($c['password']),[235]); if(!$ok){fclose($socket);return cb_email_error($msg);}
    } elseif($c['method']==='smtp_oauth2'){
        $t=cb_token($c['oauth_tenant'],$c['oauth_client_id'],$c['oauth_secret'],$c['oauth_scope']); if(!$t['ok']){fclose($socket);return $t;}
        $u=$c['username'] ?: $c['sender_address']; $xo=base64_encode("user={$u}\x01auth=Bearer {$t['token']}\x01\x01");
        [$ok,$msg]=cb_smtp_cmd($socket,'AUTH XOAUTH2 '.$xo,[235]); if(!$ok){fclose($socket);return cb_email_error($msg);}
    }
    foreach ([['MAIL FROM:<'.$c['sender_address'].'>',[250]],['RCPT TO:<'.$to.'>',[250,251]],['DATA',[354]]] as [$cmd,$valid]) {[$ok,$msg]=cb_smtp_cmd($socket,$cmd,$valid);if(!$ok){fclose($socket);return cb_email_error($msg);}}
    $headers='From: '.cb_email_clean($c['sender_name']).' <'.$c['sender_address'].">\r\n".'To: <'.$to.">\r\n".'Subject: '.cb_email_clean($subject)."\r\n".'MIME-Version: 1.0'."\r\n".'Content-Type: text/plain; charset=UTF-8'."\r\n";
    $data=$headers."\r\n".str_replace("\n.", "\n..", str_replace("\r\n","\n",$body))."\r\n.";
    [$ok,$msg]=cb_smtp_cmd($socket,$data,[250]); @cb_smtp_cmd($socket,'QUIT',[221]); fclose($socket);
    return $ok ? ['ok'=>true,'message'=>'Email accepted by SMTP relay.'] : cb_email_error($msg);
}
function cb_graph_send(array $c,string $to,string $subject,string $body): array {
    if ($c['graph_tenant']===''||$c['graph_client_id']===''||$c['graph_secret']===''||!filter_var($c['graph_mailbox'],FILTER_VALIDATE_EMAIL)) return cb_email_error('Microsoft Graph settings are incomplete.');
    $t=cb_token($c['graph_tenant'],$c['graph_client_id'],$c['graph_secret'],'https://graph.microsoft.com/.default'); if(!$t['ok']) return $t;
    $payload=['message'=>['subject'=>cb_email_clean($subject),'body'=>['contentType'=>'Text','content'=>$body],'toRecipients'=>[['emailAddress'=>['address'=>$to]]]],'saveToSentItems'=>false];
    $ch=curl_init('https://graph.microsoft.com/v1.0/users/'.rawurlencode($c['graph_mailbox']).'/sendMail');
    curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>json_encode($payload),CURLOPT_HTTPHEADER=>['Authorization: Bearer '.$t['token'],'Content-Type: application/json'],CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>20]);
    curl_exec($ch);$code=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);
    return $code===202 ? ['ok'=>true,'message'=>'Email accepted by Microsoft Graph.'] : cb_email_error('Microsoft Graph rejected the email (HTTP '.$code.').');
}
function app_send_mail_result(string $to,string $subject,string $body): array {
    if(!filter_var($to,FILTER_VALIDATE_EMAIL)) return cb_email_error('Recipient email address is invalid.');
    $c=cb_email_config(); return $c['method']==='graph' ? cb_graph_send($c,$to,$subject,$body) : cb_smtp_send($c,$to,$subject,$body);
}
function app_send_mail(string $to,string $subject,string $body): bool { return app_send_mail_result($to,$subject,$body)['ok']; }

/**
 * Sends initial local-account credentials after an administrator creates a user.
 * Delivery uses the configured Command Bridge email method.
 */
function send_new_user_pin_email(string $to, string $realName, string $username, string $pin): bool {
    $displayName = trim($realName);
    $greeting = $displayName !== '' ? 'Hello ' . $displayName . ',' : 'Hello,';

    $body = $greeting . "\n\n"
        . "An account has been created for you in Command Bridge.\n\n"
        . "Username: " . $username . "\n"
        . "PIN: " . $pin . "\n\n"
        . "For security, keep this PIN confidential and change it if your local procedures require it.\n\n"
        . "If you were not expecting this message, please contact your Command Bridge administrator.";

    return app_send_mail($to, 'Your Command Bridge username and PIN', $body);
}

/**
 * Sends a replacement local-account PIN after an administrator resets it.
 * Delivery uses the configured Command Bridge email method.
 */
function send_reset_user_pin_email(string $to, string $realName, string $username, string $pin): bool {
    $displayName = trim($realName);
    $greeting = $displayName !== '' ? 'Hello ' . $displayName . ',' : 'Hello,';

    $body = $greeting . "\n\n"
        . "Your Command Bridge PIN has been reset by an administrator.\n\n"
        . "Username: " . $username . "\n"
        . "New PIN: " . $pin . "\n\n"
        . "For security, keep this PIN confidential and change it if your local procedures require it.\n\n"
        . "If you did not request this reset, please contact your Command Bridge administrator.";

    return app_send_mail($to, 'Your Command Bridge PIN has been reset', $body);
}
