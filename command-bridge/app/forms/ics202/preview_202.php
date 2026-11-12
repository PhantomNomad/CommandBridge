<?php
declare(strict_types=1);

require_once __DIR__ . '/ics202_document_helpers.php';

if (function_exists('require_login')) {
    require_login();
}

$incidentId = cb202_incident_id();
$record = cb202_load_record(cb202_pdo(), $incidentId);
if ($record === null) {
    http_response_code(404);
    exit('No saved ICS 202 draft was found for this incident.');
}

$items = cb202_flatten(cb202_payload($record));
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>ICS 202 Print Preview</title>
<style>
body { font-family: Arial, sans-serif; color: #111; margin: 0; background: #f3f4f6; }
.toolbar { padding: 12px 20px; background: #1f2937; color: #fff; }
.toolbar button { padding: 8px 14px; cursor: pointer; }
.page { background: #fff; max-width: 8.5in; min-height: 11in; margin: 20px auto; padding: .55in; box-sizing: border-box; }
h1 { margin: 0 0 6px; font-size: 20px; } h2 { font-size: 14px; margin-top: 22px; border-bottom: 1px solid #555; padding-bottom: 4px; }
.meta { color: #444; font-size: 12px; } table { width: 100%; border-collapse: collapse; font-size: 12px; }
th, td { border: 1px solid #777; padding: 7px; vertical-align: top; text-align: left; } th { width: 31%; background: #e5e7eb; }
.note { font-size: 11px; color: #444; margin-top: 18px; }
@media print { .toolbar { display: none; } body { background: #fff; } .page { margin: 0; max-width: none; min-height: 0; padding: 0; } }
</style>
</head>
<body>
<div class="toolbar"><button type="button" onclick="window.print()">Print</button></div>
<main class="page">
<h1>ICS 202 — Incident Objectives</h1>
<div class="meta">Incident ID: <?= (int) $incidentId ?></div>
<h2>Saved Draft Details</h2>
<table>
<tbody>
<?php foreach ($items as [$label, $value]): ?>
<tr><th><?= cb202_escape($label) ?></th><td><?= nl2br(cb202_escape($value)) ?></td></tr>
<?php endforeach; ?>
</tbody>
</table>
<p class="note">Attachments remain available from the ICS 202 attachments section in Command Bridge.</p>
</main>
</body>
</html>
