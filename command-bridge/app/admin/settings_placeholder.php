<?php
declare(strict_types=1);
require_once __DIR__ . '/settings_helpers.php';

if ((string) ($_GET['area'] ?? '') === 'backup') {
    header('Location: ' . app_url('/admin/backup_restore.php'));
    exit;
}
$area=$_GET['area']??''; $titles=['security'=>'Security & Sessions','retention'=>'Retention & Archive','backup'=>'Backup & Restore']; $title=$titles[$area]??'Settings';
render_header($title); settings_menu($area); ?>
<div class="card"><h2><?=e($title)?></h2><p>This controlled configuration area is reserved for the next implementation phase. Changes affecting account security, statutory retention, restoration, or production backups require documented municipal approval and testing.</p></div>
<?php render_footer(); ?>