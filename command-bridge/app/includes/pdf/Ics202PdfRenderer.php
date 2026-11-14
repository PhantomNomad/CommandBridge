<?php
declare(strict_types=1);
require_once __DIR__ . '/TcpdfLoader.php';

class Ics202PdfRenderer
{
    private array $layout;
    public function __construct(array $layout) { $this->layout = $layout; }

    public function renderFilled(array $context): void
    {
        if (!command_bridge_require_tcpdf()) {
            throw new RuntimeException('TCPDF is not installed on this server.');
        }
        require_once __DIR__ . '/BaseTcpdfRenderer.php';

        $layout = $this->layout;
        $incident = (array)($context['incident'] ?? []);
        $record = (array)($context['record'] ?? []);
        $op = (array)($context['operational_period'] ?? []);
        $attachments = (array)($context['attachments'] ?? []);
        $inline = !empty($context['inline']);

        $pdf = new class($layout, 'ICS 202 Incident Objectives') extends BaseTcpdfRenderer {
            public function drawCheckMarkBox(float $x, float $y, bool $checked, string $label): void
            {
                $this->Rect($x, $y, 4, 4);
                if ($checked) {
                    $this->SetFont('helvetica', 'B', 10);
                    $this->SetXY($x + 0.3, $y - 0.8);
                    $this->Cell(4, 4, 'X', 0, 0, 'C');
                }
                $this->SetFont('helvetica', '', 8.5);
                $this->SetXY($x + 5.5, $y - 0.2);
                $this->Cell(45, 4.5, $label, 0, 0, 'L');
            }

            public function valueLine(float $x, float $y, float $w, string $label, string $value): void
            {
                $this->SetFont('helvetica', '', 7.2);
                $this->SetXY($x, $y);
                $this->Cell($w, 3.2, $label, 0, 0, 'L');
                $this->Line($x, $y + 8.2, $x + $w, $y + 8.2);
                $this->SetFont('helvetica', '', 8.8);
                $this->SetXY($x, $y + 3.5);
                $this->Cell($w, 4.5, $value, 0, 0, 'L');
            }

            public function drawMain(array $incident, array $record, array $op, array $attachments): void
            {
                $this->AddPage('P', 'LETTER');
                $m = $this->margin();
                $fullW = $this->pageWidth() - (2 * $m);
                $this->drawHeaderBlock('');

                $y = $m + 18.8;
                $incidentName = (string)($incident['incident_name'] ?? $incident['name'] ?? '');
                $this->drawLabeledBox($m, $y, $fullW * 0.54, 13, '1. Incident Name', $incidentName);
                $opText = trim((string)($op['name'] ?? ''));
                $start = trim((string)($op['start_time'] ?? ''));
                $end = trim((string)($op['end_time'] ?? ''));
                $opDates = trim(($opText !== '' ? $opText . ' | ' : '') . $start . ($end !== '' ? ' to ' . $end : ''));
                $this->drawLabeledBox($m + ($fullW * 0.54), $y, $fullW * 0.46, 13, '2. Operational Period', $opDates, 7.8);

                $y += 15;
                $this->drawWrappedTextBox($m, $y, $fullW, 43, '3. Objective(s)', (string)($record['objectives'] ?? ''), 8.4);
                $y += 45;
                $this->drawWrappedTextBox($m, $y, $fullW, 30, '4. Operational Period Command Emphasis', (string)($record['operational_period_command_emphasis'] ?? ''), 8.4);
                $y += 32;
                $this->drawWrappedTextBox($m, $y, $fullW, 32, '5. General Situational Awareness', (string)($record['general_situational_awareness'] ?? ''), 8.4);
                $y += 34;

                $this->SetLineWidth(0.25);
                $this->Rect($m, $y, $fullW, 18);
                $this->SetFont('helvetica', '', 7.2);
                $this->SetXY($m + 1, $y + 1);
                $this->Cell($fullW - 2, 3.2, 'Site Safety Plan', 0, 0, 'L');
                $this->drawCheckMarkBox($m + 2, $y + 7, !empty($record['site_safety_plan_required']), 'Required');
                $this->SetFont('helvetica', '', 8.3);
                $this->SetXY($m + 42, $y + 6.5);
                $this->MultiCell($fullW - 44, 4, 'Approved Site Safety Plan(s) Located At: ' . (string)($record['site_safety_plan_location'] ?? ''), 0, 'L', false);
                $y += 20;

                $this->SetLineWidth(0.25);
                $this->Rect($m, $y, $fullW, 25);
                $this->SetFont('helvetica', '', 7.2);
                $this->SetXY($m + 1, $y + 1);
                $this->Cell($fullW - 2, 3.2, '6. Incident Action Plan - Forms Included', 0, 0, 'L');
                $labels = [
                    ['iap_include_ics203','ICS 203'], ['iap_include_ics204','ICS 204'], ['iap_include_ics205','ICS 205'],
                    ['iap_include_ics205a','ICS 205A'], ['iap_include_ics206','ICS 206'], ['iap_include_ics208','ICS 208'],
                ];
                $cx = $m + 4; $cy = $y + 8;
                foreach ($labels as $i => $item) {
                    $this->drawCheckMarkBox($cx + (($i % 3) * 62), $cy + (floor($i / 3) * 8), !empty($record[$item[0]]), $item[1]);
                }
                $y += 27;

                $attachText = '';
                if ($attachments) {
                    $names = array_map(static fn($a) => (string)($a['original_name'] ?? ''), $attachments);
                    $attachText = implode(', ', array_filter($names));
                }
                $this->drawWrappedTextBox($m, $y, $fullW, 17, 'Additional Uploaded Attachments', $attachText, 7.8);
                $y += 19;

                $prep = trim((string)($record['prepared_by_name'] ?? '') . ' - ' . (string)($record['prepared_by_position_title'] ?? ''), " -\t\n\r\0\x0B");
                $approved = trim((string)($record['approved_by_ic_name'] ?? '') . (!empty($record['approved_at']) ? ' at ' . (string)$record['approved_at'] : ''), " \t\n\r\0\x0B");
                $this->drawLabeledBox($m, $y, $fullW * 0.55, 14, '7. Prepared By', $prep, 8.2);
                $this->drawLabeledBox($m + ($fullW * 0.55), $y, $fullW * 0.45, 14, '8. Approved By Incident Commander', $approved, 8.0);

                $this->SetFont('helvetica', '', 7.2);
                $this->SetXY($m, $this->pageHeight() - $m - 3.5);
                $this->Cell($fullW, 3, 'ICS 202', 0, 0, 'R');
            }
        };
        $pdf->drawMain($incident, $record, $op, $attachments);

        foreach ($attachments as $attachment) {
            $this->appendAttachment($pdf, (array)$attachment);
        }

        $filename = 'ICS202_' . preg_replace('/[^A-Za-z0-9_-]+/', '_', (string)($incident['incident_number'] ?? 'incident')) . '_' . date('Ymd_His') . '.pdf';
        $pdf->Output($filename, $inline ? 'I' : 'D');
    }


