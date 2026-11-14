<?php
declare(strict_types=1);

/**
 * Shared helpers for the per-form pdf.php endpoints.
 *
 * Every form's pdf.php serves the same document in two ways:
 *   pdf.php?...            -> forces a download (Content-Disposition: attachment)
 *   pdf.php?...&preview=1  -> shows the PDF in the browser tab (Content-Disposition: inline)
 */
if (!function_exists('ics_pdf_preview_requested')) {
    function ics_pdf_preview_requested(): bool
    {
        return isset($_GET['preview']) && (string)$_GET['preview'] === '1';
    }
}

if (!function_exists('ics_pdf_output_mode')) {
    /** TCPDF Output() destination: 'I' (inline) for preview, 'D' (download) otherwise. */
    function ics_pdf_output_mode(): string
    {
        return ics_pdf_preview_requested() ? 'I' : 'D';
    }
}

if (!function_exists('ics_pdf_safe_filename')) {
    function ics_pdf_safe_filename(string $name): string
    {
        $name = preg_replace('/[^A-Za-z0-9._-]+/', '_', basename($name)) ?: 'command_bridge_form.pdf';
        return str_ends_with(strtolower($name), '.pdf') ? $name : $name . '.pdf';
    }
}

if (!function_exists('ics_pdf_send_official')) {
    /**
     * Fill an official ICS Canada template and send it inline (preview) or as a download.
     * Throws on failure so the caller can show a friendly message.
     *
     * @param array<string, mixed> $fields
     */
    function ics_pdf_send_official(string $formCode, array $fields, string $filename): void
    {
        require_once __DIR__ . '/OfficialPdfTemplateExporter.php';
        $exporter = new OfficialPdfTemplateExporter();
        $path = $exporter->create($formCode, $fields);
        if (ics_pdf_preview_requested()) {
            $exporter->sendInline($path, $filename);
        } else {
            $exporter->sendDownload($path, $filename);
        }
    }
}

if (!function_exists('ics_pdf_fail')) {
    /** Plain-text error for a PDF endpoint (never HTML, so a failed preview tab stays readable). */
    function ics_pdf_fail(int $status, string $message): void
    {
        http_response_code($status);
        header('Content-Type: text/plain; charset=utf-8');
        echo $message;
        exit;
    }
}

if (!function_exists('ics_pdf_context')) {
    /**
     * Common checks for a form's pdf.php: login, active incident, view permission, operational period, form record.
     * Callers must have loaded includes/layout.php. Exits with a plain-text error when something is missing.
     *
     * @return array{incident: array, incident_id: int, op_id: int, op: array, form_record: array}
     */
    function ics_pdf_context(string $formCode, bool $requireRecord = true): array
    {
        require_login();

        $incident = active_incident();
        $incidentId = (int)($incident['id'] ?? 0);
        if ($incidentId <= 0) {
            ics_pdf_fail(400, 'No active incident.');
        }
        if (!current_user_can_view_form($formCode, $incidentId)) {
            ics_pdf_fail(403, 'Forbidden');
        }

        $opId = (int)($_GET['op_id'] ?? ($_GET['operational_period_id'] ?? 0));
        $op = [];
        if ($opId > 0) {
            $op = get_operational_period_by_id($incidentId, $opId) ?: [];
        }
        if (!$op) {
            $op = current_operational_period($incidentId) ?: [];
            $opId = (int)($op['id'] ?? 0);
        }

        $formRecord = get_form_record($incidentId, $formCode, $opId > 0 ? $opId : null);
        if (!$formRecord && $requireRecord) {
            ics_pdf_fail(404, $formCode . ' has not been saved yet. Save the form, then try the PDF again.');
        }

        return [
            'incident' => $incident,
            'incident_id' => $incidentId,
            'op_id' => $opId,
            'op' => $op,
            'form_record' => $formRecord ?: [],
        ];
    }
}

