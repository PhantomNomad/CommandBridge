<?php
declare(strict_types=1);
require_once dirname(dirname(__DIR__)) . '/includes/layout.php';
require_once dirname(dirname(__DIR__)) . '/includes/pdf/Ics201PdfRenderer.php';

require_login();

$incident = active_incident();
$incidentId = (int)($incident['id'] ?? 0);
if (!current_user_can_view_form('ICS201', $incidentId > 0 ? $incidentId : null)) {
    http_response_code(403);
    render_header('ICS 201 Blank PDF Preview');
    echo '<div class="card"><p>Forbidden</p></div>';
    render_footer();
    exit;
}

try {
    $layout = require dirname(dirname(__DIR__)) . '/includes/pdf/ics201_layout.php';
    $renderer = new Ics201PdfRenderer($layout);
    $renderer->renderBlankPreview();
    exit;
} catch (Throwable $e) {
    http_response_code(500);
    render_header('ICS 201 Blank PDF Preview');
    echo '<div class="card"><p>' . e($e->getMessage()) . '</p></div>';
    render_footer();
    exit;
}
