<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/pdf/SimpleTextPdf.php';
require_once dirname(__DIR__, 2) . '/includes/ics209_resources.php';

/**
 * Maps a saved ICS 209-WF (form_209 row) onto the fillable ICS Canada 209-WF template (assets/pdf/official/ICS209-wf.pdf,
 * built by tools/build_wf_template.py from the flattened official sheet).
 *
 * Multi-line boxes are fitted using the sizes in ICS209-wf.boxes.json; text that does not fit is cut at a word boundary,
 * marked "... (cont.)", and printed in full on a continuation sheet. The template's resource table has 25 columns; the
 * application's resource grid has 21, so columns 22-25 are left blank for hand entry.
 */
final class Ics209WfOfficialPdfFieldMapper
{
    private const HORIZONS = ['12h' => '12 hours', '24h' => '24 hours', '48h' => '48 hours', '72h' => '72 hours', 'after72h' => 'Anticipated after 72 hours'];
    private const STATUS_KEYS = ['fatalities', 'injuries_illness', 'trapped_rescue', 'missing', 'evacuated', 'sheltering_place', 'temporary_shelters',
        'received_immunizations', 'require_immunizations', 'quarantine', 'total_affected'];
    private const DAMAGE_KEYS = ['single_residences', 'multiple_residences', 'mixed_commercial_residential', 'nonres_commercial', 'other_minor_structures'];
    private const THREATS = ['threat_no_likely_threat', 'threat_potential_future_threat', 'threat_mass_notifications_progress',
        'threat_mass_notifications_completed', 'threat_no_evacuations_imminent', 'threat_planning_evacuation', 'threat_planning_shelter_place',
        'threat_evacuations_progress', 'threat_shelter_place_progress', 'threat_repopulation_progress', 'threat_mass_immunization_progress',
        'threat_mass_immunization_complete', 'threat_quarantine_progress', 'threat_area_restriction'];

    /** @var array<string, array{0: float, 1: float, 2: float}>|null */
    private static ?array $boxes = null;