    /**
     * Writes only the uploaded attachments as pages of their own, for appending after the official ICS 202.
     * Returns false when there was nothing to write.
     */
    public function writeAttachmentsPdf(array $attachments, string $outPath): bool
    {
        if (!$attachments || !command_bridge_require_tcpdf()) {
            return false;
        }
        require_once __DIR__ . '/BaseTcpdfRenderer.php';
        $pdf = new class($this->layout, 'ICS 202 attachments') extends BaseTcpdfRenderer {};
        foreach ($attachments as $attachment) {
            $this->appendAttachment($pdf, (array)$attachment);
        }
        if ($pdf->getNumPages() === 0) {
            return false;
        }
        $pdf->Output($outPath, 'F');
        return is_file($outPath) && filesize($outPath) > 0;
    }

    private function ics202AttachmentAbsolutePath(array $attachment): string
    {
        $incidentId = (int)($attachment['incident_id'] ?? 0);
        $storedName = (string)($attachment['stored_name'] ?? '');
        if ($incidentId <= 0 || $storedName === '') {
            return '';
        }
        return storage_root_path() . '/uploads/incidents/' . $incidentId . '/ics202/' . $storedName;
    }

    private function appendAttachment(BaseTcpdfRenderer $pdf, array $attachment): void
    {
        $path = $this->ics202AttachmentAbsolutePath($attachment);
        if (!is_file($path)) {
            return;
        }

        $ext = strtolower((string)($attachment['file_ext'] ?? pathinfo($path, PATHINFO_EXTENSION)));
        $filename = (string)($attachment['original_name'] ?? basename($path));

        if (in_array($ext, ['bmp', 'jpg', 'jpeg', 'png'], true)) {
            [$w, $h] = $this->imageSizeMm($path);
            $pdf->drawAttachmentPageFromImage($path, $w, $h);
            return;
        }

        if ($ext === 'pdf') {
            $this->appendPdfAttachment($pdf, $path);
            return;
        }

        if ($ext === 'txt') {
            $text = extract_text_file_preview($path, 50000);
            $pdf->outputTextAttachment($filename, $text);
        }
    }

