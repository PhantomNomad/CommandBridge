<?php
declare(strict_types=1);

/**
 * Maps the currently assigned incident positions onto the org-chart boxes of the official ICS Canada 207 template.
 *
 * The template's chart boxes are named Text22, Text23 ... with no meaning, so the position -> box table below was
 * worked out by filling every field with its own name and checking where it landed on the printed chart.
 */
final class Ics207OfficialPdfFieldMapper
{
    /** role code (as stored in roles.role_code) => chart box on page 1. */
    private const BOX_BY_ROLE = [
        'IC' => 'Text52',
        'LOFR' => 'Text51', 'LO' => 'Text51',
        'SOFR' => 'Text39', 'SO' => 'Text39',
        'PIO' => 'Text38', 'IO' => 'Text38',
        'OSC' => 'Text31', 'OPS' => 'Text31',
        'PSC' => 'Text25', 'PLAN' => 'Text25',
        'LSC' => 'Text23', 'LOG' => 'Text23',
        'FSC' => 'Text22', 'FIN' => 'Text22',
        'SAM' => 'Text47',
        'RESL' => 'Text43', 'RUL' => 'Text43',
        'SITL' => 'Text44',
        'DOCL' => 'Text45', 'DUL' => 'Text45',
        'DMOB' => 'Text46', 'DEMOB' => 'Text46',
        'COML' => 'Text10.0.0',
        'MEDL' => 'Text10.0.1', 'MUL' => 'Text10.0.1',
        'FDUL' => 'Text10.1', 'FOOD' => 'Text10.1',
        'SPUL' => 'Text10.2', 'SUL' => 'Text10.2',
        'FAC' => 'Text10.3', 'FUL' => 'Text10.3',
        'GSUL' => 'Text10.4', 'GUL' => 'Text10.4',
        'TIME' => 'Text21', 'TUL' => 'Text21',
        'COST' => 'Text40',
        'PROC' => 'Text41', 'PUL' => 'Text41',
        'COMP' => 'Text42', 'CCUL' => 'Text42',
    ];

    /** Roles that share the three "Branch/Division/Group" boxes under Operations. */
    private const GROUP_ROLES = ['DIVS', 'STAM', 'BD', 'BDIR', 'DGS'];
    private const GROUP_BOXES = ['Text48', 'Text49', 'Text50'];

    /**
     * @param array<int, array<string, mixed>> $positions rows with real_name and code
     * @return array<string, string>
     */
    public static function map(array $positions, array $incident, array $op, array $formRecord): array
    {
        $byBox = [];
        $groupNames = [];
        foreach ($positions as $p) {
            $code = strtoupper(trim((string)($p['code'] ?? '')));
            $name = trim((string)($p['real_name'] ?? ''));
            if ($name === '') {
                continue;
            }
            if (isset(self::BOX_BY_ROLE[$code])) {
                $byBox[self::BOX_BY_ROLE[$code]][] = $name;
            } elseif (in_array($code, self::GROUP_ROLES, true)) {
                $groupNames[] = $name;
            }
        }

        $fields = [];
        foreach ($byBox as $box => $names) {
            $fields[$box] = implode('; ', array_unique($names));
        }
        foreach (array_slice($groupNames, 0, count(self::GROUP_BOXES)) as $i => $name) {
            $fields[self::GROUP_BOXES[$i]] = $name;
        }

        $start = (string)($op['op_start'] ?? $op['start_time'] ?? '');
        $end = (string)($op['op_end'] ?? $op['end_time'] ?? '');
        $preparedBy = trim((string)($formRecord['prepared_by_name'] ?? ''));
        $position = trim((string)($formRecord['prepared_by_position_title'] ?? ''));

        $fields += [
            '1 Incident Name' => ics_pdf_incident_name($incident),
            // Page 1 operational period, then the repeat on page 2.
            'Text1' => ics_pdf_fmt($start, 'Y-m-d'), 'Text7' => ics_pdf_fmt($start, 'H:i'),
            'Text9' => ics_pdf_fmt($end, 'Y-m-d'), 'Text17' => ics_pdf_fmt($end, 'H:i'),
            'Text2' => ics_pdf_fmt($start, 'Y-m-d'), 'Text4' => ics_pdf_fmt($start, 'H:i'),
            'Text3' => ics_pdf_fmt($end, 'Y-m-d'), 'Text5' => ics_pdf_fmt($end, 'H:i'),
            '4 Prepared by Name' => trim($preparedBy . ($position !== '' ? ' - ' . $position : '')),
            'DateTime' => ics_pdf_fmt($formRecord['prepared_at'] ?? $formRecord['updated_at'] ?? ''),
        ];

        return array_filter($fields, static fn (string $v): bool => $v !== '');
    }
}