    /**
     * @param array<string,mixed> $r  form_209 row
     * @param array<string,string> $positionCodes role id => role code
     * @return array{fields: array<string, mixed>, continuations: array<int, array{heading: string, text: string}>, header: array<int,string>}
     */
    public static function build(array $r, array $incident, array $op, array $formRecord, array $positionCodes = [], ?array $resourceGrid = null): array
    {
        $g = static fn (string $k): string => trim((string)($r[$k] ?? ''));
        $cont = [];
        $f = [];
        $x = ['value' => 'Yes', 'type' => 'name'];

        $name = $g('incident_name') !== '' ? $g('incident_name') : (string)($incident['incident_name'] ?? $incident['name'] ?? '');
        $no = $g('incident_no') !== '' ? $g('incident_no') : (string)($incident['incident_number'] ?? '');
        $f['wf_incident_name'] = $name;
        $f['wf_incident_no'] = $no;
        $version = strtolower($g('report_version'));
        foreach (['initial', 'update', 'final'] as $v) { if ($version === $v) { $f['wf_version_' . $v] = $x; } }
        $f['wf_report_number'] = $g('report_number');
        $f['wf_incident_commanders'] = self::box($g('incident_commanders'), 'wf_incident_commanders', '4. Incident Commander(s) & Agency or Organization', $cont);
        $f['wf_incident_mgmt_org'] = self::box($g('incident_management_org'), 'wf_incident_mgmt_org', '5. Incident Management Organization', $cont);
        $f['wf_start_date'] = $g('incident_start_date');
        $f['wf_start_time'] = $g('incident_start_time');
        $f['wf_current_size'] = self::box($g('current_size_area'), 'wf_current_size', '7. Current Incident Size or Area Involved', $cont);
        $f['wf_pct_contained'] = $g('percent_contained');
        $status = strtoupper($g('fire_status'));
        if (in_array($status, ['OC', 'BH', 'UC', 'O'], true)) { $f['wf_status_' . $status] = $x; }
        $f['wf_complexity_level'] = self::box($g('complexity_level'), 'wf_complexity_level', '10. Incident Complexity Level', $cont);
        $from = $g('period_from') !== '' ? $g('period_from') : self::fmt($op['op_start'] ?? '');
        $to = $g('period_to') !== '' ? $g('period_to') : self::fmt($op['op_end'] ?? '');
        $f['wf_period_from'] = $from;
        $f['wf_period_to'] = $to;

        $preparedName = $g('prepared_by_name') !== '' ? $g('prepared_by_name') : trim((string)($formRecord['prepared_by_name'] ?? ''));
        $preparedPos = $positionCodes[$g('prepared_by_position_id')] ?? trim((string)($formRecord['prepared_by_position_title'] ?? ''));
        $f['wf_prepared_name_position'] = self::joinNamePos($preparedName, $preparedPos);
        $f['wf_prepared_datetime'] = $g('prepared_datetime');
        $f['wf_submitted_datetime'] = $g('submitted_datetime');
        $f['wf_approved_name_position'] = self::joinNamePos($g('approved_by_name'), $positionCodes[$g('approved_by_position_id')] ?? '');
        $f['wf_approved_datetime'] = $g('approved_datetime');
        $f['wf_sent_to'] = self::box($g('primary_location_sent_to'), 'wf_sent_to', '15. Primary Location, Organization, or Agency Sent To', $cont);

        $f['wf_province'] = $g('province_territory');
        $f['wf_county'] = $g('county_regional');
        $f['wf_city'] = $g('city');
        $f['wf_unit_other'] = self::box($g('unit_other'), 'wf_unit_other', '19. Unit or Other', $cont);
        $f['wf_jurisdiction'] = self::box($g('incident_jurisdiction'), 'wf_jurisdiction', '20. Incident Jurisdiction', $cont);
        $f['wf_ownership'] = self::box($g('location_ownership'), 'wf_ownership', '21. Incident Location Ownership', $cont);
        $f['wf_longitude'] = $g('longitude');
        $f['wf_latitude'] = $g('latitude');
        $f['wf_datum'] = $g('datum');
        $f['wf_legal_description'] = self::oneLine($g('legal_description'));
        $f['wf_short_location'] = self::box($g('short_location_desc'), 'wf_short_location', '25. Short Location or Area Description', $cont);
        $f['wf_utm'] = $g('utm_coordinates');
        $f['wf_geospatial'] = self::box($g('geospatial_data'), 'wf_geospatial', '27. Electronic Geospatial Data Included or Attached', $cont);
        $f['wf_significant_events'] = self::box($g('significant_events'), 'wf_significant_events', '28. Observed Fire Behaviour or Significant Events', $cont);
        $f['wf_hazards'] = self::box($g('primary_materials_hazards'), 'wf_hazards', '29. Primary FBP Fuel Type, Materials or Hazards Involved', $cont);
        foreach (self::DAMAGE_KEYS as $key) {
            foreach (['threatened', 'damaged', 'destroyed'] as $col) { $f["wf_damage_{$key}_{$col}"] = $g("damage_{$key}_{$col}"); }
        }

        foreach (['public', 'responder'] as $grp) {
            foreach (self::STATUS_KEYS as $key) {
                $f["wf_{$grp}_{$key}_period"] = $g("{$grp}_{$key}_period");
                $f["wf_{$grp}_{$key}_total"] = $g("{$grp}_{$key}_total");
            }
        }
        $f['wf_life_safety_remarks'] = self::box($g('life_safety_remarks'), 'wf_life_safety_remarks', '33. Life, Safety, and Health Status/Threat Remarks', $cont);
        foreach (self::THREATS as $i => $key) {
            if (!empty($r[$key]) && (string)$r[$key] !== '0') { $f['wf_threat_' . ($i + 1)] = 'X'; }
        }
        $f['wf_weather_concerns'] = self::box($g('weather_concerns'), 'wf_weather_concerns', '35. Weather Concerns', $cont);
        foreach (self::HORIZONS as $k => $label) {
            $f["wf_projected_activity_$k"] = self::box($g("projected_activity_$k"), "wf_projected_activity_$k", "36. Projected Incident Activity - $label", $cont);
            $f["wf_threat_risk_$k"] = self::box($g("threat_risk_$k"), "wf_threat_risk_$k", "38. Current Incident Threat Summary and Risk Information - $label", $cont);
            $f["wf_critical_needs_$k"] = self::box($g("critical_resource_needs_$k"), "wf_critical_needs_$k", "39. Critical Resource Needs - $label", $cont);
        }
        $f['wf_objectives'] = self::box($g('objectives'), 'wf_objectives', '37. Strategic Objectives', $cont);
        $f['wf_strategic_discussion'] = self::box($g('strategic_discussion'), 'wf_strategic_discussion', '40. Strategic Discussion', $cont);
        $f['wf_planned_actions'] = self::box($g('planned_actions'), 'wf_planned_actions', '41. Planned Actions for Next Operational Period', $cont);
        $f['wf_projected_final_size'] = $g('projected_final_size');
        $f['wf_completion_date'] = $g('anticipated_completion_date');
        $f['wf_demob_start_date'] = $g('demob_start_date');
        $f['wf_costs_to_date'] = $g('estimated_costs_to_date');
        $f['wf_final_cost'] = $g('projected_final_cost');
        $f['wf_remarks'] = self::box($g('remarks'), 'wf_remarks', '47. Remarks', $cont);

        if ($resourceGrid !== null) { self::resourceFields($resourceGrid, $f, $cont); }

        $f = array_filter($f, static fn ($v): bool => is_array($v) || (string)$v !== '');
        return ['fields' => $f, 'continuations' => $cont, 'header' => [
            'Incident: ' . $name . ($no !== '' ? '   No. ' . $no : ''),
            'Report #' . $g('report_number') . ($g('report_version') !== '' ? ' (' . $g('report_version') . ')' : '') . '   Period: ' . $from . ' to ' . $to,
        ]];
    }

