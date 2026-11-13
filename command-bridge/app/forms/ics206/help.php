<?php
declare(strict_types=1);
require_once dirname(dirname(__DIR__)) . '/includes/layout.php';
require_login();
render_header('ICS 206 Help');
?>
<div class="card">
<h2>ICS 206 Help / Instructions</h2>
<p>ICS 206 is the Medical Plan. Complete aid stations, ambulance/transportation, hospitals, medical emergency procedures, prepared by, and review/approval fields for the operational period.</p>
<p class="small">Source: ICS Canada Forms Catalogue.</p>
<p><a href="index.php">Back to ICS 206</a></p>
</div>
<?php render_footer(); ?>
