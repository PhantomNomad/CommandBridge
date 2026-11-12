<?php
declare(strict_types=1);
require_once __DIR__ . '/TcpdfLoader.php';

if (!command_bridge_require_tcpdf()) {
    throw new RuntimeException('TCPDF is not installed on this server.');
}

require_once __DIR__ . '/BaseTcpdfRenderer.php';

final class Ics230PdfRenderer extends BaseTcpdfRenderer
{
    private array $data;
    private string $mode;

    public function __construct(array $data)
    {
        $this->data = $data;
        $this->mode = in_array((string)($data['mode'] ?? 'full'), ['full', 'posting'], true)
            ? (string)$data['mode']
            : 'full';

        $title = $this->mode === 'posting'
            ? 'Meeting Schedule Posting'
            : 'ICS 230 - Daily Meeting Schedule';

        parent::__construct([
            'title' => $title,
            'form_code' => 'ICS 230',
            'logo_path' => '',
            'page' => [
                'width_mm' => 215.9,
                'height_mm' => 279.4,
                'margin_mm' => 10.0,
            ],
        ], $title);
    }

    public function renderDocument(): void
    {
        if (!command_bridge_require_tcpdf()) {
            throw new RuntimeException('TCPDF is not installed on this server.');
        }

        $this->SetMargins(0, 0, 0, true);
        $this->SetAutoPageBreak(false, 0);

        if ($this->mode === 'posting') {
            $this->buildPosting();
            return;
        }

        $this->buildFull();
    }

    private function buildFull(): void
    {
        $this->AddPage('P', 'LETTER');
        $this->drawHeaderBlock('');

        $m = $this->margin();
        $usableW = $this->pageWidth() - (2 * $m);
        $y = $m + 19.5;

        $this->drawMetaBlock($y);
        $y += 26.0;

        $this->drawFullTableHeader($y);
        $y += 8.0;

        $meetings = $this->normalizedMeetings();
        if (!$meetings) {
            $this->SetFont('helvetica', '', 9);
            $this->Rect($m, $y, $usableW, 10);
            $this->SetXY($m + 1.5, $y + 2.5);
            $this->Cell($usableW - 3, 5, 'No meetings scheduled for this operational period.', 0, 1, 'L');
            $y += 10.0;
        } else {
            foreach ($meetings as $meeting) {
                $rowH = $this->estimateRowHeight([
                    [32.0, $meeting['scheduled_at_display']],
                    [32.0, $meeting['meeting_type_name']],
                    [44.0, $meeting['title']],
                    [34.0, $meeting['location']],
                    [24.0, $meeting['chair']],
                    [29.0, $meeting['details']],
                ], 8.2, 9.0);

                if ($y + $rowH > ($this->pageHeight() - $m - 22.0)) {
                    $this->AddPage('P', 'LETTER');
                    $this->drawHeaderBlock('');
                    $y = $m + 19.5;
                    $this->drawMetaBlock($y);
                    $y += 26.0;
                    $this->drawFullTableHeader($y);
                    $y += 8.0;
                }

                $this->drawRow($y, [
                    [32.0, $meeting['scheduled_at_display']],
                    [32.0, $meeting['meeting_type_name']],
                    [44.0, $meeting['title']],
                    [34.0, $meeting['location']],
                    [24.0, $meeting['chair']],
                    [29.0, $meeting['details']],
                ], 8.2, $rowH);
                $y += $rowH;
            }
        }

        $this->drawPreparedFooter($y + 4.0);
    }

