<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/pdf/OfficialPdfText.php';

/**
 * Maps the ICS 230 Daily Meeting Schedule onto the official ICS Canada 230 template (12 meeting rows a page).
 * The template's field names jump around the page, so ROWS lists them top to bottom:
 * [date/time, meeting name, purpose, attendees, location, row height in points].
 */
final class Ics230OfficialPdfFieldMapper
{
    private const ROWS = [
        ['Text1', 'Text12.0', 'Text12.1', 'Text12.2', 'Text12.3', 28],
        ['Text2.0', 'Text22', 'Text21', 'Text20', 'Text19', 36],
        ['Text2.1', 'Text11.0', 'Text11.1', 'Text11.2', 'Text11.3', 28],
        ['Text2.2', 'Text26', 'Text25', 'Text24', 'Text23', 36],
        ['Text2.3', 'Text10.0', 'Text10.1', 'Text10.2', 'Text10.3', 28],
        ['Text2.4', 'Text28', 'Text31', 'Text32', 'Text35', 45],
        ['Text2.5', 'Text8.0', 'Text8.1', 'Text8.2', 'Text8.3', 34],
        ['Text2.6', 'Text29', 'Text33', 'Text34', 'Text36', 63],
        ['Text2.7', 'Text7.0', 'Text7.1', 'Text7.2', 'Text7.3', 38],
        ['Text3', 'Text30', 'Text37', 'Text38', 'Text39', 54],
        ['Text4', 'Text6.0.0', 'Text6.0.1', 'Text6.0.2', 'Text6.0.3', 39],
        ['Text5', 'Text6.1.0', 'Text6.1.1', 'Text6.1.2', 'Text6.1.3', 34],
    ];

    /** @return array{pages: array<int, array<string, mixed>>, continuations: array<int, array{heading: string, text: string}>} */
    public static function build(array $incident, array $op, array $meetings, string $preparedName, string $preparedPosition, string $preparedAt): array
    {
        $cont = [];
        $common = [
            'Text13' => ics_pdf_incident_name($incident),
            'Text14' => ics_pdf_fmt($op['op_start'] ?? $op['start_time'] ?? ''),
            'Text15' => ics_pdf_fmt($op['op_end'] ?? $op['end_time'] ?? ''),
            'Text16' => trim($preparedName . ($preparedPosition !== '' ? ', ' . $preparedPosition : '')),
            'Text17' => ics_pdf_fmt($preparedAt),
        ];
        $pages = [];
        foreach (array_chunk(array_values($meetings), count(self::ROWS)) ?: [[]] as $chunk) {
            $f = $common;
            $headings = [];
            foreach ($chunk as $i => $m) {
                [$fDate, $fName, $fPurpose, $fAttend, $fLoc, $h] = self::ROWS[$i];
                $name = trim((string)($m['title'] ?? '')) ?: trim((string)($m['meeting_type_name'] ?? ''));
                $status = strtolower(trim((string)($m['status'] ?? '')));
                if (in_array($status, ['cancelled', 'tentative', 'completed'], true)) { $name .= ' (' . $status . ')'; }
                $chair = trim(trim((string)($m['chair_name'] ?? '')) . ' ' . trim((string)($m['chair_position_title'] ?? '')));
                $attendees = trim((string)($m['attendees_summary'] ?? ''));
                if ($chair !== '') { $attendees = 'Chair: ' . $chair . ($attendees !== '' ? "\n" . $attendees : ''); }
                $label = $name . ' ' . ics_pdf_fmt($m['scheduled_at'] ?? '');
                $f[$fDate] = ics_pdf_fmt($m['scheduled_at'] ?? '', 'M j H:i');
                $f[$fName] = $name;
                $headings[$fName] = $label . ' - Meeting name';
                $f[$fPurpose] = (string)($m['purpose'] ?? '');
                $headings[$fPurpose] = $label . ' - Purpose';
                $f[$fAttend] = $attendees;
                $headings[$fAttend] = $label . ' - Attendees';
                $f[$fLoc] = (string)($m['location'] ?? '');
                $headings[$fLoc] = $label . ' - Location';
            }
            $pages[] = OfficialPdfText::apply('ICS230', $f, $headings, $cont);
        }
        return ['pages' => $pages, 'continuations' => $cont];
    }
}
