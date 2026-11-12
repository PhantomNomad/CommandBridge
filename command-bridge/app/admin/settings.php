<?php
declare(strict_types=1);
require_once __DIR__ . '/settings_helpers.php';
render_header('Settings');
?>
<div class="card">
<h2>Settings</h2>
<p>System administration settings for the ICS application. Access is restricted to System Administrators.</p>
<?php settings_menu(); ?>
</div>
<?php render_footer(); ?>