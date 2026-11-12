<?php
declare(strict_types=1);
require_once __DIR__ . '/TcpdfLoader.php';

class Ics218PdfRenderer
{
    public function renderInventory(array $context): void
    {
        if (!command_bridge_require_tcpdf()) {
            throw new RuntimeException('TCPDF is not installed on this server.');
        }
        require_once __DIR__ . '/BaseTcpdfRenderer.php';

        $incident = (array)($context['incident'] ?? []);
        $header = (array)($context['header'] ?? []);
        $entries = (array)($context['entries'] ?? []);

        $pdf = new class([], 'ICS 218 Support Vehicle/Equipment Inventory') extends BaseTcpdfRenderer {
            public function Header() {}
            public function Footer() {}

            public function drawInventory(array $incident, array $header, array $entries): void
            {
                $this->AddPage('L', 'LETTER');
                $this->SetFont('helvetica', 'B', 14);
                $this->SetXY(10, 8);
                $this->Cell(150, 7, 'ICS 218 - Support Vehicle/Equipment Inventory', 0, 0, 'L');

                $this->SetFont('helvetica', '', 8);
                $incidentName = (string)($incident['name'] ?? $incident['incident_name'] ?? '');
                $this->SetXY(10, 18);
                $this->Cell(90, 5, 'Incident: ' . $incidentName, 0, 0, 'L');
                $this->SetXY(105, 18);
                $this->Cell(70, 5, 'Prepared by: ' . (string)($header['prepared_by_name'] ?? ''), 0, 0, 'L');
                $this->SetXY(180, 18);
                $this->Cell(75, 5, 'Prepared: ' . (string)($header['prepared_at'] ?? ''), 0, 0, 'L');

                $cols = [
                    ['label'=>'Description (Kind / Type)', 'w'=>54],
                    ['label'=>'Owner/Agency', 'w'=>35],
                    ['label'=>'Operator', 'w'=>30],
                    ['label'=>'Contact', 'w'=>34],
                    ['label'=>'Location', 'w'=>34],
                    ['label'=>'Order #', 'w'=>28],
                    ['label'=>'Status', 'w'=>24],
                ];
                $tableX = 8;
                $tableW = 0;
                foreach ($cols as $c) { $tableW += $c['w']; }
                $x = $tableX; $y = 28; $h = 8;
                $this->SetFillColor(230,230,230);
                $this->SetFont('helvetica','B',7);
                foreach ($cols as $c) {
                    $this->Rect($x, $y, $c['w'], $h, 'DF');
                    $this->SetXY($x+1, $y+1);
                    $this->MultiCell($c['w']-2, $h-2, $c['label'], 0, 'L', false, 0);
                    $x += $c['w'];
                }
                $y += $h;
                $this->SetFont('helvetica','',6.5);
                $rowH = 19;
                foreach ($entries as $e) {
                    if ($y + $rowH > 205) {
                        $this->AddPage('L', 'LETTER');
                        $y = 12;
                        $x = $tableX;
                        $this->SetFillColor(230,230,230);
                        $this->SetFont('helvetica','B',7);
                        foreach ($cols as $c) {
                            $this->Rect($x, $y, $c['w'], $h, 'DF');
                            $this->SetXY($x+1, $y+1);
                            $this->MultiCell($c['w']-2, $h-2, $c['label'], 0, 'L', false, 0);
                            $x += $c['w'];
                        }
                        $y += $h;
                        $this->SetFont('helvetica','',6.5);
                    }
                    $kindType = trim((string)($e['kind'] ?? '') . ' / ' . (string)($e['type'] ?? ''), " /");
                    if ($kindType === '') {
                        $kindType = (string)($e['equipment_description'] ?? '');
                    }
                    $values = [
                        $kindType,
                        $e['owner_agency'] ?? '',
                        $e['operator_name'] ?? '',
                        $e['contact_info'] ?? '',
                        $e['location'] ?? '',
                        $e['order_request_number'] ?? '',
                        $e['status'] ?? '',
                    ];
                    $x = $tableX;
                    $topH = 9;
                    foreach ($cols as $i=>$c) {
                        $this->Rect($x, $y, $c['w'], $topH);
                        $this->SetXY($x+1, $y+1);
                        $this->MultiCell($c['w']-2, $topH-2, (string)$values[$i], 0, 'L', false, 0);
                        $x += $c['w'];
                    }
                    $detailY = $y + $topH;
                    $detailH = $rowH - $topH;
                    $this->Rect($tableX, $detailY, $tableW, $detailH);
                    $this->SetFont('helvetica','B',6.3);
                    $this->SetXY($tableX+1, $detailY+1);
                    $this->Cell(26, 4, 'Detailed description:', 0, 0, 'L');
                    $this->SetFont('helvetica','',6.3);
                    $this->SetXY($tableX+29, $detailY+1);
                    $this->MultiCell($tableW-31, $detailH-2, (string)($e['equipment_description'] ?? ''), 0, 'L', false, 0);
                    $this->SetFont('helvetica','',6.5);
                    $y += $rowH;
                }
                if (!$entries) {
                    $this->SetXY(8, $y+4);
                    $this->Cell(260, 6, 'No equipment entries recorded.', 0, 0, 'C');
                }
            }
        };

        $pdf->drawInventory($incident, $header, $entries);
        $pdf->Output('ICS_218_inventory.pdf', !empty($context['inline']) ? 'I' : 'D');
    }
}
