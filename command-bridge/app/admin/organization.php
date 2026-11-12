<?php
declare(strict_types=1);
require_once __DIR__ . '/settings_helpers.php';
$settings = branding_settings();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf_token();
    foreach (['application_name','organization_name','header_text','footer_text'] as $key) {
        save_app_setting('branding_' . $key, trim((string)($_POST[$key] ?? '')));
    }
    save_app_setting('branding_theme', in_array($_POST['theme'] ?? '', ['blue','green','grey'], true) ? $_POST['theme'] : 'blue');
    save_app_setting('branding_show_ics_logo', isset($_POST['show_ics_logo']) ? '1' : '0');
    if (!empty($_FILES['organization_logo']['name'])) {
        $file = $_FILES['organization_logo'];
        $allowed = ['image/png'=>'png','image/jpeg'=>'jpg','image/gif'=>'gif','image/webp'=>'webp'];
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || ($file['size'] ?? 0) > 2 * 1024 * 1024) {
            flash('error', 'Logo upload failed. The maximum file size is 2 MB.');
        } else {
            $mime = function_exists('mime_content_type') ? mime_content_type($file['tmp_name']) : '';
            if (!isset($allowed[$mime])) {
                flash('error', 'Logo must be a PNG, JPG, GIF, or WebP image.');
            } else {
                $dir = dirname(__DIR__) . '/storage/branding';
                if (!is_dir($dir)) { mkdir($dir, 0750, true); }
                $name = 'org-logo-' . bin2hex(random_bytes(12)) . '.' . $allowed[$mime];
                if (move_uploaded_file($file['tmp_name'], $dir . '/' . $name)) {
                    $old = get_app_setting('branding_logo_file', '');
                    if ($old && preg_match('/^[a-z0-9.-]+$/i', $old)) { @unlink($dir . '/' . $old); }
                    save_app_setting('branding_logo_file', $name);
                    audit_log((int)current_user()['id'], 'branding_logo_updated', 'settings', 0, 'Organization logo updated');
                } else { flash('error', 'Logo could not be saved.'); }
            }
        }
    }
    audit_log((int)current_user()['id'], 'branding_updated', 'settings', 0, 'Organization branding settings updated');
    flash('success', 'Organization and branding settings saved.');
    header('Location: ' . app_url('/admin/organization.php')); exit;
}
$settings = branding_settings();
render_header('Organization & Branding');
settings_menu('organization');
?>
<div class="card"><h2>Organization & Branding</h2>
<form method="post" enctype="multipart/form-data">
<?= csrf_field() ?>
<label>Application name<input name="application_name" value="<?= e($settings['application_name']) ?>" maxlength="100"></label>
<label>Organization name<input name="organization_name" value="<?= e($settings['organization_name']) ?>" maxlength="150"></label>
<label>Header/subtitle text<input name="header_text" value="<?= e($settings['header_text']) ?>" maxlength="150"></label>
<label>Footer text<textarea name="footer_text" rows="3"><?= e($settings['footer_text']) ?></textarea></label>
<label>Header colour/theme<select name="theme"><option value="blue" <?= $settings['theme']==='blue'?'selected':'' ?>>Blue</option><option value="green" <?= $settings['theme']==='green'?'selected':'' ?>>Green</option><option value="grey" <?= $settings['theme']==='grey'?'selected':'' ?>>Grey</option></select></label>
<label>Organization logo (PNG, JPG, GIF, or WebP; 2 MB maximum)<input type="file" name="organization_logo" accept=".png,.jpg,.jpeg,.gif,.webp,image/png,image/jpeg,image/gif,image/webp"></label>
<?php if ($settings['logo_file']): ?><p>Current logo: <img style="max-height:55px;max-width:180px;vertical-align:middle" src="<?= e(app_url('/brand_asset.php')) ?>" alt="Current organization logo"></p><?php endif; ?>
<label><input type="checkbox" name="show_ics_logo" value="1" <?= $settings['show_ics_logo']?'checked':'' ?>> Display ICS Canada logo</label>
<p class="small">Logo files are renamed server-side and are served only through a validated application route.</p>
<button class="btn" type="submit">Save settings</button>
</form></div>
<?php render_footer(); ?>