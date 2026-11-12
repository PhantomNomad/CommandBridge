<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/security.php';

require_once dirname(dirname(__DIR__)) . '/includes/layout.php';

function cb202_pdo(): PDO
{
    global $pdo;

    if (isset($pdo) && $pdo instanceof PDO) {
        return $pdo;
    }

    foreach (['db', 'get_db', 'database'] as $factory) {
        if (function_exists($factory)) {
            $candidate = $factory();
            if ($candidate instanceof PDO) {
                return $candidate;
            }
        }
    }

    throw new RuntimeException('A database connection is not available.');
}

function cb202_incident_id(): int
{
    if (isset($_GET['incident_id']) && ctype_digit((string) $_GET['incident_id'])) {
        return (int) $_GET['incident_id'];
    }

    if (function_exists('get_current_incident_id')) {
        return (int) get_current_incident_id();
    }

    return (int) ($_SESSION['current_incident_id'] ?? 0);
}

function cb202_load_record(PDO $pdo, int $incidentId): ?array
{
    if ($incidentId <= 0) {
        return null;
    }

    $stmt = $pdo->prepare(
        "SELECT * FROM form_records
         WHERE incident_id = ? AND form_type = 'ICS202'
         ORDER BY id DESC
         LIMIT 1"
    );
    $stmt->execute([$incidentId]);
    $record = $stmt->fetch(PDO::FETCH_ASSOC);

    return is_array($record) ? $record : null;
}

function cb202_payload(array $record): array
{
    foreach (['data_json', 'payload_json', 'form_data', 'data', 'payload'] as $field) {
        if (!empty($record[$field]) && is_string($record[$field])) {
            $decoded = json_decode($record[$field], true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }
    }

    return [];
}

function cb202_flatten(array $data, string $prefix = ''): array
{
    $lines = [];

    foreach ($data as $key => $value) {
        $label = trim($prefix . ' ' . preg_replace('/[_-]+/', ' ', (string) $key));

        if (is_array($value)) {
            $lines = array_merge($lines, cb202_flatten($value, $label));
            continue;
        }

        if (is_bool($value)) {
            $value = $value ? 'Yes' : 'No';
        }

        $value = trim((string) $value);
        if ($value !== '') {
            $lines[] = [ucwords($label), $value];
        }
    }

    return $lines;
}

function cb202_escape(string $text): string
{
    return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function cb202_pdf_escape(string $text): string
{
    $text = iconv('UTF-8', 'Windows-1252//TRANSLIT//IGNORE', $text) ?: '';
    $text = preg_replace('/[^\x20-\x7E\x80-\xFF]/', ' ', $text) ?? '';
    return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $text);
}

function cb202_make_pdf(array $lines): string
{
    $wrapped = [];
    foreach ($lines as $line) {
        $parts = wordwrap((string) $line, 96, "\n", true);
        foreach (explode("\n", $parts) as $part) {
            $wrapped[] = $part;
        }
    }

    $pages = array_chunk($wrapped ?: ['ICS 202'], 45);
    $objects = [];
    $objects[1] = '<< /Type /Catalog /Pages 2 0 R >>';
    $objects[3] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>';

    $kids = [];
    foreach ($pages as $pageIndex => $pageLines) {
        $pageObject = 4 + ($pageIndex * 2);
        $contentObject = $pageObject + 1;
        $kids[] = $pageObject . ' 0 R';

        $stream = "BT\n/F1 10 Tf\n14 TL\n54 738 Td\n";
        foreach ($pageLines as $line) {
            $stream .= '(' . cb202_pdf_escape($line) . ") Tj\nT*\n";
        }
        $stream .= "ET\n";

        $objects[$pageObject] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << /Font << /F1 3 0 R >> >> /Contents ' . $contentObject . ' 0 R >>';
        $objects[$contentObject] = "<< /Length " . strlen($stream) . " >>\nstream\n" . $stream . "endstream";
    }

    $objects[2] = '<< /Type /Pages /Kids [' . implode(' ', $kids) . '] /Count ' . count($pages) . ' >>';
    ksort($objects);

    $pdf = "%PDF-1.4\n";
    $offsets = [0];
    foreach ($objects as $number => $body) {
        $offsets[$number] = strlen($pdf);
        $pdf .= $number . " 0 obj\n" . $body . "\nendobj\n";
    }

    $xref = strlen($pdf);
    $maxObject = max(array_keys($objects));
    $pdf .= "xref\n0 " . ($maxObject + 1) . "\n0000000000 65535 f \n";
    for ($i = 1; $i <= $maxObject; $i++) {
        $pdf .= sprintf("%010d 00000 n \n", $offsets[$i] ?? 0);
    }
    $pdf .= "trailer\n<< /Size " . ($maxObject + 1) . " /Root 1 0 R >>\nstartxref\n" . $xref . "\n%%EOF";

    return $pdf;
}
