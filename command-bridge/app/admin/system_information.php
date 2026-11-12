<?php
declare(strict_types=1);

require_once __DIR__ . '/settings_helpers.php';
require_once dirname(__DIR__) . '/includes/pdf/OfficialPdfTemplateExporter.php';

$tables = db()->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()")->fetchColumn();

$pdfExporter = new OfficialPdfTemplateExporter();
$pdfStatus = $pdfExporter->status();
$pdfStatusLabel = $pdfStatus['ready'] ? 'Ready' : 'Deployment configuration required';
$pdfMissingTemplates = $pdfStatus['missing_templates'] === []
    ? 'All templates available'
    : 'Missing: ' . implode(', ', $pdfStatus['missing_templates']);

$requiredModules = array_filter([
    'PDO MySQL' => extension_loaded('pdo_mysql'),
    'OpenSSL' => extension_loaded('openssl'),
    'Fileinfo' => extension_loaded('fileinfo'),
]);
?>
<?php require_once dirname(__DIR__) . '/includes/version.php'; render_header('System Information'); settings_menu('system'); ?>
<div class="card">
  <h2>System Information</h2>
  <table><tbody>
    <tr><th>Application version</th><td><?= e(CB_VERSION) ?></td></tr>
    <tr><th>PHP version</th><td><?= e(PHP_VERSION) ?></td></tr>
    <tr><th>Database server</th><td><?= e((string)db()->getAttribute(PDO::ATTR_SERVER_VERSION)) ?></td></tr>
    <tr><th>Database tables</th><td><?= e((string)$tables) ?></td></tr>
    <tr><th>Required PHP modules</th><td><?= e(implode(', ', array_keys($requiredModules))) ?></td></tr>
    <tr><th>Standard PDF export</th><td><?= e($pdfStatusLabel) ?></td></tr>
    <tr><th>PDF export engine</th><td><?= $pdfStatus['engine_available'] ? 'Available' : 'Unavailable' ?></td></tr>
    <tr><th>PDF temporary storage</th><td><?= $pdfStatus['temporary_directory_writable'] ? 'Writable' : 'Not writable' ?></td></tr>
    <tr><th>Official PDF templates</th><td><?= e((string)$pdfStatus['registered_templates']) ?> registered; <?= e((string)$pdfStatus['fillable_templates']) ?> fillable. <?= e($pdfMissingTemplates) ?></td></tr>
  </tbody></table>
  <p class="small">PDF export server requirements are documented in <code>includes/pdf/PDF_EXPORT_METHOD.md</code>. Backups and migration status should be verified using the approved server administration procedures.</p>
</div>
<?php render_footer(); ?>
