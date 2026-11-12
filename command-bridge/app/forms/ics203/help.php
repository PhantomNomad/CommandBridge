<?php
declare(strict_types=1);
require_once dirname(dirname(__DIR__)) . '/includes/layout.php';
require_login();
render_header('ICS 203 Help');
?>
<div class="card">
<h2>ICS 203 Help / Instructions</h2>
<p>ICS 203 lists the incident organization for the operational period. Use current user and incident-position assignments where possible. Keep it current as a living organization document and align Operations Section entries with approved ICS 204 assignments.</p>
<p>The help content is based on the ICS Canada Forms Catalogue instructions and should be kept adjacent to the form so staff can reference the purpose, preparation, distribution, and block-by-block guidance while completing the page.</p>
<p><a href="index.php">Back to ICS 203</a></p>
</div>
<?php render_footer(); ?>
