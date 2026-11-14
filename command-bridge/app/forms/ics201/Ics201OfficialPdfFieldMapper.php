<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/pdf/OfficialPdfText.php';

/**
 * Maps the ICS 201 Incident Briefing onto the official ICS Canada 201 template (4 pages).
 * Page 1: map/sketch (uploaded maps follow the form as extra pages) and situation summary; page 2: objectives and
 * 16 action rows (current actions first, then planned); page 3: current organization; page 4: 22 resource rows.
 * Text or rows that do not fit go on the continuation sheet.
 */
final class Ics201OfficialPdfFieldMapper
{
    /** @return array{fields: array<string, mixed>, continuations: array<int, array{heading: string, text: string}>} */
    public static function build(array $record, array $incident, array $currentActions, array $plannedActions, array $resources,
        string $preparedBy, string $signatureName, int $attachmentCount): array
    {
        $cont = [];
        $headings = [];
        $prepared = (string)(($record['datetime_prepared'] ?? '') ?: ($record['updated_at'] ?? ''));
        // Name the signer only when it is someone other than the preparer.
        $by = $preparedBy . ($signatureName !== '' && !str_starts_with($preparedBy, $signatureName) ? ' (signed: ' . $signatureName . ')' : '');
        $f = [
            '1 INCIDENT NAME' => ics_pdf_incident_name($incident),
            '2 DATE PREPARED' => ics_pdf_fmt($prepared, 'Y-m-d'),
            '3 TIME PREPARED' => ics_pdf_fmt($prepared, 'H:i'),
            // Multi-line box whose first line sits on the printed label: start on the second line.
            '6. PREPARED BY Name and Position' => $by !== '' ? "\n" . $by : '',
            '9. PREPARED BY Name and Position' => $by,
            '11. PREPARED BY Name and Position' => $by,
            '13. PREPARED BY Name and Position' => $by,
        ];
        if ($attachmentCount > 0) {
            $f['4. MAP SKETCH'] = 'See the attached map/sketch page' . ($attachmentCount === 1 ? '' : 's') . ' (' . $attachmentCount . ') following this form.';
        }

        $situation = trim((string)($record['situation_summary'] ?? ''));
        $safety = trim((string)($record['safety_briefing'] ?? ''));
        $summary = trim($situation . ($safety !== '' ? "\n\nSafety briefing: " . $safety : ''));
        $f['5. SITUATION SUMMARY AND SAFETY BRIEFING'] = $summary;
        $headings['5. SITUATION SUMMARY AND SAFETY BRIEFING'] = '5. Situation Summary and Safety Briefing';

        $current = trim((string)($record['current_objectives'] ?? ''));
        $planned = trim((string)($record['planned_objectives'] ?? ''));
        $objectives = trim(($current !== '' ? "Current:\n" . $current : '') . ($planned !== '' ? "\n\nPlanned:\n" . $planned : ''));
        $f['7. CURRENT AND PLANNED OBJECTIVES'] = $objectives;
        $headings['7. CURRENT AND PLANNED OBJECTIVES'] = '7. Current and Planned Objectives';

        $actions = [];
        foreach ($currentActions as $a) { $actions[] = [(string)($a['action_time'] ?? ''), trim((string)($a['action_text'] ?? ''))]; }
        foreach ($plannedActions as $a) { $actions[] = [(string)($a['action_time'] ?? ''), 'Planned: ' . trim((string)($a['action_text'] ?? ''))]; }
        $actions = array_values(array_filter($actions, static fn ($a) => trim($a[0] . $a[1]) !== '' && $a[1] !== 'Planned:'));
        $overflow = [];
        foreach ($actions as $i => [$time, $text]) {
            if ($i < 16) {
                $f['Time Row ' . ($i + 1)] = $time;
                $f['Action Row ' . ($i + 1)] = $text;
                $headings['Action Row ' . ($i + 1)] = '8. Action at ' . ($time !== '' ? $time : 'row ' . ($i + 1));
            } else {
                $overflow[] = trim($time . '  ' . $text);
            }
        }
        if ($overflow) { $cont[] = ['heading' => '8. Current and Planned Actions, Strategies and Tactics (rows that did not fit)', 'text' => implode("\n", $overflow)]; }

        $f['9. CURRENT ORGANIZATION'] = (string)($record['current_organization'] ?? '');
        $headings['9. CURRENT ORGANIZATION'] = '10. Current Organization';

        $resources = array_values(array_filter($resources, static fn ($r) => trim(implode('', array_map('strval', (array)$r))) !== ''));
        $more = [];
        foreach ($resources as $i => $r) {
            $row = [(string)($r['resources_ordered'] ?? ''), (string)($r['resource_identification'] ?? ''), (string)($r['eta'] ?? ''),
                (string)($r['on_scene'] ?? ''), (string)($r['location_assignment'] ?? '')];
            if ($i >= 22) { $more[] = implode(' | ', array_filter($row, static fn ($v) => trim($v) !== '')); continue; }
            $n = $i + 1;
            $f['Resources OrderedRow' . $n] = $row[0];
            $f['Resource IdentificationRow' . $n] = $row[1];
            $f['ETARow' . $n] = $row[2];
            $f['On SceneRow' . $n] = $row[3];
            $f['LocationAssignmentRow' . $n] = $row[4];
        }
        if ($more) { $cont[] = ['heading' => '11. Resource Summary (rows that did not fit)', 'text' => implode("\n", $more)]; }

        return ['fields' => OfficialPdfText::apply('ICS201', $f, $headings, $cont), 'continuations' => $cont];
    }
}
