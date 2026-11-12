<?php
declare(strict_types=1);
require_once __DIR__ . '/TcpdfLoader.php';

class Ics203PdfRenderer
{
    private array $layout;
    public function __construct(array $layout = []) { $this->layout = $layout; }

    public function renderFilled(array $context): void
    {
        if (!command_bridge_require_tcpdf()) { throw new RuntimeException('TCPDF is not installed on this server.'); }
        require_once __DIR__ . '/BaseTcpdfRenderer.php';

        $incident = (array)($context['incident'] ?? []);
        $op = (array)($context['operational_period'] ?? []);
        $rows = (array)($context['assignments'] ?? []);
        $attachments = (array)($context['attachments'] ?? []);

        $pdf = new class($this->layout, 'ICS 203 Organization Assignment List') extends BaseTcpdfRenderer {
            public function drawMain(array $incident, array $op, array $rows): void
            {
                $this->AddPage('P', 'LETTER');
                $m = $this->margin();
                $fullW = $this->pageWidth() - (2 * $m);
                $this->drawHeaderBlock('');

                $y = $m + 18.8;
                $incidentName = (string)($incident['incident_name'] ?? $incident['name'] ?? '');
                $opText = trim((string)($op['name'] ?? ''));
                $start = trim((string)($op['start_time'] ?? ''));
                $end = trim((string)($op['end_time'] ?? ''));
                $opDates = trim(($opText !== '' ? $opText . ' | ' : '') . $start . ($end !== '' ? ' to ' . $end : ''));
                $this->drawLabeledBox($m, $y, $fullW * 0.54, 13, '1. Incident Name', $incidentName);
                $this->drawLabeledBox($m + ($fullW * 0.54), $y, $fullW * 0.46, 13, '2. Operational Period', $opDates, 7.8);
                $y += 16;

                $this->SetFont('helvetica', 'B', 10);
                $this->SetXY($m, $y);
                $this->Cell($fullW, 6, '3. Organization Assignment List', 0, 1, 'L');
                $y += 7;

                $col1 = 48; $col2 = $fullW - $col1;
                $lastSection = null;
                foreach ($rows as $row) {
                    if ($y > $this->pageHeight() - $m - 18) {
                        $this->AddPage('P', 'LETTER');
                        $this->drawHeaderBlock('');
                        $y = $m + 18;
                    }
                    $section = (string)($row['section_name'] ?? '');
                    if ($section !== $lastSection) {
                        $this->SetFillColor(230, 230, 230);
                        $this->SetFont('helvetica', 'B', 8.5);
                        $this->Rect($m, $y, $fullW, 6, 'DF');
                        $this->SetXY($m + 1.5, $y + 1.2);
                        $this->Cell($fullW - 3, 3.5, $section, 0, 0, 'L');
                        $y += 6;
                        $lastSection = $section;
                    }
                    $this->SetFillColor(255,255,255);
                    $this->Rect($m, $y, $col1, 7);
                    $this->Rect($m + $col1, $y, $col2, 7);
                    $this->SetFont('helvetica', '', 8);
                    $this->SetXY($m + 1.5, $y + 1.5);
                    $this->Cell($col1 - 3, 4, (string)($row['position_title'] ?? ''), 0, 0, 'L');
                    $this->SetFont('helvetica', '', 8.5);
                    $this->SetXY($m + $col1 + 1.5, $y + 1.5);
                    $this->Cell($col2 - 3, 4, (string)($row['assigned_name'] ?? ''), 0, 0, 'L');
                    $y += 7;
                }
                $this->SetFont('helvetica', '', 7.2);
                $this->SetXY($m, $this->pageHeight() - $m - 3.5);
                $this->Cell($fullW, 3, 'ICS 203', 0, 0, 'R');
            }
        };
        $pdf->drawMain($incident, $op, $rows);
        $filename = 'ICS203_' . preg_replace('/[^A-Za-z0-9_-]+/', '_', (string)($incident['incident_number'] ?? 'incident')) . '_' . date('Ymd_His') . '.pdf';
        $pdf->Output($filename, !empty($context['inline']) ? 'I' : 'D');
    }
}
