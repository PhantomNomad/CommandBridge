<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/pdf/OfficialPdfText.php';

/**
 * Maps the ICS 206 Medical Plan onto the official ICS Canada 206 template (5 rows each for aid stations,
 * transportation and hospitals). More rows continue on extra copies of the form; block 7 prints on the first copy.
 * The template's check boxes are numbered across the page, so their names are listed per row below.
 */
final class Ics206OfficialPdfFieldMapper
{
    public const ROWS = 5;
    /** Aid station "Paramedics" Yes/No boxes, rows 1-5. */
    private const PARAMEDICS = [[15, 16], [17, 20], [18, 21], [19, 22], [23, 24]];

    /** @return array{pages: array<int, array<string, mixed>>, continuations: array<int, array{heading: string, text: string}>} */
    public static function build(array $header, array $incident, array $op, array $formRecord, array $aid, array $transport, array $hospitals): array
    {
        $cont = [];
        $p = OfficialPdfText::opParts($op, 'M j');
        $prepared = (string)($formRecord['prepared_at'] ?? $formRecord['updated_at'] ?? '');
        $common = [
            '1 INCIDENT NAME' => ics_pdf_incident_name($incident),
            '2 DATE PREPARED' => ics_pdf_fmt($prepared, 'M j'),
            'time prep' => ics_pdf_fmt($prepared, 'H:i'),
            'Text1' => $p['from_date'], 'Text2' => $p['from_time'],
            'Text3' => $p['to_date'], 'Text4' => $p['to_time'],
        ];
        $first = $common;
        $first['8. SPECIAL MEDICAL EMERGENCY PROCEDURES'] = (string)($header['medical_emergency_procedures'] ?? '');
        $preparedBy = trim(trim((string)($header['prepared_by_name'] ?? '')) . ', ' . trim((string)($header['prepared_by_position_title'] ?? '')), ', ');
        $first['PREPARED BY Medical Unit Leader'] = $preparedBy;

        $aid = array_values($aid);
        $transport = array_values($transport);
        $hospitals = array_values($hospitals);
        $pageCount = max(1, (int)ceil(max(count($aid), count($transport), count($hospitals)) / self::ROWS));
        $pages = [];
        for ($pg = 0; $pg < $pageCount; $pg++) {
            $f = $pg === 0 ? $first : $common;
            $headings = $pg === 0 ? ['8. SPECIAL MEDICAL EMERGENCY PROCEDURES' => '7. Special Medical Emergency Procedures'] : [];
            for ($i = 0; $i < self::ROWS; $i++) {
                $n = $i + 1;
                $k = $pg * self::ROWS + $i;
                if (isset($aid[$k])) {
                    $a = $aid[$k];
                    $f['Medical Aid StationsRow' . $n] = (string)($a['name'] ?? '');
                    $f['LocationRow' . $n] = (string)($a['location'] ?? '');
                    $f['Text' . (4 + $n)] = (string)($a['contact'] ?? '');
                    $f['Check Box' . self::PARAMEDICS[$i][!empty($a['paramedics']) ? 0 : 1]] = OfficialPdfText::check();
                }
                if (isset($transport[$k])) {
                    $t = $transport[$k];
                    $f['Ambulance ServiceRow' . $n] = (string)($t['ambulance_service'] ?? '');
                    $f['LocationRow' . $n . '_2'] = (string)($t['location'] ?? '');
                    $f['Contact number or frequencyRow' . $n] = (string)($t['contact'] ?? '');
                    $level = strtoupper(trim((string)($t['level_of_service'] ?? '')));
                    if (str_contains($level, 'ALS')) { $f['Check Box' . (3 + 2 * $n)] = OfficialPdfText::check(); }
                    if (str_contains($level, 'BLS')) { $f['Check Box' . (4 + 2 * $n)] = OfficialPdfText::check(); }
                }
                if (isset($hospitals[$k])) {
                    $h = $hospitals[$k];
                    $contact = trim(trim((string)($h['contact'] ?? '')) . ' ' . trim((string)($h['phone'] ?? '')));
                    $f['Hospital NameRow' . $n] = (string)($h['hospital_name'] ?? '');
                    $address = trim((string)($h['address'] ?? ''));
                    $f['Address Lat and Long if HelipadRow' . $n] = $address;
                    $headings['Address Lat and Long if HelipadRow' . $n] = '6. Hospital address: ' . (string)($h['hospital_name'] ?? '');
                    $headings['Contact number or frequencyRow' . $n . '_2'] = '6. Hospital contact: ' . (string)($h['hospital_name'] ?? '');
                    $f['Travel Time Air GrndRow' . $n] = (string)($h['travel_time_air'] ?? '');
                    $f['Travel Time Air GrndRow' . $n . '_2'] = (string)($h['travel_time_ground'] ?? '');
                    $f['Contact number or frequencyRow' . $n . '_2'] = $contact;
                    $f[(!empty($h['helipad']) ? 'Yes' : 'No') . (5 + $n)] = OfficialPdfText::check();
                    $f[(!empty($h['burn_centre']) ? 'Yes' : 'No') . (10 + $n)] = OfficialPdfText::check();
                }
            }
            $pages[] = OfficialPdfText::apply('ICS206', $f, $headings, $cont);
        }
        return ['pages' => $pages, 'continuations' => $cont];
    }
}
