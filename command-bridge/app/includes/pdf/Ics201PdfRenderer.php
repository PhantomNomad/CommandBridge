<?php
declare(strict_types=1);
require_once __DIR__ . '/TcpdfLoader.php';

class Ics201PdfRenderer
{
    private array $layout;

    public function __construct(array $layout)
    {
        $this->layout = $layout;
    }

    public function renderBlankPreview(): void
    {
        if (!command_bridge_require_tcpdf()) {
            throw new RuntimeException('TCPDF is not installed on this server.');
        }

        require_once __DIR__ . '/BaseTcpdfRenderer.php';

        $pdf = new class($this->layout, 'ICS 201 Blank PDF Preview') extends BaseTcpdfRenderer {
            public function build(): void
            {
                $preparedBy = '';
                $signature = '';
                $pageCount = 4;
                $this->drawPage1([], [], $preparedBy, $signature, $pageCount);
                $this->drawPage2([], [], [], $preparedBy, $signature, $pageCount);
                $this->drawPage3([], $preparedBy, $signature, $pageCount);
                $this->drawPage4([], $preparedBy, $signature, $pageCount);
            }

            public function drawPage1(array $record, array $meta, string $preparedBy, string $signature, int $pageCount): void
            {
                $this->AddPage('P', 'LETTER');
                $m = $this->margin();
                $contentY = $m + 18.8;
                $fullW = $this->pageWidth() - (2 * $m);

                $this->drawHeaderBlock('Page 1');
                $this->drawLabeledBox($m, $contentY, 118, 12, '1. Incident Name / Number');
                $this->drawLabeledBox($m + 118, $contentY, 38, 12, '2. Date Prepared', '');
                $this->drawLabeledBox($m + 156, $contentY, $fullW - 156, 12, '3. Time Prepared', '');

                $y2 = $contentY + 14.5;
                $this->drawWrappedTextBox($m, $y2, $fullW, 82, '4. Map / Sketch', '');

                $y3 = $y2 + 84.5;
                $this->drawWrappedTextBox($m, $y3, $fullW, 84, '5. Situation Summary and Health / Safety Briefing', '');

                $this->drawFooterSignature($preparedBy, $signature, 1, $pageCount);
            }

            public function drawPage2(array $record, array $currentActions, array $plannedActions, string $preparedBy, string $signature, int $pageCount): void
            {
                $this->AddPage('P', 'LETTER');
                $m = $this->margin();
                $this->drawHeaderBlock('Page 2');
                $contentY = $m + 18.8;
                $fullW = $this->pageWidth() - (2 * $m);

                $halfW = ($fullW - 4) / 2;
                $this->drawWrappedTextBox($m, $contentY, $halfW, 34, '6. Current Objectives', '');
                $this->drawWrappedTextBox($m + $halfW + 4, $contentY, $halfW, 34, '7. Planned Objectives', '');

                $tableY = $contentY + 36.5;
                $tableH = 136.5;
                $this->drawActionsTable($m, $tableY, $halfW, $tableH, '8. Current Actions, Strategies and Tactics', []);
                $this->drawActionsTable($m + $halfW + 4, $tableY, $halfW, $tableH, '9. Planned Actions, Strategies and Tactics', []);

                $this->drawFooterSignature($preparedBy, $signature, 2, $pageCount);
            }

            public function drawPage3(array $record, string $preparedBy, string $signature, int $pageCount): void
            {
                $this->AddPage('P', 'LETTER');
                $m = $this->margin();
                $this->drawHeaderBlock('Page 3');
                $contentY = $m + 18.8;
                $fullW = $this->pageWidth() - (2 * $m);

                $this->drawWrappedTextBox($m, $contentY, $fullW, 210, '10. Current Organization', '');
                $this->drawFooterSignature($preparedBy, $signature, 3, $pageCount);
            }

            public function drawPage4(array $resources, string $preparedBy, string $signature, int $pageCount): void
            {
                $this->AddPage('P', 'LETTER');
                $m = $this->margin();
                $this->drawHeaderBlock('Page 4');
                $contentY = $m + 18.8;
                $fullW = $this->pageWidth() - (2 * $m);
                $this->drawResourcesTable($m, $contentY, $fullW, 210, '11. Resource Summary', []);
                $this->drawFooterSignature($preparedBy, $signature, 4, $pageCount);
            }

            private function drawActionsTable(float $x, float $y, float $w, float $h, string $title, array $rows): void
            {
                $this->SetLineWidth(0.25);
                $this->Rect($x, $y, $w, $h);
                $this->SetFont('helvetica', 'B', 8.2);
                $this->SetXY($x + 1, $y + 1);
                $this->Cell($w - 2, 4.5, $title, 0, 1, 'L');

                $headerY = $y + 7;
                $timeW = 28;
                $this->Rect($x, $headerY, $timeW, 7.5);
                $this->Rect($x + $timeW, $headerY, $w - $timeW, 7.5);
                $this->SetFont('helvetica', '', 7.2);
                $this->SetXY($x + 1, $headerY + 2.0);
                $this->Cell($timeW - 2, 3.5, 'Time', 0, 0, 'L');
                $this->SetXY($x + $timeW + 1, $headerY + 2.0);
                $this->Cell($w - $timeW - 2, 3.5, 'Actions', 0, 0, 'L');

                $bodyTop = $headerY + 7.5;
                $bodyH = $h - ($bodyTop - $y);
                $rowH = 12.0;
                $rowsToDraw = max(1, (int)floor($bodyH / $rowH));
                for ($i = 0; $i < $rowsToDraw; $i++) {
                    $ry = $bodyTop + ($i * $rowH);
                    if ($ry + $rowH > $y + $h + 0.01) {
                        break;
                    }
                    $this->Rect($x, $ry, $timeW, $rowH);
                    $this->Rect($x + $timeW, $ry, $w - $timeW, $rowH);
                }
            }

            private function drawResourcesTable(float $x, float $y, float $w, float $h, string $title, array $rows): void
            {
                $this->SetLineWidth(0.25);
                $this->Rect($x, $y, $w, $h);
                $this->SetFont('helvetica', 'B', 8.2);
                $this->SetXY($x + 1, $y + 1);
                $this->Cell($w - 2, 4.5, $title, 0, 1, 'L');

                $headerY = $y + 7;
                $colWidths = [26, 47, 19, 19, $w - 26 - 47 - 19 - 19];
                $labels = ['Resources Ordered', 'Resource Identification', 'ETA', 'On Scene', 'Location / Assignment'];
                $cx = $x;
                foreach ($colWidths as $i => $colW) {
                    $this->Rect($cx, $headerY, $colW, 10);
                    $this->SetFont('helvetica', '', 6.8);
                    $this->SetXY($cx + 0.8, $headerY + 2.2);
                    $this->MultiCell($colW - 1.6, 2.8, $labels[$i], 0, 'L', false, 1, '', '', true, 0, false, true, 6.5, 'T', false);
                    $cx += $colW;
                }

                $bodyTop = $headerY + 10;
                $bodyH = $h - ($bodyTop - $y);
                $rowH = 12.0;
                $rowsToDraw = max(1, (int)floor($bodyH / $rowH));
                for ($i = 0; $i < $rowsToDraw; $i++) {
                    $ry = $bodyTop + ($i * $rowH);
                    if ($ry + $rowH > $y + $h + 0.01) {
                        break;
                    }
                    $cx = $x;
                    foreach ($colWidths as $colW) {
                        $this->Rect($cx, $ry, $colW, $rowH);
                        $cx += $colW;
                    }
                }
            }
        };

        $pdf->build();
        $pdf->Output('ICS201_blank_preview.pdf', 'I');
    }

