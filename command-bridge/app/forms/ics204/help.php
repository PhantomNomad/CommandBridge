<?php
declare(strict_types=1);
require_once dirname(dirname(__DIR__)) . '/includes/layout.php';
require_login();
render_header('ICS 204 Help');
?>
<div class="card">
<h2>ICS 204 Help / Instructions</h2>
<p>ICS 204 provides tactical assignments for each Branch, Division, Group, Strike Team, Task Force, or resource grouping. Complete operational period, assignment area, operations personnel, resources, work assignments, special instructions, communications, safety/medical references, prepared by, and approval/review details.</p>
<p class="small">Source: ICS Canada Forms Catalogue.</p>
<p><a href="index.php">Back to ICS 204</a></p>
</div>
<?php render_footer(); ?>
