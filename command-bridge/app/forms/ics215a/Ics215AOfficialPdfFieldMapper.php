<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/pdf/OfficialPdfText.php';

/**
 * Maps the ICS 215A Incident Action Safety Plan Analysis onto the official ICS Canada 215a template (14 rows a page).
 * Each row box holds about three lines; longer text is cut and printed in full on the continuation sheet.
 */
final class Ics215AOfficialPdfFieldMapper
{
    public const ROWS_PER_PAGE = 14;

    /** @return array{pages: array<int, array<string, mixed>>, continuations: array<int, array{heading: string, text: string}>} */
    public static function build(array $header, array $incident, array $op, array $rows): array
    {
        $cont = [];
        $p = OfficialPdfText::opParts($op, 'M j');
        $prepared = (string)($header['date_time_prepared'] ?? '');
        $sofr = trim((string)($header['sofr_name'] ?? ''));
        if ($sofr !== '' && !empty($header['sofr_signed_at'])) { $sofr .= '  (' . ics_pdf_fmt($header['sofr_signed_at']) . ')'; }
        $osc = trim((string)($header['osc_name'] ?? ''));
        if ($osc !== '' && !empty($header['osc_signed_at'])) { $osc .= '  (' . ics_pdf_fmt($header['osc_signed_at']) . ')'; }
        $common = [
            '1 Incident Name' => (string)(($header['incident_name'] ?? '') ?: ics_pdf_incident_name($incident)),
            'Text1' => ics_pdf_fmt($prepared, 'Y-m-d'),
            'Text2' => ics_pdf_fmt($prepared, 'H:i'),
            'Text3' => $p['from_date'], 'Text5' => $p['from_time'],
            'Text4' => $p['to_date'], 'Text6' => $p['to_time'],
            'Name.0' => $sofr,
            'Name.1' => $osc,
        ];
        $rows = array_values(array_filter($rows, static fn ($r) =>
            trim((string)($r['incident_area'] ?? '') . (string)($r['hazards_risks'] ?? '') . (string)($r['mitigations'] ?? '')) !== ''));
        $pages = [];
        foreach (array_chunk($rows, self::ROWS_PER_PAGE) ?: [[]] as $pi => $chunk) {
            $f = $common;
            $headings = [];
            foreach ($chunk as $i => $r) {
                $n = $i + 1;
                $label = 'Row ' . ($pi * self::ROWS_PER_PAGE + $n) . ' (' . trim((string)($r['incident_area'] ?? '')) . ')';
                $f['5 Incident AreaRow' . $n] = (string)($r['incident_area'] ?? '');
                $headings['5 Incident AreaRow' . $n] = $label . ' - Incident area';
                $f['6 HazardsRisksRow' . $n] = (string)($r['hazards_risks'] ?? '');
                $headings['6 HazardsRisksRow' . $n] = $label . ' - Hazards/risks';
                $f['7 MitigationsRow' . $n] = (string)($r['mitigations'] ?? '');
                $headings['7 MitigationsRow' . $n] = $label . ' - Mitigations';
            }
            $pages[] = OfficialPdfText::apply('ICS215A', $f, $headings, $cont);
        }
        return ['pages' => $pages, 'continuations' => $cont];
    }
}
