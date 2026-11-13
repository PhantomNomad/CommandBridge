<?php
declare(strict_types=1);
require_once dirname(dirname(__DIR__)) . '/includes/layout.php';
require_login();
render_header('ICS 208 Help');
?>
<div class="card">
<h2>ICS 208 Help / Instructions</h2>
<p>ICS 208 is the Safety Message/Plan. It identifies major hazards, site safety concerns, required PPE, mitigations, emergency procedures, and Safety Officer messages for the operational period.</p>
<p class="small">Source: ICS Canada Forms Catalogue.</p>
<p><a href="index.php">Back to ICS 208</a></p>
</div>
<?php render_footer(); ?>
