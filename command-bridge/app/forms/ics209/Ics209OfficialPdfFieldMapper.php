<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/pdf/SimpleTextPdf.php';
require_once dirname(__DIR__, 2) . '/includes/ics209_resources.php';

/**
 * Maps a saved ICS 209 (form_209 row) onto the official ICS Canada 209 template.
 *
 * Narrative boxes on the template hold only one to nine lines. Text that does not fit is cut at a word boundary,
 * marked "... (cont.)", and printed in full on a continuation sheet appended to the PDF.
 *
 * The template's fields 4, 5, 28-30, 33, 35-39, 41, 42 and 47 were switched to multi-line with a fixed font size
 * so that this fit calculation matches what the PDF engine draws.
 */
final class Ics209OfficialPdfFieldMapper
{
    private const HORIZONS = ['12h' => '12 hours', '24h' => '24 hours', '48h' => '48 hours', '72h' => '72 hours', 'after72h' => 'Anticipated after 72 hours'];

    /** field-letter => [form_209 key, template label]  (blocks 31 and 32) */
    private const STATUS_ROWS = [
        'fatalities' => 'D. Fatalities',
        'injuries_illness' => 'E. With Injuries/Illness',
        'trapped_rescue' => 'F. Trapped/In Need of Rescue',
        'missing' => 'G. Missing (note if estimated)',
        'evacuated' => 'H. Evacuated (note if estimated)',
        'sheltering_place' => 'I. Sheltering in Place (note if estimated)',
        'temporary_shelters' => 'J In Temporary Shelters note if estimated',
        'received_immunizations' => 'K Have Received Mass Immunizations',
        'require_immunizations' => 'L Require Immunizations note if estimated',
        'quarantine' => 'MIn Quarantine',
        'total_affected' => 'N Total  Civilians Public Affected',
    ];

    private const DAMAGE_ROWS = [
        'single_residences' => 'E Single Residences',
        'nonres_commercial' => 'F Nonresidential Commercial Property',
        'other_minor_structures' => 'Other Minor Structures',
        'other' => 'Other',
    ];

    /** threat checkbox keys in template order (Check Box1 .. Check Box14 = A .. N) */
    private const THREATS = ['threat_no_likely_threat', 'threat_potential_future_threat', 'threat_mass_notifications_progress',
        'threat_mass_notifications_completed', 'threat_no_evacuations_imminent', 'threat_planning_evacuation', 'threat_planning_shelter_place',
        'threat_evacuations_progress', 'threat_shelter_place_progress', 'threat_repopulation_progress', 'threat_mass_immunization_progress',
        'threat_mass_immunization_complete', 'threat_quarantine_progress', 'threat_area_restriction'];

