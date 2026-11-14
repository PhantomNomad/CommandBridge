<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/pdf/OfficialPdfText.php';

/**
 * Maps the incident's current position assignments onto the official ICS Canada 203 template.
 * $holders is role code => list of names (everyone currently holding that position). Several people in one position
 * share its box; names that do not fit, and positions the template has no box for, go on the continuation sheet.
 */
final class Ics203OfficialPdfFieldMapper
{
    private const S5 = '5. INCIDENT COMMAND AND STAFF ';
    private const S7 = '7. PLANNING SECTION ';
    private const S8 = '8. LOGISTICS SECTION ';
    private const S9 = '9. OPERATIONS SECTION ';
    private const S10 = '10 FINANCIALADMINISTRATION SECTION ';
    private const DIV = ['a' => 'a. BRANCH - DIVISIONS/GROUPS ', 'b' => 'b. BRANCH - DIVISIONS/GROUPS ', 'c' => 'c. BRANCH - DIVISIONS/GROUPS '];

    /**
     * @param array<string, array<int, string>> $holders role code => names
     * @param array<string, string> $titles role code => position name (for positions listed on the continuation sheet)
     * @return array{fields: array<string, mixed>, continuations: array<int, array{heading: string, text: string}>}
     */
    public static function build(array $holders, array $incident, array $op, string $preparedBy, string $preparedAt, array $titles = []): array
    {
        $f = [];
        $cont = [];
        $headings = [];
        $used = [];
        $take = static function (array $codes) use ($holders, &$used): array {
            $names = [];
            foreach ($codes as $code) {
                if (!empty($holders[$code])) { $names = array_merge($names, $holders[$code]); $used[$code] = true; }
            }
            return array_values(array_unique(array_filter(array_map('trim', $names))));
        };
        $box = static function (string $field, array $names, float $width, string $title) use (&$f, &$headings): void {
            // $width is kept for readability of the calls; apply() uses the template's real box size.
            if (!$names) { return; }
            $f[$field] = implode(', ', $names);
            $headings[$field] = $title;
        };

        $p = OfficialPdfText::opParts($op, 'M j');
        $f['1 INCIDENT NAME'] = ics_pdf_incident_name($incident);
        $f['2 DATE'] = ics_pdf_fmt($preparedAt, 'Y-m-d');
        $f['3 TIME'] = ics_pdf_fmt($preparedAt, 'H:i');
        $f['Text17.0.0'] = $p['from_date'];
        $f['Text17.2'] = $p['from_time'];
        $f['Text17.0.1'] = $p['to_date'];
        $f['Text17.1'] = $p['to_time'];

        // 5. Incident Commander / Unified Commanders: three lines, one person each.
        $command = $take(['IC', 'UC']);
        foreach (['A', 'B', 'C'] as $i => $letter) {
            if (isset($command[$i])) { $f[self::S5 . 'Inident Cmndr/Unified Cmndrs ' . $letter] = $command[$i]; }
        }
        if (count($command) > 3) { $cont[] = ['heading' => 'Incident Commander / Unified Commanders', 'text' => implode(', ', $command)]; }
        $box(self::S5 . 'Deputy', $take(['DIC']), 168, 'Deputy Incident Commander');
        $box(self::S5 . 'Safety Officer', $take(['SOFR', 'SO']), 168, 'Safety Officer');
        $box(self::S5 . 'Information Officer', $take(['PIO', 'IO']), 168, 'Information Officer');
        $box(self::S5 . 'Liaison Officer', $take(['LOFR', 'LO']), 168, 'Liaison Officer');

        // 6. Agency / organization representatives.
        $reps = $take(['AREP']);
        foreach (array_slice($reps, 0, 7) as $i => $name) { $f['Representative Row' . ($i + 1)] = $name; }
        if (count($reps) > 7) { $cont[] = ['heading' => 'Agency / Organization Representatives', 'text' => implode(', ', $reps)]; }

        // 7. Planning.
        $box(self::S7 . 'Chief', $take(['PSC']), 131, 'Planning Section Chief');
        $box(self::S7 . 'Deputy', $take(['DPSC']), 131, 'Deputy Planning Section Chief');
        $box(self::S7 . 'Resources Unit', $take(['RESL', 'RUL']), 131, 'Resources Unit Leader');
        $box(self::S7 . 'Situation Unit', $take(['SITL']), 131, 'Situation Unit Leader');
        $box(self::S7 . 'Documentation Unit', $take(['DOCL', 'DUL']), 131, 'Documentation Unit Leader');
        $box(self::S7 . 'Demobilization Unit', $take(['DMOB', 'DEMOB']), 131, 'Demobilization Unit Leader');
        $box(self::S7 . 'Technical Specialists', $take(['EVAL', 'THSP', 'TS']), 131, 'Technical Specialists');
        $extraPlanning = [['Check-In Recorder', $take(['CIR', 'CHECK_IN_RECORDER'])]];
        $row = 1;
        foreach ($extraPlanning as [$title, $names]) {
            if ($names && $row <= 3) {
                $f[self::S7 . 'Position' . $row] = $title;
                $box(self::S7 . 'Name' . $row, $names, 131, $title);
                $row++;
            }
        }

        // 8. Logistics.
        $box(self::S8 . 'Chief', $take(['LSC']), 131, 'Logistics Section Chief');
        $box(self::S8 . 'Deputy', $take(['DLSC']), 131, 'Deputy Logistics Section Chief');
        $box('a. SUPPORT BRANCH Director', $take(['SUBD']), 131, 'Support Branch Director');
        $box('a. SUPPORT BRANCH Supply Unit', $take(['SPUL', 'SUL']), 131, 'Supply Unit Leader');
        $box('a. SUPPORT BRANCH Facilities Unit', $take(['FAC', 'FUL', 'FACL']), 131, 'Facilities Unit Leader');
        $box('a. SUPPORT BRANCH Ground Support Unit', $take(['GSUL', 'GUL']), 131, 'Ground Support Unit Leader');
        $box('b. SERVICE BRANCH Director', $take(['SVBD']), 131, 'Service Branch Director');
        $box('b. SERVICE BRANCH Communications Unit', $take(['COML']), 131, 'Communications Unit Leader');
        $box('b. SERVICE BRANCH Medical Unit', $take(['MEDL', 'MUL']), 131, 'Medical Unit Leader');
        $box('b. SERVICE BRANCH Food Unit', $take(['FDUL', 'FOOD']), 131, 'Food Unit Leader');

        // 9. Operations: Branch Directors go in branches a-c; Division/Group Supervisors and the Staging Area Manager in
        // branch a's Division/Group rows (the app does not record which branch a division belongs to).
        $box(self::S9 . 'Chief', $take(['OSC', 'OPS']), 132, 'Operations Section Chief');
        $box(self::S9 . 'Deputy', $take(['DOSC']), 132, 'Deputy Operations Section Chief');
        $branchDirs = $take(['BDIR', 'BD']);
        foreach (['a', 'b', 'c'] as $i => $letter) {
            if (isset($branchDirs[$i])) { $f[self::DIV[$letter] . 'Branch Director'] = $branchDirs[$i]; }
        }
        if (count($branchDirs) > 3) { $cont[] = ['heading' => 'Branch Directors', 'text' => implode(', ', $branchDirs)]; }
        $divRows = [];
        foreach ($take(['STAM', 'SAM', 'STAGING_AREA_MANAGER']) as $n) { $divRows[] = ['Staging', $n]; }
        foreach ($take(['DIVS', 'DGS', 'DGSUP']) as $n) { $divRows[] = ['Div./Group', $n]; }
        $slots = [];
        foreach (['a', 'b', 'c'] as $letter) {
            for ($r = 1; $r <= 5; $r++) {
                $slots[] = $letter === 'a'
                    ? [self::DIV['a'] . 'Division/Group Division Row' . $r, self::DIV['a'] . 'Division/Group Group Row' . $r]
                    : [self::DIV[$letter] . 'Division/Group Division Row' . $r, self::DIV[$letter] . 'Division/Group Row' . $r];
            }
        }
        foreach ($divRows as $i => [$label, $name]) {
            if (!isset($slots[$i])) { break; }
            $f[$slots[$i][0]] = $label;
            $f[$slots[$i][1]] = $name;
        }
        if (count($divRows) > count($slots)) {
            $cont[] = ['heading' => 'Divisions / Groups / Staging', 'text' => implode("\n", array_map(static fn ($r) => $r[0] . ': ' . $r[1], $divRows))];
        }
        $box('d. AIR OPERATIONS BRANCH Air Operations Br. Dir', $take(['AOBD']), 132, 'Air Operations Branch Director');

        // 10. Finance / Administration.
        $box(self::S10 . 'Chief', $take(['FSC', 'FIN', 'FAS']), 132, 'Finance / Administration Section Chief');
        $box(self::S10 . 'Deputy', $take(['DFSC']), 132, 'Deputy Finance / Administration Section Chief');
        $box(self::S10 . 'Time Unit', $take(['TIME', 'TUL']), 132, 'Time Unit Leader');
        $box(self::S10 . 'Procurement Unit', $take(['PROC', 'PUL']), 132, 'Procurement Unit Leader');
        $box(self::S10 . 'Compensation/Claims', $take(['COMP', 'CCUL', 'CCL']), 132, 'Compensation / Claims Unit Leader');
        $box(self::S10 . 'Cost Unit', $take(['COST']), 132, 'Cost Unit Leader');

        $f['PREPARED BY (Resources Only)'] = $preparedBy;

        // Any other position held on the incident (custom positions, staff roles) is listed on the continuation sheet.
        $other = [];
        foreach ($holders as $code => $names) {
            if (!isset($used[$code]) && $names && !in_array($code, ['SYSADMIN', 'GENERAL_STAFF', 'RESOURCE_UNIT', 'FINANCE_ADMIN'], true)) {
                $other[] = ($titles[$code] ?? $code) . ': ' . implode(', ', $names);
            }
        }
        if ($other) { $cont[] = ['heading' => 'Other positions assigned', 'text' => implode("\n", $other)]; }

        return ['fields' => OfficialPdfText::apply('ICS203', $f, $headings, $cont), 'continuations' => $cont];
    }
}
