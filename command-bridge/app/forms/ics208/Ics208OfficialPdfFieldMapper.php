<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/pdf/OfficialPdfText.php';

/**
 * Maps the ICS 208 Safety Message/Plan onto the official ICS Canada 208 template.
 * The template's operational-period field names do not match their positions: "Date from"/"Date to" are the start
 * date and time, "Time from"/"Time to" are the end date and time.
 */
final class Ics208OfficialPdfFieldMapper
{
    /** @return array{fields: array<string, mixed>, continuations: array<int, array{heading: string, text: string}>} */
    public static function build(array $header, array $incident, array $op, array $formRecord): array
    {
        $cont = [];
        $p = OfficialPdfText::opParts($op);
        $preparedBy = trim(trim((string)($header['prepared_by_name'] ?? '')) . ', ' . trim((string)($header['prepared_by_position_title'] ?? '')), ', ');
        $prepared = (string)($formRecord['prepared_at'] ?? $formRecord['updated_at'] ?? '');
        $fields = [
            '3 INCIDENT NAME' => ics_pdf_incident_name($incident),
            'Date from' => $p['from_date'],
            'Date to' => $p['from_time'],
            'Time from' => $p['to_date'],
            'Time to' => $p['to_time'],
            '3. SAFETY MESSAGE' => (string)($header['safety_message'] ?? ''),
            'Approved Site Safety Plan(s) Located At:' => (string)($header['site_safety_plan_location'] ?? ''),
            'PREPARED BY (Name and Position)' => $preparedBy,
            'Date/Time:' => ics_pdf_fmt($prepared, 'Y-m-d'),
            'Text15' => ics_pdf_fmt($prepared, 'H:i'),
        ];
        $fields[!empty($header['site_safety_plan_required']) ? 'Yes' : 'No'] = OfficialPdfText::check();
        $headings = ['3. SAFETY MESSAGE' => '3. Safety Message / Plan', 'Approved Site Safety Plan(s) Located At:' => '4. Site Safety Plan location'];
        return ['fields' => OfficialPdfText::apply('ICS208', $fields, $headings, $cont), 'continuations' => $cont];
    }
}