    /**
     * @param array<string,mixed> $r   form_209 row
     * @param array<string,string> $positionCodes role id => role code (for the ICS position boxes)
     * @return array{fields: array<string, mixed>, continuations: array<int, array{heading: string, text: string}>}
     */
    public static function build(array $r, array $incident, array $op, array $formRecord, array $positionCodes = [], ?array $resourceGrid = null, bool $wildfire = false): array
    {
        $g = static fn (string $k): string => trim((string)($r[$k] ?? ''));
        $cont = [];
        $f = [];

        // ---- Header / routing ------------------------------------------------------------------------------------
        $name = $g('incident_name') !== '' ? $g('incident_name') : (string)($incident['incident_name'] ?? $incident['name'] ?? '');
        $no = $g('incident_no') !== '' ? $g('incident_no') : (string)($incident['incident_number'] ?? '');
        $f['1 INCIDENT NAME'] = $name;
        $f['2 INCIDENT NO'] = $no;
        $f['*1. INCIDENT NAME'] = $name;
        $f['2. INCIDENT NO'] = $no;

        $version = strtolower($g('report_version'));
        foreach (['initial' => 'Check Box Initial', 'update' => 'Check Box Update', 'final' => 'Check Box Final'] as $v => $box) {
            if ($version === $v) { $f[$box] = ['value' => 'Yes', 'type' => 'name']; }
        }
        $f['*3. REPORT VERSION Rpt# (if used)'] = $g('report_number');

        $f['4 INCIDENT COMMANDERS  AGENCY OR ORGANIZATION'] = self::box($g('incident_commanders'), 135, 42, 8, 1.2, '4. Incident Commander(s) & Agency or Organization', $cont);
        $f['5 INCIDENT MANAGEMENT ORGANIZATION'] = self::box($g('incident_management_org'), 137, 42, 8, 1.2, '5. Incident Management Organization', $cont);
        $f['*6.INCIDENT START DATE/TIME Date'] = $g('incident_start_date');
        $f['*6.INCIDENT START DATE/TIME Time'] = $g('incident_start_time');
        $f['7 CURRENT INCIDENT SIZE OR AREA INVOLVED'] = $g('current_size_area');
        $f['8. PERCENT CONTAINED'] = $g('percent_contained');
        $f['9 INCIDENT DEFINITION'] = $g('incident_definition');
        $f['10 INCIDENT COMPLEXITY LEVEL'] = $g('complexity_level');

        if ($wildfire) {
            // ICS 209-WF is printed on the standard ICS 209 template (same block numbers). Block 9 of the WF form is the fire status.
            $statusNames = ['OC' => 'OC - Out of Control', 'BH' => 'BH - Being Held', 'UC' => 'UC - Under Control', 'O' => 'O - Out'];
            $f['9 INCIDENT DEFINITION'] = $statusNames[$g('fire_status')] ?? $g('fire_status');
            // The two extra structure rows of block 30 on the WF form have no box on the template: print them on the continuation sheet.
            $extraRows = [];
            foreach (['multiple_residences' => 'F. Multiple Residences', 'mixed_commercial_residential' => 'G. Mixed Commercial / Residential'] as $key => $label) {
                $t = $g("damage_{$key}_threatened"); $d = $g("damage_{$key}_damaged"); $x = $g("damage_{$key}_destroyed");
                if ($t !== '' || $d !== '' || $x !== '') { $extraRows[] = "$label:  # Threatened (72 hrs): " . ($t ?: '-') . "   # Damaged: " . ($d ?: '-') . "   # Destroyed: " . ($x ?: '-'); }
            }
            if ($extraRows) { $cont[] = ['heading' => '30. Damage assessment - additional structure rows (ICS 209-WF)', 'text' => implode("\n", $extraRows)]; }
        }

        $from = $g('period_from') !== '' ? $g('period_from') : self::fmt($op['op_start'] ?? '');
        $to = $g('period_to') !== '' ? $g('period_to') : self::fmt($op['op_end'] ?? '');
        $f['*11. FOR TIME PERIOD From Date/Time'] = $from;
        $f['*11. TO TIME PERIOD To Date/Time'] = $to;

        $preparedName = $g('prepared_by_name') !== '' ? $g('prepared_by_name') : trim((string)($formRecord['prepared_by_name'] ?? ''));
        $preparedPos = $positionCodes[$g('prepared_by_position_id')] ?? trim((string)($formRecord['prepared_by_position_title'] ?? ''));
        $f['*12 PREPARED BY Print Name'] = $preparedName;
        $f['*12 PREPARED BY ICS Position'] = $preparedPos;
        $f['*12 PREPARED BY Date/Time Prepared'] = $g('prepared_datetime');
        $f['*13. DATE/TIME SUBMITTED'] = $g('submitted_datetime');
        $f['*14 APPROVED BY Print Name'] = $g('approved_by_name');
        $f['*14 APPROVED BY ICS Position'] = $positionCodes[$g('approved_by_position_id')] ?? '';
        $f['*14 APPROVED BY Date/Time Prepared'] = $g('approved_datetime');
        $f['*15. PRIMARY LOCATION, ORGANIZATION, OR AGENCY SENT TO'] = $g('primary_location_sent_to');

        // ---- Location 16-27 --------------------------------------------------------------------------------------
        $f['*16. PROVINCE/TERRITORY'] = $g('province_territory');
        $f['*17. COUNTY, REGIONAL/RURAL MUNICIPALITY, REGIONAL/MUNICIPAL DISTRICT'] = $g('county_regional');
        $f['*18. CITY'] = $g('city');
        $f['19. UNIT OR OTHER'] = $g('unit_other');
        $f['*20. INCIDENT JURISDICTION'] = $g('incident_jurisdiction');
        $f['21. INCIDENT LOCATION OWNERSHIP'] = $g('location_ownership');
        $f['22. LONGITUDE'] = $g('longitude');
        $f['22. LATITUDE'] = $g('latitude');
        $f['23. DATUM'] = $g('datum');
        $f['24. LEGAL DESCRIPTION'] = self::oneLine($g('legal_description'));
        $f['*25. SHORT LOCATION OR AREA DESCRIPTION'] = $g('short_location_desc');
        $f['*26. UTM COORDINATES'] = $g('utm_coordinates');
        $f['27. NOTE ANY ELECTRONIC GEOSPATIAL DATA INCLUDED OR ATTACHED'] = self::oneLine($g('geospatial_data'));

        // ---- Page 1 narrative + damage ---------------------------------------------------------------------------
        $f['*28. SIGNIFICANT EVENTS FOR THE TIME PERIOD REPORTED'] = self::box($g('significant_events'), 552, 17, 6.5, 1.12, '28. Significant Events for the Time Period Reported', $cont);
        $f['29. PRIMARY MATERIALS OR HAZARDS INVOLVED'] = self::box($g('primary_materials_hazards'), 555, 17, 6.5, 1.12, '29. Primary Materials or Hazards Involved', $cont);
        $f['30 DAMAGE ASSESSMENT INFORMATION'] = self::box($g('damage_assessment_info'), 175, 46, 9, 1.2, '30. Damage Assessment Information', $cont);
        foreach (self::DAMAGE_ROWS as $key => $label) {
            $f["B  Threatened 72 hrs$label"] = $g("damage_{$key}_threatened");
            $f["C  Damaged$label"] = $g("damage_{$key}_damaged");
            $f["D  Destroyed$label"] = $g("damage_{$key}_destroyed");
        }

        // ---- Page 2 ----------------------------------------------------------------------------------------------
        foreach (['public' => '31', 'responder' => '32'] as $grp => $block) {
            foreach (self::STATUS_ROWS as $key => $label) {
                $f["*$block. $label - A # This Reporting Period"] = $g("{$grp}_{$key}_period");
                $f["*$block. $label - B Total # to Date"] = $g("{$grp}_{$key}_total");
            }
        }
        $f['33 LIFE SAFETY AND HEALTH STATUSTHREAT REMARKS'] = self::box($g('life_safety_remarks'), 264, 94, 9, 1.2, '33. Life, Safety, and Health Status/Threat Remarks', $cont);
        foreach (self::THREATS as $i => $key) {
            if (!empty($r[$key]) && (string)$r[$key] !== '0') { $f['Check Box' . ($i + 1)] = ['value' => 'Yes', 'type' => 'name']; }
        }
        $f['35. WEATHER CONCERNS'] = self::box($g('weather_concerns'), 264, 119, 9, 1.2, '35. Weather Concerns', $cont);
        foreach (self::HORIZONS as $k => $label) {
            $wide = $k === 'after72h';
            $f["36. PROJECTED INCIDENT ACTIVITY $label"] = self::box($g("projected_activity_$k"), $wide ? 450 : 517, 16, 6.5, 1.12, "36. Projected Incident Activity - $label", $cont);
        }
        $f['37. OBJECTIVES'] = self::box($g('objectives'), 544, 54, 9, 1.2, '37. Objectives', $cont);

        // ---- Page 3 ----------------------------------------------------------------------------------------------
        foreach (self::HORIZONS as $k => $label) {
            $wide = $k === 'after72h';
            $f["38. CURRENT INCIDENT THREAT SUMMARY $label"] = self::box($g("threat_risk_$k"), $wide ? 441 : 509, 16, 6.5, 1.12, "38. Current Incident Threat Summary and Risk Information - $label", $cont);
            $f["39. CRITICAL RESOURCE NEEDS $label"] = self::box($g("critical_resource_needs_$k"), $wide ? 441 : 509, 16, 6.5, 1.12, "39. Critical Resource Needs - $label", $cont);
        }
        // The template names its 40 and 41 boxes by the wrong block numbers: the "41 PLANNED ACTIONS" field is the
        // Strategic Discussion box (40) and the "42 ... SIZEAREA" field is the Planned Actions box (41).
        $f['41 PLANNED ACTIONS FOR NEXT OPERATIONAL PERIOD'] = self::box($g('strategic_discussion'), 542, 83, 9, 1.2, '40. Strategic Discussion', $cont);
        $f['42 PROJECTED FINAL INCIDENT SIZEAREA use unit label  eg sq km'] = self::box($g('planned_actions'), 542, 48, 9, 1.2, '41. Planned Actions for Next Operational Period', $cont);
        $f['42. PROJECTED FINAL INCIENT SIZE/AREA'] = $g('projected_final_size');
        $f['43. ANTICIPATED INCIDENT MANAGEMENT COMPLETION DATE'] = $g('anticipated_completion_date');
        $f['44. PROJECTED SIGNIFICANT RESOURCE DEMOBILIZATION START DATE'] = $g('demob_start_date');
        $f['45. ESTIMATED INCIDENT COSTS TO DATE'] = $g('estimated_costs_to_date');
        $f['46. PROJECTED FINAL INCIDENT COST ESTIMATE'] = $g('projected_final_cost');
        $f['47. REMARKS'] = self::box($g('remarks'), 543, 45, 9, 1.2, '47. Remarks', $cont);

        // ---- Page 4: resource commitment summary (blocks 48-53) -------------------------------------------------
        if ($resourceGrid !== null) { self::resourceFields($resourceGrid, $f, $cont); }

        $f = array_filter($f, static fn ($v): bool => is_array($v) || (string)$v !== '');
        return ['fields' => $f, 'continuations' => $cont, 'header' => [
            'Incident: ' . $name . ($no !== '' ? '   No. ' . $no : ''),
            'Report #' . $g('report_number') . ($g('report_version') !== '' ? ' (' . $g('report_version') . ')' : '') . '   Period: ' . $from . ' to ' . $to,
        ]];
    }

