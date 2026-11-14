<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/pdf/OfficialPdfText.php';

/**
 * Maps the ICS 204 Assignment List onto the official ICS Canada 204 template.
 * The template has 9 resource rows (the first row's fields are Text1/Text2/Text5/Text6/Text7, then "...Row1".."Row8").
 * More resources continue on extra copies of the form; the narrative boxes are printed on the first copy only.
 * Block 5 (operational personnel) is free text in the app: lines such as "Branch Director: Name" go in their box,
 * anything else goes on the continuation sheet.
 */
final class Ics204OfficialPdfFieldMapper
{
    public const ROWS_PER_PAGE = 9;

    /** @return array{pages: array<int, array<string, mixed>>, continuations: array<int, array{heading: string, text: string}>} */
    public static function build(array $header, array $incident, array $op, array $resources): array
    {
        $cont = [];
        $headings = [];
        $p = OfficialPdfText::opParts($op, 'M j');
        $common = [
            '1 BRANCH' => (string)($header['branch_name'] ?? ''),
            '2 DIVISIONGROUP' => (string)($header['division_group'] ?? ''),
            '3 INCIDENT NAME' => ics_pdf_incident_name($incident),
            'Text3' => $p['from_date'], 'Text8' => $p['from_time'],
            'Text4' => $p['to_date'], 'Text9' => $p['to_time'],
        ];
        $common += self::personnel((string)($header['operations_personnel'] ?? ''), $cont);

        $first = $common;
        $first['7. WORK ASSIGNMENTS'] = (string)($header['work_assignments'] ?? '');
        $headings['7. WORK ASSIGNMENTS'] = '7. Work Assignments';
        $first['8 SPECIAL INSTRUCTIONS'] = (string)($header['special_instructions'] ?? '');
        $headings['8 SPECIAL INSTRUCTIONS'] = '8. Special Instructions';
        $comms = trim((string)($header['medical_plan_summary'] ?? ''));
        if ($comms !== '') {
            // The app keeps block 9 as a free-text summary; the form's grid wants frequencies per function.
            $first['FreqDivGroup Tactical'] = 'See continuation sheet';
            $cont[] = ['heading' => '9. Division/Group Communications Summary', 'text' => $comms];
        }
        $first['PREPARED BY Resource Unit Leader'] = trim((string)($header['prepared_by_name'] ?? ''));
        $first['APPROVED BY Plans Section Chief'] = trim((string)($header['approved_by_name'] ?? ''));

        $rows = array_values(array_filter($resources, static fn ($r) => trim(implode('', array_map('strval', (array)$r))) !== ''));
        $chunks = array_chunk($rows, self::ROWS_PER_PAGE) ?: [[]];
        $pages = [];
        foreach ($chunks as $pi => $chunk) {
            $f = $pi === 0 ? $first : $common;
            $pageHeadings = $pi === 0 ? $headings : [];
            foreach ($chunk as $i => $r) {
                $names = $i === 0
                    ? ['Text1', 'Text2', 'Text5', 'Text6', 'Text7']
                    : ['Resource IdentifierRow' . $i, 'LeaderRow' . $i, 'No of PersonsRow' . $i, 'Contact Cell  radio freq etcRow' . $i,
                        'Reporting Location Special Equipment and Supplies RemarksRow' . $i];
                $f[$names[0]] = (string)($r['resource_identifier'] ?? '');
                $f[$names[1]] = (string)($r['leader_name'] ?? '');
                $f[$names[2]] = ($r['number_persons'] ?? '') === null ? '' : (string)($r['number_persons'] ?? '');
                $f[$names[3]] = (string)($r['contact_info'] ?? '');
                $remarks = trim((string)($r['reporting_location'] ?? ''));
                if (!empty($r['reporting_time'])) { $remarks = trim(ics_pdf_fmt($r['reporting_time'], 'H:i') . ' ' . $remarks); }
                $f[$names[4]] = $remarks;
                $pageHeadings[$names[4]] = '6. Resource ' . (string)($r['resource_identifier'] ?? '') . ' - reporting location / remarks';
                $pageHeadings[$names[3]] = '6. Resource ' . (string)($r['resource_identifier'] ?? '') . ' - contact';
            }
            $pages[] = OfficialPdfText::apply('ICS204', $f, $pageHeadings, $cont);
        }
        return ['pages' => $pages, 'continuations' => $cont];
    }

    /** Block 5 from "Label: Name" lines. */
    private static function personnel(string $text, array &$cont): array
    {
        $out = [];
        $left = [];
        $boxes = [
            ['/operations|\bosc\b/i', 'Operations Chief', 179],
            ['/branch/i', 'Branch Director', 180],
            ['/staging/i', 'DivisionGroup Supervisor 2', 187],
            ['/division|group|\bdivs\b/i', 'DivisionGroup Supervisor 1', 187],
        ];
        foreach (preg_split('/\R/', trim($text)) ?: [] as $line) {
            $line = trim($line);
            if ($line === '') { continue; }
            $placed = false;
            if (str_contains($line, ':')) {
                [$label, $name] = array_map('trim', explode(':', $line, 2));
                foreach ($boxes as [$re, $field, $w]) {
                    if ($name !== '' && !isset($out[$field]) && preg_match($re, $label)) {
                        $out[$field] = $name;
                        $placed = true;
                        break;
                    }
                }
            }
            if (!$placed) { $left[] = $line; }
        }
        if ($left) { $cont[] = ['heading' => '5. Operational Personnel', 'text' => trim($text)]; }
        return $out;
    }
}