    private function buildPosting(): void
    {
        $this->AddPage('P', 'LETTER');
        $this->drawHeaderBlock('');

        $m = $this->margin();
        $usableW = $this->pageWidth() - (2 * $m);
        $y = $m + 19.5;

        $this->drawMetaBlock($y);
        $y += 26.0;

        $this->SetFont('helvetica', 'B', 11);
        $this->SetXY($m, $y);
        $this->Cell($usableW, 6, 'Meeting Posting Schedule', 0, 1, 'L');
        $y += 7.0;

        $this->drawPostingTableHeader($y);
        $y += 9.0;

        $meetings = $this->normalizedMeetings();
        if (!$meetings) {
            $this->SetFont('helvetica', '', 10);
            $this->Rect($m, $y, $usableW, 12);
            $this->SetXY($m + 1.5, $y + 3.0);
            $this->Cell($usableW - 3, 5, 'No meetings scheduled for this operational period.', 0, 1, 'L');
            $y += 12.0;
        } else {
            foreach ($meetings as $meeting) {
                $rowH = $this->estimateRowHeight([
                    [46.0, $meeting['scheduled_at_display']],
                    [92.0, $meeting['title']],
                    [57.0, $meeting['location']],
                ], 10.0, 10.0);

                if ($y + $rowH > ($this->pageHeight() - $m - 22.0)) {
                    $this->AddPage('P', 'LETTER');
                    $this->drawHeaderBlock('');
                    $y = $m + 19.5;
                    $this->drawMetaBlock($y);
                    $y += 26.0;
                    $this->SetFont('helvetica', 'B', 11);
                    $this->SetXY($m, $y);
                    $this->Cell($usableW, 6, 'Meeting Posting Schedule', 0, 1, 'L');
                    $y += 7.0;
                    $this->drawPostingTableHeader($y);
                    $y += 9.0;
                }

                $this->drawRow($y, [
                    [46.0, $meeting['scheduled_at_display']],
                    [92.0, $meeting['title']],
                    [57.0, $meeting['location']],
                ], 10.0, $rowH);
                $y += $rowH;
            }
        }

        $this->drawPreparedFooter($y + 4.0);
    }

    private function drawMetaBlock(float $y): void
    {
        $incident = (array)($this->data['incident'] ?? []);
        $period = (array)($this->data['operational_period'] ?? []);

        $m = $this->margin();
        $usableW = $this->pageWidth() - (2 * $m);

        $incidentName = trim((string)($incident['name'] ?? ''));
        if ($incidentName === '') {
            $incidentName = trim((string)($incident['incident_name'] ?? ''));
        }
        if ($incidentName === '') {
            $incidentName = 'Unknown Incident';
        }

        $opLabel = operational_period_label($period);

        $this->SetFont('helvetica', '', 9.2);
        $this->Rect($m, $y, $usableW * 0.52, 11.5);
        $this->Rect($m + ($usableW * 0.52), $y, $usableW * 0.48, 11.5);
        $this->SetXY($m + 1.5, $y + 1.2);
        $this->Cell(30, 4.0, 'Incident Name', 0, 0, 'L');
        $this->SetFont('helvetica', 'B', 9.4);
        $this->SetXY($m + 1.5, $y + 5.2);
        $this->Cell(($usableW * 0.52) - 3, 4.2, $incidentName, 0, 0, 'L');

        $this->SetFont('helvetica', '', 9.2);
        $this->SetXY($m + ($usableW * 0.52) + 1.5, $y + 1.2);
        $this->Cell(40, 4.0, 'Operational Period', 0, 0, 'L');
        $this->SetFont('helvetica', 'B', 9.2);
        $this->SetXY($m + ($usableW * 0.52) + 1.5, $y + 5.0);
        $this->MultiCell(($usableW * 0.48) - 3, 4.2, $opLabel, 0, 'L', false, 1);
    }

    private function drawFullTableHeader(float $y): void
    {
        $this->SetFont('helvetica', 'B', 8.4);
        $this->SetFillColor(230, 230, 230);
        $this->drawHeaderCells($y, [
            [32.0, 'Date / Time'],
            [32.0, 'Meeting Type'],
            [44.0, 'Meeting Name'],
            [34.0, 'Location'],
            [24.0, 'Chair'],
            [29.0, 'Notes'],
        ]);
    }

    private function drawPostingTableHeader(float $y): void
    {
        $this->SetFont('helvetica', 'B', 10.0);
        $this->SetFillColor(230, 230, 230);
        $this->drawHeaderCells($y, [
            [46.0, 'Date / Time'],
            [92.0, 'Meeting'],
            [57.0, 'Location'],
        ], 9.0);
    }

    private function drawHeaderCells(float $y, array $cells, float $height = 8.0): void
    {
        $x = $this->margin();
        foreach ($cells as [$w, $label]) {
            $this->SetXY($x, $y);
            $this->Cell((float)$w, $height, (string)$label, 1, 0, 'C', true);
            $x += (float)$w;
        }
        $this->Ln();
    }

