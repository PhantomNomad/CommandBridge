<?php
declare(strict_types=1);

require_once __DIR__ . '/settings_helpers.php';
require_once dirname(__DIR__) . '/includes/mail.php';

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

        foreach ($set as $key => $value) {
            save_app_setting($key, $value);
        }

        foreach (['smtp_password', 'email_oauth_client_secret', 'email_graph_client_secret'] as $key) {
            if (trim($_POST[$key] ?? '') !== '') {
                save_app_setting($key, $_POST[$key]);
            }
        }

        if (!filter_var($set['smtp_sender_address'], FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('Enter a valid sender email address.');
        }

        audit_log(
            (int)(current_user()['id'] ?? 0),
            'email_delivery_settings_updated',
            'settings',
            0,
            'Email delivery configuration updated.'
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
        header('Location: ' . app_url('/admin/smtp.php'));
        exit;
    }
}

$config = cb_email_config();
$hasSetting = static fn(string $key): bool => get_app_setting($key, '') !== '';

render_header('Email Delivery');
settings_menu('smtp');
?>
<div class="page-header">
  <div>
    <h1>Email Delivery</h1>
    <p>All Command Bridge email uses the single active method selected below. PHP local mail transport is not used.</p>
  </div>
</div>

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
    </label>

    <label>Sender email address
      <input name="smtp_sender_address" type="email" required value="<?= e($config['sender_address']) ?>">
    </label>

    <label>Sender display name
      <input name="smtp_sender_name" value="<?= e($config['sender_name']) ?>">
    </label>
  </section>

  <section class="card smtp-fields">
    <h2>SMTP connection</h2>

    <label>SMTP host
      <input name="smtp_host" value="<?= e($config['host']) ?>">
    </label>

    <label>Port
      <input name="smtp_port" type="number" min="1" max="65535" value="<?= e((string)$config['port']) ?>">
    </label>

    <label>Encryption
      <select name="smtp_encryption">
        <option value="tls" <?= $config['encryption'] === 'tls' ? 'selected' : '' ?>>STARTTLS</option>
        <option value="ssl" <?= $config['encryption'] === 'ssl' ? 'selected' : '' ?>>SSL/TLS</option>
        <option value="none" <?= $config['encryption'] === 'none' ? 'selected' : '' ?>>None</option>
      </select>
    </label>

    <label class="auth-fields">Username / mailbox
      <input name="smtp_username" value="<?= e($config['username']) ?>">
    </label>

    <label class="password-fields">Password
      <input name="smtp_password" type="password" placeholder="<?= $hasSetting('smtp_password') ? 'Saved — leave blank to retain' : '' ?>">
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
    </label>
  </section>

  <section class="card">
    <h2>Save and test</h2>

    <label>Test recipient email
      <input type="email" name="test_email" placeholder="it@example.ca">
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

function showDeliveryFields() {
  const value = method.value;
  const showSmtp = value !== 'graph';

  smtpFields.style.display = showSmtp ? '' : 'none';
  authFields.forEach((field) => { field.style.display = value === 'smtp_none' ? 'none' : ''; });
  passwordFields.forEach((field) => { field.style.display = value === 'smtp_password' ? '' : 'none'; });
  oauthFields.style.display = value === 'smtp_oauth2' ? '' : 'none';
  graphFields.style.display = value === 'graph' ? '' : 'none';
}

method.addEventListener('change', showDeliveryFields);
showDeliveryFields();
</script>

<?php render_footer(); ?>
