<?php
declare(strict_types=1);
require_once dirname(dirname(__DIR__)) . '/includes/layout.php';
require_login();
render_header('ICS 215A Help');
?>
<div class="card">
<h2>ICS 215A - Incident Action Safety Plan Analysis Help</h2>
<p>The ICS 215A helps the Safety Officer complete an operational risk assessment, prioritize hazards and health/safety issues, and identify controls for the operational period.</p>
<h3>Preparation</h3>
<p>The Safety Officer normally prepares the form during the incident action planning cycle, in collaboration with the Operations Section Chief. It is closely linked to the Operational Planning Worksheet and should support Operations Section briefings.</p>
<h3>Distribution</h3>
<p>When complete, distribute the safety analysis to the Resources Unit to help prepare the Operations Section briefing. Completed originals should be provided to the Documentation Unit.</p>
<h3>Field guidance</h3>
<table>
<thead><tr><th>Block</th><th>Title</th><th>Guidance</th></tr></thead>
<tbody>
<tr><td>1</td><td>Incident Name</td><td>Enter the assigned incident name.</td></tr>
<tr><td>2</td><td>Date/Time Prepared</td><td>Enter the date and 24-hour time the form was prepared.</td></tr>
<tr><td>3</td><td>Operational Period</td><td>Enter the start and end date/time for the operational period.</td></tr>
<tr><td>4</td><td>Incident Area</td><td>Enter the areas where personnel or resources are likely to encounter risks, such as a Division or Group.</td></tr>
<tr><td>5</td><td>Hazards / Risks</td><td>List hazards and risks likely to be encountered in the incident area.</td></tr>
<tr><td>6</td><td>Mitigations</td><td>List controls to reduce risk, such as PPE, buddy system, escape routes, or other protective actions.</td></tr>
<tr><td>7</td><td>Prepared / Reviewed By</td><td>Enter both the Safety Officer and Operations Section Chief names, signatures, and date/time reviewed.</td></tr>
</tbody>
</table>
<p><a href="index.php">Back to ICS 215A</a></p>
</div>
<?php render_footer(); ?>
