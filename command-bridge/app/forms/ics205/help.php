<?php
declare(strict_types=1);
require_once dirname(dirname(__DIR__)) . '/includes/layout.php';
require_login();
render_header('ICS 205 Help');
?>
<div class="card">
<h2>ICS 205 Help / Instructions</h2>
<p>ICS 205 is the Incident Radio Communications Plan. It identifies radio channels/talkgroups, functions, assignments, frequencies or trunked resources, tones/codes, mode, and remarks for the operational period.</p>
<p class="small">Source: ICS Canada Forms Catalogue.</p>
<p><a href="index.php">Back to ICS 205</a></p>
</div>
<?php render_footer(); ?>