    public function renderFilled(array $context): void
    {
        if (!command_bridge_require_tcpdf()) {
            throw new RuntimeException('TCPDF is not installed on this server.');
        }

        require_once __DIR__ . '/BaseTcpdfRenderer.php';

        $layout = $this->layout;
        $record = (array)($context['record'] ?? []);
        $incident = (array)($context['incident'] ?? []);
        $attachments = (array)($context['attachments'] ?? []);
        $currentActions = (array)($context['current_actions'] ?? []);
        $plannedActions = (array)($context['planned_actions'] ?? []);
        $resources = (array)($context['resources'] ?? []);
        $preparedBy = (string)($context['prepared_by'] ?? '');
        $signatureName = (string)($context['signature_name'] ?? '');

        $pdf = new class($layout, 'ICS 201 Incident Briefing') extends BaseTcpdfRenderer {
            public function drawActionsTableFilled(float $x, float $y, float $w, float $h, string $title, array $rows): void
            {
                $this->SetLineWidth(0.25);
                $this->Rect($x, $y, $w, $h);
                $this->SetFont('helvetica', 'B', 8.2);
                $this->SetXY($x + 1, $y + 1);
                $this->Cell($w - 2, 4.5, $title, 0, 1, 'L');

                $headerY = $y + 7;
                $timeW = 28;
                $this->Rect($x, $headerY, $timeW, 7.5);
                $this->Rect($x + $timeW, $headerY, $w - $timeW, 7.5);
                $this->SetFont('helvetica', '', 7.2);
                $this->SetXY($x + 1, $headerY + 2.0);
                $this->Cell($timeW - 2, 3.5, 'Time', 0, 0, 'L');
                $this->SetXY($x + $timeW + 1, $headerY + 2.0);
                $this->Cell($w - $timeW - 2, 3.5, 'Actions', 0, 0, 'L');

                $bodyTop = $headerY + 7.5;
                $bodyH = $h - ($bodyTop - $y);
                $rowH = 12.0;
                $rowsToDraw = max(1, (int)floor($bodyH / $rowH));

                for ($i = 0; $i < $rowsToDraw; $i++) {
                    $ry = $bodyTop + ($i * $rowH);
                    if ($ry + $rowH > $y + $h + 0.01) {
                        break;
                    }
                    $this->Rect($x, $ry, $timeW, $rowH);
                    $this->Rect($x + $timeW, $ry, $w - $timeW, $rowH);

                    $row = $rows[$i] ?? [];
                    $time = trim((string)($row['action_time'] ?? ''));
                    $text = trim((string)($row['action_text'] ?? ''));
                    if ($time !== '') {
                        $this->SetFont('helvetica', '', 8.0);
                        $this->SetXY($x + 1, $ry + 1.5);
                        $this->Cell($timeW - 2, 4, $time, 0, 0, 'L');
                    }
                    if ($text !== '') {
                        $this->SetFont('helvetica', '', 7.6);
                        $this->SetXY($x + $timeW + 1, $ry + 1.2);
                        $this->MultiCell($w - $timeW - 2, 3.2, $text, 0, 'L', false, 1, '', '', true, 0, false, true, $rowH - 2, 'T', false);
                    }
                }
            }

            public function drawResourcesTableFilled(float $x, float $y, float $w, float $h, string $title, array $rows): void
            {
                $this->SetLineWidth(0.25);
                $this->Rect($x, $y, $w, $h);
                $this->SetFont('helvetica', 'B', 8.2);
                $this->SetXY($x + 1, $y + 1);
                $this->Cell($w - 2, 4.5, $title, 0, 1, 'L');

                $headerY = $y + 7;
                $colWidths = [26, 47, 19, 19, $w - 26 - 47 - 19 - 19];
                $labels = ['Resources Ordered', 'Resource Identification', 'ETA', 'On Scene', 'Location / Assignment'];
                $cx = $x;
                foreach ($colWidths as $i => $colW) {
                    $this->Rect($cx, $headerY, $colW, 10);
                    $this->SetFont('helvetica', '', 6.8);
                    $this->SetXY($cx + 0.8, $headerY + 2.2);
                    $this->MultiCell($colW - 1.6, 2.8, $labels[$i], 0, 'L', false, 1, '', '', true, 0, false, true, 6.5, 'T', false);
                    $cx += $colW;
                }

                $bodyTop = $headerY + 10;
                $bodyH = $h - ($bodyTop - $y);
                $rowH = 12.0;
                $rowsToDraw = max(1, (int)floor($bodyH / $rowH));

                for ($i = 0; $i < $rowsToDraw; $i++) {
                    $ry = $bodyTop + ($i * $rowH);
                    if ($ry + $rowH > $y + $h + 0.01) {
                        break;
                    }
                    $cx = $x;
                    $vals = $rows[$i] ?? [];
                    $texts = [
                        trim((string)($vals['resources_ordered'] ?? '')),
                        trim((string)($vals['resource_identification'] ?? '')),
                        trim((string)($vals['eta'] ?? '')),
                        trim((string)($vals['on_scene'] ?? '')),
                        trim((string)($vals['location_assignment'] ?? '')),
                    ];
                    foreach ($colWidths as $colIdx => $colW) {
                        $this->Rect($cx, $ry, $colW, $rowH);
                        if ($texts[$colIdx] !== '') {
                            $this->SetFont('helvetica', '', 7.0);
                            $this->SetXY($cx + 0.8, $ry + 1.2);
                            $this->MultiCell($colW - 1.6, 3.0, $texts[$colIdx], 0, 'L', false, 1, '', '', true, 0, false, true, $rowH - 2, 'T', false);
                        }
                        $cx += $colW;
                    }
                }
            }
        };

        $incidentName = trim((string)($incident['incident_name'] ?? $incident['name'] ?? ''));
        $incidentNumber = trim((string)($incident['incident_number'] ?? ''));
        $incidentDisplay = trim($incidentName . ($incidentNumber !== '' ? ' / ' . $incidentNumber : ''));

        $dtPrepared = trim((string)($record['datetime_prepared'] ?? ''));
        $datePrepared = '';
        $timePrepared = '';
        if ($dtPrepared !== '') {
            $ts = strtotime($dtPrepared);
            if ($ts !== false) {
                $datePrepared = date('Y-m-d', $ts);
                $timePrepared = date('H:i', $ts);
            }
        }

        $mapText = !empty($attachments) ? 'See attached pages.' : '';

        $pageCount = 4;
        $m = (float)($layout['page']['margin_mm'] ?? 6.35);
        $fullW = (float)($layout['page']['width_mm'] ?? 215.9) - (2 * $m);
        $contentY = $m + 18.8;

        $pdf->AddPage('P', 'LETTER');
        $pdf->drawHeaderBlock('Page 1');
        $pdf->drawLabeledBox($m, $contentY, 118, 12, '1. Incident Name / Number', $incidentDisplay, 9.5);
        $pdf->drawLabeledBox($m + 118, $contentY, 38, 12, '2. Date Prepared', $datePrepared, 8.8, 'C');
        $pdf->drawLabeledBox($m + 156, $contentY, $fullW - 156, 12, '3. Time Prepared', $timePrepared, 8.8, 'C');
        $pdf->drawWrappedTextBox($m, $contentY + 14.5, $fullW, 82, '4. Map / Sketch', $mapText, 9.0);
        $summary = trim((string)($record['situation_summary'] ?? ''));
        $safety = trim((string)($record['safety_briefing'] ?? ''));
        $combined = $summary;
        if ($safety !== '') {
            $combined .= ($combined !== '' ? "\n\n" : '') . 'Safety Briefing:' . "\n" . $safety;
        }
        $pdf->drawWrappedTextBox($m, $contentY + 99.0, $fullW, 84, '5. Situation Summary and Health / Safety Briefing', $combined, 8.4);
        $pdf->drawFooterSignature($preparedBy, $signatureName, 1, $pageCount);

        $pdf->AddPage('P', 'LETTER');
        $pdf->drawHeaderBlock('Page 2');
        $halfW = ($fullW - 4) / 2;
        $pdf->drawWrappedTextBox($m, $contentY, $halfW, 34, '6. Current Objectives', trim((string)($record['current_objectives'] ?? '')), 8.2);
        $pdf->drawWrappedTextBox($m + $halfW + 4, $contentY, $halfW, 34, '7. Planned Objectives', trim((string)($record['planned_objectives'] ?? '')), 8.2);
        $tableY = $contentY + 36.5;
        $tableH = 136.5;
        $pdf->drawActionsTableFilled($m, $tableY, $halfW, $tableH, '8. Current Actions, Strategies and Tactics', $currentActions);
        $pdf->drawActionsTableFilled($m + $halfW + 4, $tableY, $halfW, $tableH, '9. Planned Actions, Strategies and Tactics', $plannedActions);
        $pdf->drawFooterSignature($preparedBy, $signatureName, 2, $pageCount);

        $pdf->AddPage('P', 'LETTER');
        $pdf->drawHeaderBlock('Page 3');
        $pdf->drawWrappedTextBox($m, $contentY, $fullW, 210, '10. Current Organization', trim((string)($record['current_organization'] ?? '')), 8.2);
        $pdf->drawFooterSignature($preparedBy, $signatureName, 3, $pageCount);

        $pdf->AddPage('P', 'LETTER');
        $pdf->drawHeaderBlock('Page 4');
        $pdf->drawResourcesTableFilled($m, $contentY, $fullW, 210, '11. Resource Summary', $resources);
        $pdf->drawFooterSignature($preparedBy, $signatureName, 4, $pageCount);

        foreach ($attachments as $attachment) {
            $this->appendAttachment($pdf, (array)$attachment);
        }

        $filename = 'ICS201_' . preg_replace('/[^A-Za-z0-9_-]+/', '_', (string)($incident['incident_number'] ?? 'incident')) . '_' . date('Ymd_His') . '.pdf';
        $pdf->Output($filename, !empty($context['inline']) ? 'I' : 'D');
    }

