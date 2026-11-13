<?php
declare(strict_types=1);
require_once dirname(dirname(__DIR__)) . '/includes/layout.php';
require_login();
render_header('ICS 214 Help');
?>
<div class="card">
<h2>ICS 214 Help / Instructions</h2>
<p>The ICS 214 Activity Log records notable activities for an assigned person or unit during an incident and operational period.</p>
<h3>Preparation</h3>
<p>Each assigned individual or unit should maintain an ICS 214. Record significant actions, contacts, decisions, assignments, resource status changes, briefings, meetings, and other events with the date/time they occurred.</p>
<h3>Distribution</h3>
<p>Completed logs are submitted to the Documentation Unit or other assigned incident records function. They support the incident file, after-action review, cost recovery, and continuity between operational periods.</p>
<h3>Block Guidance</h3>
<ul>
<li><strong>Incident Name:</strong> Auto-filled from the active incident.</li>
<li><strong>Operational Period:</strong> Auto-filled from the active/current operational period when available.</li>
<li><strong>Name / ICS Position / Home Agency / Unit:</strong> Auto-filled where possible; confirm and correct as needed.</li>
<li><strong>Date/Time Prepared:</strong> Leave blank until the user submits or finalizes the log.</li>
<li><strong>Activity Log:</strong> Enter a date/time and a concise description of each notable event. The system also displays eligible login/logout and past meeting entries.</li>
</ul>
<p class="small">Source: ICS Canada Forms Catalogue.</p>
<p><a href="index.php">Back to ICS 214</a></p>
</div>
<?php render_footer(); ?>
