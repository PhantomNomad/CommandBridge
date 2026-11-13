<?php
declare(strict_types=1);
require_once dirname(dirname(__DIR__)) . '/includes/layout.php';
require_login();
render_header('ICS 205A Help');
?>
<div class="card">
<h2>ICS 205A Help / Instructions</h2>
<p>ICS 205A is the Communications List. Populate from assigned personnel and contact information where possible. It supports phone, satellite, email, and other non-radio communications for the incident organization.</p>
<p class="small">Source: ICS Canada Forms Catalogue.</p>
<p><a href="index.php">Back to ICS 205A</a></p>
</div>
<?php render_footer(); ?>