    /**
     * Writes only the uploaded maps/sketches (images, PDFs, text) as pages of their own, for appending after the
     * official ICS 201. Returns false when there was nothing to write.
     */
    public function writeAttachmentsPdf(array $attachments, string $outPath): bool
    {
        if (!$attachments || !command_bridge_require_tcpdf()) {
            return false;
        }
        require_once __DIR__ . '/BaseTcpdfRenderer.php';
        $pdf = new class($this->layout, 'ICS 201 attachments') extends BaseTcpdfRenderer {};
        foreach ($attachments as $attachment) {
            $this->appendAttachment($pdf, (array)$attachment);
        }
        if ($pdf->getNumPages() === 0) {
            return false;
        }
        $pdf->Output($outPath, 'F');
        return is_file($outPath) && filesize($outPath) > 0;
    }

    private function appendAttachment(BaseTcpdfRenderer $pdf, array $attachment): void
    {
        $path = ics201_attachment_absolute_path($attachment);
        if (!is_file($path)) {
            return;
        }

        $ext = strtolower((string)($attachment['file_ext'] ?? pathinfo($path, PATHINFO_EXTENSION)));
        $filename = (string)($attachment['original_filename'] ?? basename($path));

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
        $dir = dirname(dirname(__DIR__)) . '/storage/cache/ics201_pdf/' . sha1($path . '|' . (string)@filemtime($path) . '|' . (string)@filesize($path));
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
