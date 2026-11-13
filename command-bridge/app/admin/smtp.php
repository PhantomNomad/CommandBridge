<?php
declare(strict_types=1);

require_once __DIR__ . '/settings_helpers.php';
require_once dirname(__DIR__) . '/includes/mail.php';
require_once dirname(__DIR__) . '/includes/help.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf_token();

    try {
        $method = $_POST['email_delivery_method'] ?? 'smtp_password';
        if (!in_array($method, ['smtp_none', 'smtp_password', 'smtp_oauth2', 'graph'], true)) {
            throw new InvalidArgumentException('Select a valid delivery method.');
        }

        $set = [
            'email_delivery_method' => $method,
            'smtp_host' => trim($_POST['smtp_host'] ?? ''),
            'smtp_port' => trim($_POST['smtp_port'] ?? '587'),
            'smtp_encryption' => $_POST['smtp_encryption'] ?? 'tls',
            'smtp_username' => trim($_POST['smtp_username'] ?? ''),
            'smtp_sender_address' => trim($_POST['smtp_sender_address'] ?? ''),
            'smtp_sender_name' => trim($_POST['smtp_sender_name'] ?? ''),
            'email_oauth_tenant_id' => trim($_POST['email_oauth_tenant_id'] ?? ''),
            'email_oauth_client_id' => trim($_POST['email_oauth_client_id'] ?? ''),
            'email_oauth_scope' => trim($_POST['email_oauth_scope'] ?? 'https://outlook.office365.com/.default'),
            'email_graph_tenant_id' => trim($_POST['email_graph_tenant_id'] ?? ''),
            'email_graph_client_id' => trim($_POST['email_graph_client_id'] ?? ''),
            'email_graph_sender_mailbox' => trim($_POST['email_graph_sender_mailbox'] ?? ''),
        ];

        // Checks run before anything is saved, so a rejected form leaves the working configuration untouched.
        if (!filter_var($set['smtp_sender_address'], FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('Enter a valid sender email address.');
        }
        $isLocal = cb_email_host_is_local($set['smtp_host']);
        if ($method !== 'graph' && $set['smtp_host'] === '') {
            throw new InvalidArgumentException('Enter the SMTP host.');
        }
        if ($method === 'smtp_none' && !$isLocal && empty($_POST['smtp_noauth_confirm'])) {
            throw new InvalidArgumentException('"No authentication" sends to ' . $set['smtp_host'] . ' without signing in. Tick the box to confirm '
                . 'that relay only accepts mail from this server, or choose a method that signs in. See Help for details.');
        }
        if (in_array($method, ['smtp_password', 'smtp_oauth2'], true) && $set['smtp_encryption'] === 'none' && !$isLocal) {
            throw new InvalidArgumentException('Choose STARTTLS or SSL/TLS encryption. With "None" the password or sign-in token '
                . 'would cross the network in readable form.');
        }

        foreach ($set as $key => $value) {
            save_app_setting($key, $value);
        }

        foreach (['smtp_password', 'email_oauth_client_secret', 'email_graph_client_secret'] as $key) {
            if (trim($_POST[$key] ?? '') !== '') {
                save_app_setting($key, $_POST[$key]);
            }
        }

        audit_log(
            (int)(current_user()['id'] ?? 0),
            'email_delivery_settings_updated',
            'settings',
            0,
            'Email delivery configuration updated (method ' . $method . ').'
                . ($method === 'smtp_none' && !$isLocal ? ' Unauthenticated relay to ' . $set['smtp_host'] . ' confirmed by the administrator.' : '')
        );

        if (($_POST['action'] ?? 'save') === 'test') {
            $result = app_send_mail_result(
                trim($_POST['test_email'] ?? ''),
                'Command Bridge email delivery test',
                'This is a test email from Command Bridge.'
            );

            if (!$result['ok']) {
                throw new RuntimeException($result['message']);
            }

            flash('success', 'Configuration saved. Test email accepted for delivery.');
        } else {
            flash('success', 'Email delivery configuration saved.');
        }

        header('Location: ' . app_url('/admin/smtp.php'));
        exit;
    } catch (Throwable $e) {
        flash('error', $e->getMessage());
        // Show what was typed again (never secrets), so a rejected form does not have to be re-entered.
        $_SESSION['smtp_form_retry'] = array_intersect_key($_POST, array_flip([
            'email_delivery_method', 'smtp_host', 'smtp_port', 'smtp_encryption', 'smtp_username', 'smtp_sender_address',
            'smtp_sender_name', 'email_oauth_tenant_id', 'email_oauth_client_id', 'email_oauth_scope',
            'email_graph_tenant_id', 'email_graph_client_id', 'email_graph_sender_mailbox', 'test_email',
        ]));
        header('Location: ' . app_url('/admin/smtp.php'));
        exit;
    }
}