    /**
     * Blocks 48-53. Template layout: 17 agency rows x 21 resource columns; each cell is two boxes ("Resources c-r": odd r =
     * number of resources, even r = personnel, rows 2n-1 / 2n belong to agency row n).
     *
     * @param array<string,mixed> $f
     * @param array<int, array{heading: string, text: string}> $cont
     */
    private static function resourceFields(array $grid, array &$f, array &$cont): void
    {
        $grid = s209res_normalize($grid);
        $t = s209res_totals($grid);
        $longLabels = [];
        for ($c = 1; $c <= S209RES_COLS; $c++) {
            $label = (string)$grid['columns'][$c];
            if ($label === '') { continue; }
            $name = $c === 6 ? '49 RESOURCES 6ith resource on bottom ½ of boxRow1_6' : "49 RESOURCES $c";
            $f[$name] = mb_strlen($label) > 18 ? rtrim(mb_substr($label, 0, 17)) . '.' : $label;
            if (mb_strlen($label) > 18) { $longLabels[] = "Column $c: $label"; }
            if ($t['col_resources'][$c] > 0) { $f["Total Resources $c"] = (string)$t['col_resources'][$c]; }
        }
        if ($longLabels) { $cont[] = ['heading' => '49. Resource column labels (full text)', 'text' => implode("\n", $longLabels)]; }
        for ($r = 1; $r <= S209RES_ROWS; $r++) {
            $row = $grid['rows'][$r];
            if ($row['agency'] === '' && $row['add'] === '' && !$row['cells']) { continue; }
            if ($row['agency'] !== '') { $f["48 AGENCY OR ORGANIZATIONRow$r"] = self::box($row['agency'], 101, 23, 7, 1.15, "48. Agency or organization (row $r)", $cont); }
            foreach ($row['cells'] as $c => $cell) {
                if ($cell['r'] !== '') { $f['Resources ' . $c . '-' . (2 * $r - 1)] = $cell['r']; }
                if ($cell['p'] !== '') { $f['Resources ' . $c . '-' . (2 * $r)] = $cell['p']; }
            }
            if ($row['add'] !== '') { $f["50. ADDITIONAL PERSONNEL Row $r"] = $row['add']; }
            if ($t['row_personnel'][$r] > 0) { $f["51 TOTAL PERSONNEL Row $r"] = (string)$t['row_personnel'][$r]; }
        }
        if ($t['resources'] > 0) { $f['52. TOTAL RESOURCES'] = (string)$t['resources']; }
        if ($t['personnel'] > 0) { $f['51 TOTAL PERSONNEL Row 18'] = (string)$t['personnel']; }
        if (trim((string)$grid['coop']) !== '') {
            // The template names this field "52" although it is block 53.
            $f['52 ADDITIONAL COOPERATING AND ASSISTING ORGANIZATIONS NOT LISTED ABOVE'] = self::box((string)$grid['coop'], 544, 55, 8, 1.15, '53. Additional cooperating and assisting organizations', $cont);
        }
    }

