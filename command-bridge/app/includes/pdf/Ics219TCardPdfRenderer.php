<?php
declare(strict_types=1);
require_once __DIR__ . '/TcpdfLoader.php';

class Ics219TCardPdfRenderer
{
    public function renderCards(array $context): void
    {
        if (!command_bridge_require_tcpdf()) {
            throw new RuntimeException('TCPDF is not installed on this server.');
        }
        require_once __DIR__ . '/BaseTcpdfRenderer.php';

        $incident = (array)($context['incident'] ?? []);
        $entries = (array)($context['entries'] ?? []);

        $pdf = new class([], 'ICS 219 Resource Status T-Cards') extends BaseTcpdfRenderer {
            public function Header() {}
            public function Footer() {}

            private function fitText(string $text, int $maxLen): string
            {
                $text = trim(preg_replace('/\s+/', ' ', $text));
                if (function_exists('mb_strlen') && function_exists('mb_substr')) {
                    return mb_strlen($text) > $maxLen ? mb_substr($text, 0, max(0, $maxLen - 3)) . '...' : $text;
                }
                return strlen($text) > $maxLen ? substr($text, 0, max(0, $maxLen - 3)) . '...' : $text;
            }

            private function labelledBox(float $x, float $y, float $w, float $h, string $label, string $value, float $fontSize = 8.0): void
            {
                $this->SetLineWidth(0.25);
                $this->Rect($x, $y, $w, $h);
                $this->SetFont('helvetica','B',7);
                $this->SetXY($x + 1.5, $y + 1.0);
                $this->Cell($w - 3, 3.5, strtoupper($label), 0, 1, 'L');
                $this->SetFont('helvetica','', $fontSize);
                $this->SetXY($x + 1.5, $y + 5.0);
                $this->MultiCell($w - 3, $h - 6, $value, 0, 'L', false, 1, '', '', true, 0, false, true, $h - 6, 'T');
            }

            private function card(float $x, float $y, float $cardW, float $cardH, array $incident, array $e): void
            {
                $pad = 3.0;
                $this->SetLineWidth(0.45);
                $this->Rect($x, $y, $cardW, $cardH);
                $this->SetFillColor(245,245,245);
                $this->Rect($x, $y, $cardW, 16, 'DF');

                $this->SetFont('helvetica','B',12);
                $this->SetXY($x + $pad, $y + 2.5);
                $this->Cell($cardW - ($pad * 2), 5.5, 'ICS 219 T-CARD - EQUIPMENT', 0, 1, 'C');
                $this->SetFont('helvetica','',7.5);
                $this->SetXY($x + $pad, $y + 9.5);
                $this->Cell($cardW - ($pad * 2), 4, 'Generated from ICS 218 Support Vehicle/Equipment Inventory', 0, 0, 'C');

                $kindType = trim((string)($e['kind'] ?? '') . ' / ' . (string)($e['type'] ?? ''), " /");
                if ($kindType === '') {
                    $kindType = (string)($e['equipment_description'] ?? '');
                }

                $innerX = $x + $pad;
                $innerW = $cardW - ($pad * 2);
                $yy = $y + 19;

                $this->labelledBox($innerX, $yy, $innerW, 15, 'Incident', (string)($incident['name'] ?? $incident['incident_name'] ?? ''), 8);
                $yy += 17;

                $this->labelledBox($innerX, $yy, $innerW, 22, 'Resource Description - Kind / Type', $kindType, 10);
                $yy += 24;

                $halfGap = 2.0;
                $halfW = ($innerW - $halfGap) / 2.0;
                $this->labelledBox($innerX, $yy, $halfW, 14, 'Owner / Agency', (string)($e['owner_agency'] ?? ''), 7.5);
                $this->labelledBox($innerX + $halfW + $halfGap, $yy, $halfW, 14, 'Operator', (string)($e['operator_name'] ?? ''), 7.5);
                $yy += 16;

                $this->labelledBox($innerX, $yy, $halfW, 14, 'Contact', (string)($e['contact_info'] ?? ''), 7.5);
                $this->labelledBox($innerX + $halfW + $halfGap, $yy, $halfW, 14, 'Location', (string)($e['location'] ?? ''), 7.5);
                $yy += 16;

                $this->labelledBox($innerX, $yy, $halfW, 14, 'Order / Request #', (string)($e['order_request_number'] ?? ''), 7.5);
                $this->labelledBox($innerX + $halfW + $halfGap, $yy, $halfW, 14, 'Status', (string)($e['status'] ?? ''), 7.5);
                $yy += 16;

                $descH = 36;
                $this->labelledBox($innerX, $yy, $innerW, $descH, 'Detailed Description', (string)($e['equipment_description'] ?? ''), 8);
                $yy += $descH + 3;

                $remarksH = max(22.0, ($y + $cardH) - $yy - $pad);
                $this->labelledBox($innerX, $yy, $innerW, $remarksH, 'Remarks', (string)($e['remarks'] ?? ''), 8);
            }

            public function draw(array $incident, array $entries): void
            {
                if (!$entries) {
                    $this->AddPage('P','LETTER');
                    $this->SetFont('helvetica','B',12);
                    $this->SetXY(10,20);
                    $this->Cell(190,8,'No ICS 218 equipment entries available for T-Cards.',0,0,'C');
                    return;
                }

                // Letter portrait is 215.9mm x 279.4mm. Use 0.25 inch (6.35mm) outside margins.
                // Two T-cards are printed side-by-side on a portrait page. A single-card request
                // intentionally uses the left half of the sheet only so it can be cut/handled the same way.
                $margin = 6.35;
                $gap = 3.0;
                $pageW = 215.9;
                $pageH = 279.4;
                $cardW = ($pageW - ($margin * 2) - $gap) / 2.0;
                $cardH = $pageH - ($margin * 2);

                $i = 0;
                foreach ($entries as $e) {
                    if ($i % 2 === 0) {
                        $this->AddPage('P','LETTER');
                    }
                    $slot = $i % 2;
                    $x = $margin + ($slot * ($cardW + $gap));
                    $this->card($x, $margin, $cardW, $cardH, $incident, (array)$e);
                    $i++;
                }
            }
        };

        $pdf->draw($incident, $entries);
        $pdf->Output('ICS_219_T_Cards.pdf', 'I');
    }
}