if (!function_exists('ics_pdf_fmt')) {
    /** Format a database date/time for a PDF field; returns '' for empty or zero dates. */
    function ics_pdf_fmt($value, string $format = 'Y-m-d H:i'): string
    {
        $value = trim((string)$value);
        if ($value === '' || str_starts_with($value, '0000-00-00')) {
            return '';
        }
        $ts = strtotime($value);
        return $ts ? date($format, $ts) : $value;
    }
}

if (!function_exists('ics_pdf_send_official_paged')) {
    /** Like ics_pdf_send_official() but joins several filled pages into one PDF. */
    function ics_pdf_send_official_paged(string $formCode, array $pages, string $filename): void
    {
        require_once __DIR__ . '/OfficialPdfTemplateExporter.php';
        $exporter = new OfficialPdfTemplateExporter();
        $path = $exporter->createPaged($formCode, $pages);
        if (ics_pdf_preview_requested()) {
            $exporter->sendInline($path, $filename);
        } else {
            $exporter->sendDownload($path, $filename);
        }
    }
}

if (!function_exists('ics_pdf_incident_name')) {
    function ics_pdf_incident_name(array $incident): string
    {
        return trim((string)($incident['incident_name'] ?? $incident['name'] ?? ''));
    }
}

if (!function_exists('ics_pdf_send_official_with_continuation')) {
    /**
     * Fill an official template once per page of data, append a continuation sheet for text that did not fit
     * (OfficialPdfText::fit()), and send it.
     *
     * @param array<int, array<string, mixed>> $pages one field map per output page
     * @param array<int, array{heading: string, text: string}> $continuations
     * @param array<int, string> $headerLines printed at the top of each continuation page (incident, period)
     * @param array<int, string>|null $keepPages per output page, which template pages to keep ("1", "2"); null = all
     * @param array<int, string> $appendFiles temporary PDFs to add at the end (deleted afterwards)
     */
    function ics_pdf_send_official_with_continuation(string $formCode, array $pages, array $continuations, array $headerLines,
        string $title, string $filename, ?array $keepPages = null, array $appendFiles = []): void
    {
        require_once __DIR__ . '/OfficialPdfTemplateExporter.php';
        require_once __DIR__ . '/SimpleTextPdf.php';
        $exporter = new OfficialPdfTemplateExporter();
        $path = $exporter->createPaged($formCode, $pages ?: [[]], $keepPages);
        if ($continuations) {
            $extra = tempnam(sys_get_temp_dir(), 'cbcont_');
            if ($extra === false || file_put_contents($extra, SimpleTextPdf::render($title . ' - Continuation Sheet', $headerLines, $continuations)) === false) {
                @unlink($path);
                throw new RuntimeException('Unable to prepare the continuation sheet.');
            }
            $path = $exporter->appendPdf($path, $extra);
        }
        foreach ($appendFiles as $file) {
            // Temporary PDFs made by the caller (e.g. ICS 201 maps); appendPdf() deletes them.
            if (is_string($file) && is_file($file) && filesize($file) > 0) {
                $path = $exporter->appendPdf($path, $file);
            }
        }
        if (ics_pdf_preview_requested()) {
            $exporter->sendInline($path, $filename);
        } else {
            $exporter->sendDownload($path, $filename);
        }
    }
}

if (!function_exists('ics_pdf_header_lines')) {
    /** Incident and operational period lines for the top of a continuation sheet. */
    function ics_pdf_header_lines(array $incident, array $op): array
    {
        $lines = ['Incident: ' . ics_pdf_incident_name($incident)];
        $start = ics_pdf_fmt($op['op_start'] ?? $op['start_time'] ?? '');
        if ($start !== '') {
            $lines[] = 'Operational period: ' . trim((string)($op['period_name'] ?? '')) . ' ' . $start . ' to ' . ics_pdf_fmt($op['op_end'] ?? $op['end_time'] ?? '');
        }
        return $lines;
    }
}