$config = cb_email_config();
$savedConfig = $config;
$retry = $_SESSION['smtp_form_retry'] ?? [];
unset($_SESSION['smtp_form_retry']);
foreach ([
    'method' => 'email_delivery_method', 'host' => 'smtp_host', 'port' => 'smtp_port', 'encryption' => 'smtp_encryption',
    'username' => 'smtp_username', 'sender_address' => 'smtp_sender_address', 'sender_name' => 'smtp_sender_name',
    'oauth_tenant' => 'email_oauth_tenant_id', 'oauth_client_id' => 'email_oauth_client_id', 'oauth_scope' => 'email_oauth_scope',
    'graph_tenant' => 'email_graph_tenant_id', 'graph_client_id' => 'email_graph_client_id', 'graph_mailbox' => 'email_graph_sender_mailbox',
] as $configKey => $postKey) {
    if (isset($retry[$postKey]) && is_string($retry[$postKey])) {
        $config[$configKey] = trim($retry[$postKey]);
    }
}
if (!in_array($config['method'], ['smtp_none', 'smtp_password', 'smtp_oauth2', 'graph'], true)) {
    $config['method'] = $savedConfig['method'];
}
$retryTestEmail = is_string($retry['test_email'] ?? null) ? (string)$retry['test_email'] : '';
$remoteNoAuth = $savedConfig['method'] === 'smtp_none' && $savedConfig['host'] !== '' && !cb_email_host_is_local($savedConfig['host']);
$methodHelp = [
    'graph' => ['admin/smtp#microsoft-graph', 'How to set up Microsoft Graph'],
    'smtp_oauth2' => ['admin/smtp#smtp-oauth2-microsoft-365', 'How to set up SMTP OAuth2 (Microsoft 365)'],
    'smtp_password' => ['admin/smtp#smtp-username-and-password', 'How to set up SMTP with a username and password'],
    'smtp_none' => ['admin/smtp#smtp-relay-without-authentication', 'When "no authentication" is safe'],
];
$hasSetting = static fn(string $key): bool => get_app_setting($key, '') !== '';

render_header('Email Delivery');
settings_menu('smtp');
?>
<div class="page-header">
  <div>
    <h1>Email Delivery</h1>
    <p>All Command Bridge email uses the single active method selected below. PHP local mail transport is not used.
      <a href="<?= e(cb_help_url('admin/smtp#which-method-should-i-use')) ?>" data-help-open="admin/smtp#which-method-should-i-use">Which method should I use?</a></p>
  </div>
</div>

<?php if ($remoteNoAuth): ?>
  <div class="flash error" role="alert">
    Email is sent to <strong><?= e($savedConfig['host']) ?></strong> without signing in. That is only safe if that relay accepts mail from this
    server alone. Consider a method that signs in.
    <a href="<?= e(cb_help_url('admin/smtp#smtp-relay-without-authentication')) ?>" data-help-open="admin/smtp#smtp-relay-without-authentication">Why?</a>
  </div>
<?php endif; ?>