    private function drawRow(float $y, array $cells, float $fontSize, float $rowHeight): void
    {
        $x = $this->margin();
        $this->SetFont('helvetica', '', $fontSize);

        foreach ($cells as [$w, $text]) {
            $this->Rect($x, $y, (float)$w, $rowHeight);
            $this->SetXY($x + 1.0, $y + 1.0);
            $this->MultiCell((float)$w - 2.0, $rowHeight - 2.0, (string)$text, 0, 'L', false, 0);
            $x += (float)$w;
        }

        $this->SetXY($this->margin(), $y + $rowHeight);
    }

    private function estimateRowHeight(array $cells, float $fontSize, float $minimum): float
    {
        $this->SetFont('helvetica', '', $fontSize);
        $max = $minimum;
        foreach ($cells as [$w, $text]) {
            $h = $this->getStringHeight((float)$w - 2.0, (string)$text, false, true, '', 1);
            $max = max($max, $h + 2.0);
        }
        return $max;
    }

    private function drawPreparedFooter(float $y): void
    {
        $m = $this->margin();
        $usableW = $this->pageWidth() - (2 * $m);

        if ($y > ($this->pageHeight() - $m - 16.0)) {
            $this->AddPage('P', 'LETTER');
            $this->drawHeaderBlock('');
            $y = $m + 24.0;
        }

        $preparedName = trim((string)($this->data['prepared_by_name'] ?? ''));
        $preparedPosition = trim((string)($this->data['prepared_by_position'] ?? ''));
        $preparedAt = format_datetime_display((string)($this->data['prepared_at'] ?? ''));

        $preparedBy = $preparedName !== '' ? $preparedName : 'TBD';
        if ($preparedPosition !== '') {
            $preparedBy .= ' (' . $preparedPosition . ')';
        }

        $this->SetFont('helvetica', '', 9.0);
        $this->Rect($m, $y, $usableW * 0.62, 11.5);
        $this->Rect($m + ($usableW * 0.62), $y, $usableW * 0.38, 11.5);

        $this->SetXY($m + 1.5, $y + 1.2);
        $this->Cell(40, 4.0, 'Prepared By', 0, 0, 'L');
        $this->SetXY($m + 1.5, $y + 5.2);
        $this->Cell(($usableW * 0.62) - 3, 4.2, $preparedBy, 0, 0, 'L');

        $this->SetXY($m + ($usableW * 0.62) + 1.5, $y + 1.2);
        $this->Cell(28, 4.0, 'Date / Time', 0, 0, 'L');
        $this->SetXY($m + ($usableW * 0.62) + 1.5, $y + 5.2);
        $this->Cell(($usableW * 0.38) - 3, 4.2, $preparedAt, 0, 0, 'L');
    }

    private function normalizedMeetings(): array
    {
        $meetings = is_array($this->data['meetings'] ?? null) ? $this->data['meetings'] : [];
        $normalized = [];

        foreach ($meetings as $meeting) {
            $title = trim((string)($meeting['title'] ?? ''));
            if ($title === '') {
                $title = trim((string)($meeting['meeting_type_name'] ?? 'Meeting'));
            }

            $chair = trim((string)($meeting['chair_name'] ?? ''));
            $chairPosition = trim((string)($meeting['chair_position_title'] ?? ''));
            if ($chair === '' && $chairPosition !== '') {
                $chair = $chairPosition;
            } elseif ($chair !== '' && $chairPosition !== '') {
                $chair .= "\n" . $chairPosition;
            }

            $detailParts = [];
            foreach ([
                'purpose' => 'Purpose',
                'attendees_summary' => 'Attendees',
                'notes' => 'Notes',
            ] as $field => $label) {
                $value = trim((string)($meeting[$field] ?? ''));
                if ($value !== '') {
                    $detailParts[] = $label . ': ' . $value;
                }
            }

            $normalized[] = [
                'scheduled_at_display' => format_datetime_display((string)($meeting['scheduled_at'] ?? '')),
                'meeting_type_name' => trim((string)($meeting['meeting_type_name'] ?? 'Meeting')),
                'title' => $title,
                'location' => trim((string)($meeting['location'] ?? '')),
                'chair' => $chair,
                'details' => $detailParts ? implode("\n", $detailParts) : ' ',
            ];
        }

        return $normalized;
    }
}