    /** Blocks 48-53: 17 agency rows x 25 columns (the application fills columns 1-21); two boxes per cell (resources above, personnel below). */
    private static function resourceFields(array $grid, array &$f, array &$cont): void
    {
        $grid = s209res_normalize($grid);
        $t = s209res_totals($grid);
        $longLabels = [];
        for ($c = 1; $c <= S209RES_COLS; $c++) {
            $label = (string)$grid['columns'][$c];
            if ($label === '') { continue; }
            $f["wf_res_label_$c"] = mb_strlen($label) > 11 ? rtrim(mb_substr($label, 0, 10)) . '.' : $label;
            if (mb_strlen($label) > 11) { $longLabels[] = "Column $c: $label"; }
            if ($t['col_resources'][$c] > 0) { $f["wf_total_resources_$c"] = (string)$t['col_resources'][$c]; }
        }
        if ($longLabels) { $cont[] = ['heading' => '49. Resource column labels (full text)', 'text' => implode("\n", $longLabels)]; }
        for ($r = 1; $r <= S209RES_ROWS; $r++) {
            $row = $grid['rows'][$r];
            if ($row['agency'] === '' && $row['add'] === '' && !$row['cells']) { continue; }
            if ($row['agency'] !== '') { $f["wf_agency_$r"] = self::box($row['agency'], "wf_agency_$r", "48. Agency or organization (row $r)", $cont); }
            foreach ($row['cells'] as $c => $cell) {
                if ($cell['r'] !== '') { $f["wf_res_{$c}_" . (2 * $r - 1)] = $cell['r']; }
                if ($cell['p'] !== '') { $f["wf_res_{$c}_" . (2 * $r)] = $cell['p']; }
            }
            if ($row['add'] !== '') { $f["wf_add_personnel_$r"] = $row['add']; }
            if ($t['row_personnel'][$r] > 0) { $f["wf_total_personnel_$r"] = (string)$t['row_personnel'][$r]; }
        }
        if ($t['resources'] > 0) { $f['wf_total_resources'] = (string)$t['resources']; }
        if ($t['personnel'] > 0) { $f['wf_total_personnel_18'] = (string)$t['personnel']; }
        if (trim((string)$grid['coop']) !== '') {
            $f['wf_coop_orgs'] = self::box((string)$grid['coop'], 'wf_coop_orgs', '53. Additional cooperating and assisting organizations', $cont);
        }
    }

    /** Fits text into a multi-line box (size and font from ICS209-wf.boxes.json); the overflow goes to the continuation sheet. */
    private static function box(string $text, string $field, string $heading, array &$cont): string
    {
        $text = trim(str_replace(["\r\n", "\r"], "\n", $text));
        if ($text === '') { return ''; }
        if (self::$boxes === null) {
            $file = dirname(__DIR__, 2) . '/assets/pdf/official/ICS209-wf.boxes.json';
            $d = is_file($file) ? json_decode((string)file_get_contents($file), true) : null;
            self::$boxes = is_array($d) ? $d : [];
        }
        [$w, $h, $fs] = self::$boxes[$field] ?? [300.0, 40.0, 8.0];
        $maxLines = max(1, (int)floor(($h - 1) / ($fs * 1.2)));
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

    private static function joinNamePos(string $name, string $pos): string
    {
        return trim($name . ($name !== '' && $pos !== '' ? ' / ' : '') . $pos);
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