<form method="post" class="settings-form">
  <?= csrf_field() ?>

  <section class="card">
    <h2>Active delivery method</h2>

    <label>Delivery method
      <select name="email_delivery_method" id="method">
        <option value="smtp_none" <?= $config['method'] === 'smtp_none' ? 'selected' : '' ?>>SMTP relay — no authentication</option>
        <option value="smtp_password" <?= $config['method'] === 'smtp_password' ? 'selected' : '' ?>>SMTP relay — username/password</option>
        <option value="smtp_oauth2" <?= $config['method'] === 'smtp_oauth2' ? 'selected' : '' ?>>SMTP relay — OAuth2 / XOAUTH2</option>
        <option value="graph" <?= $config['method'] === 'graph' ? 'selected' : '' ?>>Microsoft Graph API</option>
      </select>
      <span class="field-hint">
        <a id="method-help" href="<?= e(cb_help_url($methodHelp[$config['method']][0])) ?>" data-help-open="<?= e($methodHelp[$config['method']][0]) ?>"><?= e($methodHelp[$config['method']][1]) ?> &rarr;</a>
      </span>
    </label>

    <label class="noauth-confirm" style="display:none">
      <input type="checkbox" name="smtp_noauth_confirm" value="1">
      I confirm this relay only accepts mail from this server (for example by IP address), so it cannot be used by anyone else.
      <span class="field-hint">Not needed when the relay runs on this server (host <code>localhost</code> or <code>127.0.0.1</code>).</span>
    </label>

    <label>Sender email address
      <input name="smtp_sender_address" type="email" required value="<?= e($config['sender_address']) ?>">
      <span class="field-hint">The From address people see. Most providers only allow an address the account or app may send as.</span>
    </label>

    <label>Sender display name
      <input name="smtp_sender_name" value="<?= e($config['sender_name']) ?>">
    </label>
  </section>

  <section class="card smtp-fields">
    <h2>SMTP connection</h2>

    <label>SMTP host
      <input name="smtp_host" value="<?= e($config['host']) ?>" placeholder="smtp.example.ca">
      <span class="field-hint">Microsoft 365: <code>smtp.office365.com</code> &middot; Gmail: <code>smtp.gmail.com</code> &middot; a relay on this server: <code>localhost</code></span>
    </label>

    <label>Port
      <input name="smtp_port" type="number" min="1" max="65535" value="<?= e((string)$config['port']) ?>">
      <span class="field-hint">Usually <strong>587</strong> with STARTTLS, or <strong>465</strong> with SSL/TLS. Port 25 is normally only for a local or internal relay.</span>
    </label>

    <label>Encryption
      <select name="smtp_encryption">
        <option value="tls" <?= $config['encryption'] === 'tls' ? 'selected' : '' ?>>STARTTLS</option>
        <option value="ssl" <?= $config['encryption'] === 'ssl' ? 'selected' : '' ?>>SSL/TLS</option>
        <option value="none" <?= $config['encryption'] === 'none' ? 'selected' : '' ?>>None</option>
      </select>
      <span class="field-hint">Must match the port. The relay's certificate must be valid for the host name above; self-signed certificates are refused.</span>
    </label>

    <label class="auth-fields">Username / mailbox
      <input name="smtp_username" value="<?= e($config['username']) ?>">
      <span class="field-hint">Usually the full email address of the sending mailbox.</span>
    </label>

    <label class="password-fields">Password
      <input name="smtp_password" type="password" autocomplete="new-password" placeholder="<?= $hasSetting('smtp_password') ? 'Saved — leave blank to retain' : '' ?>">
      <span class="field-hint">Gmail and Google Workspace need an <em>app password</em>, not the normal account password.</span>
    </label>
  </section>

  <section class="card oauth-fields">
    <h2>SMTP OAuth2</h2>

    <label>Tenant ID
      <input name="email_oauth_tenant_id" value="<?= e($config['oauth_tenant']) ?>">
    </label>

    <label>Application (client) ID
      <input name="email_oauth_client_id" value="<?= e($config['oauth_client_id']) ?>">
    </label>

    <label>Application client secret
      <input name="email_oauth_client_secret" type="password" placeholder="<?= $hasSetting('email_oauth_client_secret') ? 'Saved — leave blank to retain' : '' ?>">
    </label>

    <label>OAuth scope
      <input name="email_oauth_scope" value="<?= e($config['oauth_scope']) ?>">
      <span class="field-hint">Leave as <code>https://outlook.office365.com/.default</code> for Microsoft 365.</span>
    </label>
  </section>

  <section class="card graph-fields">
    <h2>Microsoft Graph</h2>

    <label>Tenant ID
      <input name="email_graph_tenant_id" value="<?= e($config['graph_tenant']) ?>">
    </label>

    <label>Application (client) ID
      <input name="email_graph_client_id" value="<?= e($config['graph_client_id']) ?>">
    </label>

    <label>Application client secret
      <input name="email_graph_client_secret" type="password" placeholder="<?= $hasSetting('email_graph_client_secret') ? 'Saved — leave blank to retain' : '' ?>">
    </label>

    <label>Sending mailbox
      <input name="email_graph_sender_mailbox" type="email" value="<?= e($config['graph_mailbox']) ?>">
      <span class="field-hint">The mailbox the app sends from, for example a shared mailbox such as <code>commandbridge@example.ca</code>.</span>
    </label>
  </section>

  <section class="card">
    <h2>Save and test</h2>

    <label>Test recipient email
      <input type="email" name="test_email" placeholder="it@example.ca" value="<?= e($retryTestEmail) ?>">
    </label>

    <button class="btn btn-primary" name="action" value="save">Save configuration</button>
    <button class="btn btn-secondary" name="action" value="test">Save and send test email</button>
  </section>
</form>

<script>
const method = document.getElementById('method');
const smtpFields = document.querySelector('.smtp-fields');
const authFields = document.querySelectorAll('.auth-fields');
const passwordFields = document.querySelectorAll('.password-fields');
const oauthFields = document.querySelector('.oauth-fields');
const graphFields = document.querySelector('.graph-fields');
const noauthConfirm = document.querySelector('.noauth-confirm');
const methodHelpLink = document.getElementById('method-help');
const methodHelp = <?= json_encode(array_map(static fn($h) => ['topic' => $h[0], 'label' => $h[1], 'url' => cb_help_url($h[0])], $methodHelp), JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES) ?>;

function showDeliveryFields() {
  const value = method.value;
  const showSmtp = value !== 'graph';

  smtpFields.style.display = showSmtp ? '' : 'none';
  authFields.forEach((field) => { field.style.display = value === 'smtp_none' ? 'none' : ''; });
  passwordFields.forEach((field) => { field.style.display = value === 'smtp_password' ? '' : 'none'; });
  oauthFields.style.display = value === 'smtp_oauth2' ? '' : 'none';
  graphFields.style.display = value === 'graph' ? '' : 'none';
  noauthConfirm.style.display = value === 'smtp_none' ? '' : 'none';
  const help = methodHelp[value];
  if (help) {
    methodHelpLink.dataset.helpOpen = help.topic;
    methodHelpLink.href = help.url;
    methodHelpLink.textContent = help.label + ' \u2192';
  }
}

method.addEventListener('change', showDeliveryFields);
showDeliveryFields();
</script>

<?php render_footer(); ?>
