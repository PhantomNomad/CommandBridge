<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/vendor/fpdf/fpdf.php';

/**
 * The official ICS Canada 218 template has no fillable fields, so the text is drawn on a transparent overlay at the
 * template's own coordinates and stamped onto it (OfficialPdfTemplateExporter::stampTemplate()).
 *
 * The form is printed sideways: its columns are bands across the page (top-origin y, in points, measured from the
 * template) and its 12 vehicle rows are strips 28.8 pt wide starting at x = 200.6. Text is rotated 90 degrees so it
 * reads the same way as the form. Re-measure these numbers if the template is replaced.
 * Uses the bundled FPDF (app/vendor/fpdf), so it does not depend on the system TCPDF package.
 */
final class Ics218OfficialPdfOverlay
{
    public const ROWS_PER_PAGE = 12;
    private const ROW_X0 = 200.6;
    private const ROW_W = 28.8;
    /** Column => [y top, y bottom] (bottom is where reading starts). */
    private const COLS = [
        'order' => [717.4, 762.5], 'incident_id' => [669.4, 717.4], 'classification' => [594.0, 669.4], 'make' => [526.9, 594.0],
        'category' => [454.6, 526.9], 'features' => [381.7, 454.6], 'agency' => [333.4, 381.7], 'operator' => [261.4, 333.4],
        'license' => [214.4, 261.4], 'assignment' => [141.4, 214.4], 'start' => [85.4, 141.4], 'release' => [26.3, 85.4],
    ];

    /**
     * Writes the overlay PDF and returns [path, page count].
     *
     * @return array{0: string, 1: int}
     */
    public static function write(array $incident, array $header, array $entries, string $tempDir): array
    {
        $pdf = new Ics218OverlayPdf('P', 'pt', 'Letter');
        $pdf->SetMargins(0, 0, 0);
        $pdf->SetAutoPageBreak(false, 0);

        $entries = array_values($entries);
        $chunks = array_chunk($entries, self::ROWS_PER_PAGE) ?: [[]];
        $prepared = (string)($header['prepared_at'] ?? '');
        $preparedBy = trim(trim((string)($header['prepared_by_name'] ?? '')) . ', ' . trim((string)($header['prepared_by_position'] ?? '')), ', ');
        foreach ($chunks as $chunk) {
            $pdf->AddPage();
            // Header strip: 1. incident name, 2. incident number, 3. date / time prepared.
            self::text($pdf, 92, 130, 598.6, 762.5, ics_pdf_incident_name($incident), 11);
            self::text($pdf, 92, 130, 454.6, 598.6, trim((string)($incident['incident_number'] ?? '')), 11);
            self::text($pdf, 96, 106, 355, 420, ics_pdf_fmt($prepared, 'Y-m-d'), 9);
            self::text($pdf, 96, 106, 262, 333, ics_pdf_fmt($prepared, 'H:i'), 9);
            // 6. Prepared by.
            self::text($pdf, 556, 580, 330, 680, $preparedBy, 10);

            foreach ($chunk as $i => $e) {
                $x0 = self::ROW_X0 + $i * self::ROW_W;
                $x1 = $x0 + self::ROW_W;
                $kindType = trim(trim((string)($e['kind'] ?? '')) . ' / ' . trim((string)($e['type'] ?? '')), ' /');
                $status = str_replace('_', ' ', (string)($e['status'] ?? ''));
                $values = [
                    'order' => (string)($e['order_request_number'] ?? ''),
                    'classification' => (string)($e['equipment_description'] ?? ''),
                    'category' => $kindType,
                    'features' => (string)($e['remarks'] ?? ''),
                    'agency' => (string)($e['owner_agency'] ?? ''),
                    'operator' => trim(trim((string)($e['operator_name'] ?? '')) . "\n" . trim((string)($e['contact_info'] ?? ''))),
                    'assignment' => trim(trim((string)($e['location'] ?? '')) . ($status !== '' ? "\n(" . $status . ')' : '')),
                    'start' => ics_pdf_fmt($e['created_at'] ?? '', 'M j H:i'),
                    'release' => ($e['status'] ?? '') === 'released' ? ics_pdf_fmt($e['updated_at'] ?? '', 'M j H:i') : '',
                ];
                foreach ($values as $col => $value) {
                    [$top, $bottom] = self::COLS[$col];
                    self::text($pdf, $x0, $x1, $top, $bottom, $value, 7);
                }
            }
        }
        $path = (string)tempnam($tempDir, 'cb218_');
        if ($path === '') {
            throw new RuntimeException('Unable to create a temporary PDF file.');
        }
        $pdf->Output('F', $path);
        return [$path, count($chunks)];
    }

    /** Text rotated to read bottom-to-top inside the box x0..x1 (across) by top..bottom (along), shrunk to fit. */
    private static function text(Ics218OverlayPdf $pdf, float $x0, float $x1, float $top, float $bottom, string $text, float $size): void
    {
        $text = trim(str_replace(["\r\n", "\r"], "\n", $text));
        if ($text === '') { return; }
        $pdf->rotatedBox($x0, $x1, $top, $bottom, $text, $size);
    }
}

/** FPDF with rotated, word-wrapped, shrink-to-fit text boxes. */
final class Ics218OverlayPdf extends FPDF
{
    // The bundled FPDF build reads these in _putcatalog() without declaring them.
    public $ZoomMode = 'default';
    public $LayoutMode = 'default';

    public function rotatedBox(float $x0, float $x1, float $top, float $bottom, string $text, float $size): void
    {
        $text = (string)@iconv('UTF-8', 'windows-1252//TRANSLIT', $text);
        $len = $bottom - $top - 5;      // along the reading direction
        $across = $x1 - $x0 - 2;        // room for lines
        for ($fs = $size; $fs >= 4.5; $fs -= 0.5) {
            $this->SetFont('Helvetica', '', $fs);
            $lines = $this->wrapLines($text, $len);
            if (count($lines) * $fs * 1.1 <= $across) { break; }
        }
        $lead = $fs * 1.1;
        $lines = array_slice($lines, 0, max(1, (int)floor($across / $lead)));
        // Rotate 90 degrees counter-clockwise about (x0, bottom); afterwards +x runs up the page, +y to the right.
        $cx = $x0 * $this->k;
        $cy = ($this->h - $bottom) * $this->k;
        $this->_out(sprintf('q 0 1 -1 0 %.2F %.2F cm 1 0 0 1 %.2F %.2F cm', $cx, $cy, -$cx, -$cy));
        $block = count($lines) * $lead;
        $y = $bottom + max(0.0, ($across - $block) / 2) + 1;
        foreach ($lines as $line) {
            $this->Text($x0 + 3, $y + $fs * 0.85, $line);
            $y += $lead;
        }
        $this->_out('Q');
    }

    /** @return array<int, string> */
    private function wrapLines(string $text, float $width): array
    {
        $out = [];
        foreach (explode("\n", $text) as $para) {
            $line = '';
            foreach (preg_split('/\s+/', trim($para)) ?: [] as $word) {
                $try = $line === '' ? $word : $line . ' ' . $word;
                if ($line !== '' && $this->GetStringWidth($try) > $width) { $out[] = $line; $line = $word; } else { $line = $try; }
            }
            $out[] = $line;
        }
        return $out;
    }
}
