<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/includes/layout.php';
require_sysadmin();
require_csrf_for_post();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    save_entra_config($_POST);
    $config = entra_config();

    if (isset($_POST['validate_entra'])) {
        $result = validate_entra_config($config);
        flash($result['ok'] ? 'success' : 'error', $result['message']);
    } else {
        flash('success', 'Microsoft Entra settings saved.');
    }

    header('Location: ' . app_url('/admin/entra_settings.php'));
    exit;
}

$config = entra_config();
$suggestedRedirectUri = suggested_entra_redirect_uri();
render_header('Microsoft Entra Settings');
?>
<div class="card">
  <h2>Microsoft Entra / M365 Settings</h2>
  <p class="small">These settings are stored in the database and override placeholder values in includes/config.php.</p>
  <form method="post">
    <?= csrf_field() ?>
    <label class="checkbox-row"><input type="checkbox" name="entra_enabled" value="1" <?= $config['enabled'] ? 'checked' : '' ?>> Enable Microsoft sign-in</label>
    <label>Tenant ID</label><input name="entra_tenant_id" value="<?= e($config['tenant_id']) ?>">
    <label>Client ID</label><input name="entra_client_id" value="<?= e($config['client_id']) ?>">
    <label>Client Secret</label><input name="entra_client_secret" type="password" value="<?= e($config['client_secret']) ?>">
    <label>Redirect URI</label><input name="entra_redirect_uri" value="<?= e($config['redirect_uri'] ?: $suggestedRedirectUri) ?>">
    <p class="small">Use this exact Redirect URI in the Entra App Registration under Authentication &gt; Web redirect URIs:<br><strong><?= e($config['redirect_uri'] ?: $suggestedRedirectUri) ?></strong></p>
    <label>Scopes</label><input name="entra_scopes" value="<?= e($config['scopes']) ?>">
    <div style="margin-top:16px;">
      <button type="submit">Save Entra Settings</button>
      <button type="submit" name="validate_entra" value="1" class="btn btn-secondary">Save and Validate Settings</button>
      <a class="btn btn-secondary" href="<?= e(app_url('/admin/index.php')) ?>">Back</a>
    </div>
  </form>
  <hr>
  <h3>Brief Entra App Registration Steps</h3>
  <ol class="small">
    <li>In Microsoft Entra admin center, go to App registrations and create a new registration.</li>
    <li>Set Supported account types to your organization only unless external agencies must sign in.</li>
    <li>Add a Web redirect URI exactly matching the value shown above.</li>
    <li>Create a client secret under Certificates &amp; secrets and paste the secret value here.</li>
    <li>Copy the Application (client) ID and Directory (tenant) ID into this screen.</li>
    <li>Save and use Validate Settings to confirm the tenant, client ID, and secret are accepted.</li>
  </ol>
</div>
<?php render_footer(); ?>