    /**
     * Fit text into a form box. Anything that does not fit is cut at a word boundary, marked "... (cont.)", and queued
     * (in full) for the continuation sheet.
     *
     * @param array<int, array{heading: string, text: string}> $cont
     */
    private static function box(string $text, float $w, float $h, float $fs, float $leading, string $heading, array &$cont): string
    {
        $text = trim(str_replace(["\r\n", "\r"], "\n", $text));
        if ($text === '') { return ''; }
        $maxLines = max(1, (int)floor(($h - 1) / ($fs * $leading)));
        $inner = $w - 6;
        $lines = SimpleTextPdf::wrap($text, $inner, $fs);
        if (count($lines) <= $maxLines) { return $text; }

        $cont[] = ['heading' => $heading, 'text' => $text];
        $keep = array_slice($lines, 0, $maxLines);
        $marker = ' ... (cont.)';
        $last = (string)array_pop($keep);
        while ($last !== '' && SimpleTextPdf::textWidth($last . $marker, $fs) > $inner) {
            $pos = mb_strrpos($last, ' ');
            $last = $pos === false ? mb_substr($last, 0, max(0, mb_strlen($last) - 1)) : mb_substr($last, 0, $pos);
        }
        $keep[] = rtrim($last) . $marker;
        return implode("\n", $keep);
    }

    private static function oneLine(string $s): string
    {
        return trim((string)preg_replace('/\s*\n+\s*/', '; ', $s));
    }

    private static function fmt($v): string
    {
        $v = trim((string)$v);
        $ts = $v !== '' ? strtotime($v) : false;
        return $ts ? date('Y-m-d H:i', $ts) : $v;
    }
}