    private function imageSizeMm(string $path): array
    {
        $info = @getimagesize($path);
        $pxW = (int)($info[0] ?? 0);
        $pxH = (int)($info[1] ?? 0);

        if ($pxW <= 0 || $pxH <= 0) {
            return [100.0, 100.0];
        }

        $dpiX = 96.0;
        $dpiY = 96.0;
        if (is_array($info) && isset($info['resolution_x']) && (float)$info['resolution_x'] > 0) {
            $dpiX = (float)$info['resolution_x'];
        }
        if (is_array($info) && isset($info['resolution_y']) && (float)$info['resolution_y'] > 0) {
            $dpiY = (float)$info['resolution_y'];
        }

        return [($pxW / $dpiX) * 25.4, ($pxH / $dpiY) * 25.4];
    }

    private function appendPdfAttachment(BaseTcpdfRenderer $pdf, string $path): void
    {
        $sizes = $this->pdfPageSizesMm($path);
        $count = max(1, count($sizes));
        $cache = $this->attachmentCacheDir($path);
        $rendered = false;

        if ($this->commandExists('pdftocairo')) {
            $prefix = $cache . '/page';
            if (!glob($prefix . '-*.png')) {
                @exec('pdftocairo -png -r 144 ' . escapeshellarg($path) . ' ' . escapeshellarg($prefix) . ' 2>/dev/null');
            }
            for ($p = 1; $p <= $count; $p++) {
                $img = sprintf('%s-%d.png', $prefix, $p);
                if (!is_file($img)) {
                    continue;
                }
                $w = (float)($sizes[$p - 1]['width_mm'] ?? 215.9);
                $h = (float)($sizes[$p - 1]['height_mm'] ?? 279.4);
                $pdf->drawAttachmentPageFromImage($img, $w, $h);
                $rendered = true;
            }
        } elseif ($this->commandExists('gs')) {
            for ($p = 1; $p <= $count; $p++) {
                $img = $cache . '/page-' . $p . '.png';
                if (!is_file($img)) {
                    @exec(
                        'gs -dSAFER -dBATCH -dNOPAUSE -sDEVICE=png16m -r144 -dFirstPage=' . $p . ' -dLastPage=' . $p .
                        ' -sOutputFile=' . escapeshellarg($img) . ' ' . escapeshellarg($path) . ' 2>/dev/null'
                    );
                }
                if (!is_file($img)) {
                    continue;
                }
                $w = (float)($sizes[$p - 1]['width_mm'] ?? 215.9);
                $h = (float)($sizes[$p - 1]['height_mm'] ?? 279.4);
                $pdf->drawAttachmentPageFromImage($img, $w, $h);
                $rendered = true;
            }
        }

        if (!$rendered) {
            $pdf->outputTextAttachment(basename($path), 'Unable to embed PDF attachment because neither pdftocairo nor gs is available on this server.');
        }
    }

    private function pdfPageSizesMm(string $path): array
    {
        if (!$this->commandExists('pdfinfo')) {
            return [];
        }

        $out = [];
        @exec('pdfinfo ' . escapeshellarg($path) . ' 2>/dev/null', $out, $rc);
        if (($rc ?? 1) !== 0) {
            return [];
        }

        $pages = 0;
        foreach ($out as $line) {
            if (preg_match('/^Pages:\s+(\d+)/i', $line, $m)) {
                $pages = (int)$m[1];
                break;
            }
        }

        $sizes = [];
        for ($p = 1; $p <= $pages; $p++) {
            $po = [];
            @exec('pdfinfo -f ' . $p . ' -l ' . $p . ' ' . escapeshellarg($path) . ' 2>/dev/null', $po, $prc);
            foreach ($po as $line) {
                if (preg_match('/Page\s+' . $p . '\s+size:\s+([\d.]+)\s+x\s+([\d.]+)\s+pts/i', $line, $m)) {
                    $sizes[] = [
                        'width_mm' => ((float)$m[1]) * 25.4 / 72.0,
                        'height_mm' => ((float)$m[2]) * 25.4 / 72.0,
                    ];
                    break;
                }
            }
        }

        return $sizes;
    }

    private function attachmentCacheDir(string $path): string
    {
        $dir = dirname(dirname(__DIR__)) . '/storage/cache/ics202_pdf/' . sha1($path . '|' . (string)@filemtime($path) . '|' . (string)@filesize($path));
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        return $dir;
    }

    private function commandExists(string $command): bool
    {
        $result = trim((string)@shell_exec('command -v ' . escapeshellarg($command) . ' 2>/dev/null'));
        return $result !== '';
    }
}
