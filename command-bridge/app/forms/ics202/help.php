<?php
declare(strict_types=1);
require_once dirname(dirname(__DIR__)) . '/includes/layout.php';
require_login();
render_header('ICS 202 Help');
?>
<div class="card">
<h2>ICS 202 Help / Instructions</h2>
<p>ICS 202 documents incident objectives for the operational period. It is prepared by Planning after the Command and General Staff meeting and approved by the Incident Commander. Complete incident name, operational period, objectives, command emphasis, situational awareness, site safety plan requirement/location, included IAP forms, prepared by, and IC approval.</p>
<p>The help content is based on the ICS Canada Forms Catalogue instructions and should be kept adjacent to the form so staff can reference the purpose, preparation, distribution, and block-by-block guidance while completing the page.</p>
<p><a href="index.php">Back to ICS 202</a></p>
</div>
<?php render_footer(); ?>
