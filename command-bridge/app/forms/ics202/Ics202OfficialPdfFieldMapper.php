<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/pdf/OfficialPdfText.php';

/**
 * Maps the ICS 202 Incident Objectives onto the official ICS Canada 202 template (ICS 202-CAN).
 *
 * The ICS Canada template reuses field names from an older layout, so some check boxes are named after a different
 * item than the label printed beside them. IAP_BOXES lists them by what is printed on the form.
 */
final class Ics202OfficialPdfFieldMapper
{
    /** Record column => template field name of the box printed beside that item. */
    private const IAP_BOXES = [
        'iap_include_ics203' => 'ICS 203',
        'iap_include_ics204' => 'ICS 204',
        'iap_include_ics205' => 'ICS 205',
        'iap_include_ics205a' => 'ICS 205A',
        'iap_include_ics206' => 'ICS 206',
        'iap_include_ics208' => 'ICS 207',                              // printed: ICS 208
        'iap_include_map_chart' => 'ICS 208',                           // printed: Map/Chart
        'iap_include_weather_forecast' => 'Map/Chart',                  // printed: Weather Forecast/Tides/Currents
        'iap_include_blank_ics214' => 'Weather Forecast/Tides/Current', // printed: Blank ICS 214
    ];

    /**
     * @param array<int, string> $attachmentNames uploaded attachments, listed under "Other Attachments"
     * @return array{fields: array<string, mixed>, continuations: array<int, array{heading: string, text: string}>}
     */
    public static function build(array $record, array $incident, array $op, array $attachmentNames = []): array
    {
        $cont = [];
        $start = (string)($op['op_start'] ?? $op['start_time'] ?? '');
        $end = (string)($op['op_end'] ?? $op['end_time'] ?? '');
        $f = [
            '1 Incident Name_5' => ics_pdf_incident_name($incident),
            'Date From' => ics_pdf_fmt($start, 'M j'),
            'Time From' => ics_pdf_fmt($start, 'H:i'),
            'Date To' => ics_pdf_fmt($end, 'M j'),
            'Time To' => ics_pdf_fmt($end, 'H:i'),
            '3 Objectives' => (string)($record['objectives'] ?? ''),
            '4 Operational Period Command Emphasis' => (string)($record['operational_period_command_emphasis'] ?? ''),
            'General Situational Awareness' => (string)($record['general_situational_awareness'] ?? ''),
            '5 Site Safety Plan Required Yes No Approved Site Safety Plans Located at' => (string)($record['site_safety_plan_location'] ?? ''),
            '7 Prepared by Name' => (string)($record['prepared_by_name'] ?? ''),
            'PositionTitle_5' => (string)($record['prepared_by_position_title'] ?? $record['prepared_by_position'] ?? ''),
            '8 Approved by Incident Commander Name' => (string)($record['approved_by_ic_name'] ?? ''),
            'DateTime_5' => ics_pdf_fmt($record['approved_at'] ?? ''),
            'IAP Page' => (string)($record['iap_page'] ?? ''),
        ];
        $headings = [
            '3 Objectives' => '3. Objective(s)',
            '4 Operational Period Command Emphasis' => '4. Operational Period Command Emphasis',
            'General Situational Awareness' => 'General Situational Awareness',
            '5 Site Safety Plan Required Yes No Approved Site Safety Plans Located at' => '5. Approved Site Safety Plan(s) Located at',
        ];

        $safety = strtolower(trim((string)($record['site_safety_plan_required'] ?? '')));
        if (in_array($safety, ['1', 'yes', 'y', 'true'], true)) {
            $f['Yes'] = OfficialPdfText::check();
        } elseif (in_array($safety, ['0', 'no', 'n', 'false'], true)) {
            $f['No'] = OfficialPdfText::check();
        }

        foreach (self::IAP_BOXES as $column => $field) {
            if (self::isChecked($record[$column] ?? null)) { $f[$field] = OfficialPdfText::check(); }
        }

        // "Other Attachments": four lines with a box each.
        $others = [];
        if (self::isChecked($record['iap_include_medical_plan'] ?? null)) { $others[] = 'Medical Plan'; }
        foreach ($attachmentNames as $name) { if (trim($name) !== '') { $others[] = trim($name); } }
        foreach (array_slice($others, 0, 4) as $i => $name) {
            $f['Check ' . ($i + 1)] = OfficialPdfText::check();
            $f['Other Attachments ' . ($i + 1)] = $name;
        }
        if (count($others) > 4) { $cont[] = ['heading' => '6. Other attachments', 'text' => implode("\n", $others)]; }

        return ['fields' => OfficialPdfText::apply('ICS202', $f, $headings, $cont), 'continuations' => $cont];
    }

    /** Fields only (kept for older callers). */
    public static function map(array $record, array $incident, array $operationalPeriod): array
    {
        return self::build($record, $incident, $operationalPeriod)['fields'];
    }

    private static function isChecked(mixed $value): bool
    {
        return in_array(strtolower(trim((string)$value)), ['1', 'yes', 'y', 'true', 'on'], true);
    }
}
