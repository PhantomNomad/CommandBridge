<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/security.php';
require_once __DIR__ . '/../../includes/form_action_buttons.php'; ?>
require_once dirname(dirname(__DIR__)) . '/includes/layout.php';

if (function_exists('require_login')) {
    require_login();
}

$pdo = $pdo ?? null;
if (!$pdo instanceof PDO) {
    foreach (['db', 'get_db', 'get_pdo'] as $databaseFunction) {
        if (function_exists($databaseFunction)) {
            $candidate = $databaseFunction();
            if ($candidate instanceof PDO) {
                $pdo = $candidate;
                break;
            }
        }
    }
}
if (!$pdo instanceof PDO) {
    http_response_code(500);
    exit('Database connection is unavailable.');
}

$incidentId = (int) ($_GET['incident_id'] ?? 0);
if ($incidentId <= 0) {
    http_response_code(400);
    exit('A valid incident is required.');
}

$formRecord = null;
try {
    $statement = $pdo->prepare(
        "SELECT * FROM form_records WHERE incident_id = ? AND form_type = 'ICS202' ORDER BY id DESC LIMIT 1"
    );
    $statement->execute([$incidentId]);
    $formRecord = $statement->fetch(PDO::FETCH_ASSOC) ?: null;
} catch (Throwable $exception) {
    $formRecord = null;
}

function ics202_pdf_value_lines($value, string $prefix = ''): array
{
    if (is_array($value)) {
        $lines = [];
        foreach ($value as $key => $item) {
            $label = trim($prefix . ' ' . ucwords(str_replace(['_', '-'], ' ', (string) $key)));
            $lines = array_merge($lines, ics202_pdf_value_lines($item, $label . ':'));
        }
        return $lines;
    }

    $text = trim((string) $value);
    return $text === '' ? [] : [trim($prefix . ' ' . $text)];
}

function ics202_pdf_wrap(string $text, int $width = 90): array
{
    $text = preg_replace('/\s+/', ' ', trim($text)) ?? '';
    if ($text === '') {
        return [''];
    }

    $words = explode(' ', $text);
    $lines = [];
    $line = '';
    foreach ($words as $word) {
        $candidate = $line === '' ? $word : $line . ' ' . $word;
        if (strlen($candidate) > $width && $line !== '') {
            $lines[] = $line;
            $line = $word;
        } else {
            $line = $candidate;
        }
    }
    if ($line !== '') {
        $lines[] = $line;
    }
    return $lines;
}

function ics202_pdf_escape(string $text): string
{
    $converted = @iconv('UTF-8', 'Windows-1252//TRANSLIT//IGNORE', $text);
    if ($converted !== false) {
        $text = $converted;
    }
    return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $text);
}

function ics202_emit_pdf(array $lines, string $filename, bool $inline): void
{
    $wrapped = [];
    foreach ($lines as $line) {
        foreach (ics202_pdf_wrap((string) $line) as $wrappedLine) {
            $wrapped[] = $wrappedLine;
        }
    }
    if (!$wrapped) {
        $wrapped = ['ICS 202', 'No saved form content is available.'];
    }

    $pages = array_chunk($wrapped, 46);
    $objects = [];
    $objects[1] = '<< /Type /Catalog /Pages 2 0 R >>';
    $objects[3] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>';
    $pageReferences = [];
    $nextObject = 4;

    foreach ($pages as $pageLines) {
        $pageObject = $nextObject++;
        $contentObject = $nextObject++;
        $pageReferences[] = $pageObject . ' 0 R';

        $stream = "BT\n/F1 10 Tf\n14 TL\n54 744 Td\n";
        foreach ($pageLines as $pageLine) {
            $stream .= '(' . ics202_pdf_escape($pageLine) . ") Tj\nT*\n";
        }
        $stream .= "ET";
        $objects[$pageObject] =
            '<< /Type /Page /Parent 2 0 R /Resources << /Font << /F1 3 0 R >> >> ' .
            '/MediaBox [0 0 612 792] /Contents ' . $contentObject . ' 0 R >>';
        $objects[$contentObject] = '<< /Length ' . strlen($stream) . " >>\nstream\n" . $stream . "\nendstream";
    }

    $objects[2] = '<< /Type /Pages /Kids [' . implode(' ', $pageReferences) . '] /Count ' . count($pages) . ' >>';
    ksort($objects);

    $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
    $offsets = [0];
    foreach ($objects as $number => $object) {
        $offsets[$number] = strlen($pdf);
        $pdf .= $number . " 0 obj\n" . $object . "\nendobj\n";
    }
    $xref = strlen($pdf);
    $maximumObject = max(array_keys($objects));
    $pdf .= 'xref' . "\n0 " . ($maximumObject + 1) . "\n0000000000 65535 f \n";
    for ($number = 1; $number <= $maximumObject; $number++) {
        $pdf .= sprintf('%010d 00000 n ', $offsets[$number] ?? 0) . "\n";
    }
    $pdf .= 'trailer' . "\n<< /Size " . ($maximumObject + 1) . " /Root 1 0 R >>\nstartxref\n" . $xref . "\n%%EOF";

    header('Content-Type: application/pdf');
    header('Content-Length: ' . strlen($pdf));
    header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . $filename . '"');
    header('X-Content-Type-Options: nosniff');
    echo $pdf;
    exit;
}

$lines = ['ICS 202 — Incident Objectives', 'Incident ID: ' . $incidentId, ''];

if ($formRecord) {
    $payload = [];
    foreach (['data_json', 'form_data', 'payload', 'data'] as $column) {
        if (!empty($formRecord[$column]) && is_string($formRecord[$column])) {
            $decoded = json_decode($formRecord[$column], true);
            if (is_array($decoded)) {
                $payload = $decoded;
                break;
            }
        }
    }

    if ($payload) {
        foreach ($payload as $key => $value) {
            $label = ucwords(str_replace(['_', '-'], ' ', (string) $key)) . ':';
            $lines = array_merge($lines, ics202_pdf_value_lines($value, $label));
        }
    } else {
        $lines[] = 'Saved ICS 202 record found.';
    }
} else {
    $lines[] = 'No saved ICS 202 draft was found for this incident.';
}

$lines[] = '';
$lines[] = 'Attachments are retained in Command Bridge and are not rasterized into this PDF.';
$lines[] = 'This avoids server-side PDF conversion errors when pdftocairo or Ghostscript is unavailable.';

ics202_emit_pdf(
    $lines,
    'ICS202_Incident_' . $incidentId . '.pdf',
    isset($_GET['inline']) && (string) $_GET['inline'] === '1'
);
