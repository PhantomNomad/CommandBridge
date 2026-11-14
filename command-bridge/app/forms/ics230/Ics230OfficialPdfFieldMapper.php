<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/pdf/OfficialPdfText.php';

/**
 * Maps the ICS 230 Daily Meeting Schedule onto the official ICS Canada 230 template (fillable version, 12 meeting
 * rows a page: DateTimeRowN, Meeting NameRowN, PurposeRowN, AttendeesRowN, LocationRowN).
 * The operational period "From" box is named Text2 and the "To" box "2Operatinal Period DateTime From To".
 */
final class Ics230OfficialPdfFieldMapper
{
    public const ROWS_PER_PAGE = 12;

    /** @return array{pages: array<int, array<string, mixed>>, continuations: array<int, array{heading: string, text: string}>} */
    public static function build(array $incident, array $op, array $meetings, string $preparedName, string $preparedPosition, string $preparedAt): array
    {
        $cont = [];
        $common = [
            '1 Incident Name' => ics_pdf_incident_name($incident),
            'Text2' => ics_pdf_fmt($op['op_start'] ?? $op['start_time'] ?? ''),
            '2Operatinal Period DateTime From To' => ics_pdf_fmt($op['op_end'] ?? $op['end_time'] ?? ''),
            '4 Prepared by Situation Unit Leader' => trim($preparedName . ($preparedPosition !== '' ? ', ' . $preparedPosition : '')),
            'DateTime' => ics_pdf_fmt($preparedAt),
        ];
        $pages = [];
        foreach (array_chunk(array_values($meetings), self::ROWS_PER_PAGE) ?: [[]] as $chunk) {
            $f = $common;
            $headings = [];
            foreach ($chunk as $i => $m) {
                $n = $i + 1;
                $name = trim((string)($m['title'] ?? '')) ?: trim((string)($m['meeting_type_name'] ?? ''));
                $status = strtolower(trim((string)($m['status'] ?? '')));
                if (in_array($status, ['cancelled', 'tentative', 'completed'], true)) { $name .= ' (' . $status . ')'; }
                $chair = trim(trim((string)($m['chair_name'] ?? '')) . ' ' . trim((string)($m['chair_position_title'] ?? '')));
                $attendees = trim((string)($m['attendees_summary'] ?? ''));
                if ($chair !== '') { $attendees = 'Chair: ' . $chair . ($attendees !== '' ? "\n" . $attendees : ''); }
                $label = $name . ' ' . ics_pdf_fmt($m['scheduled_at'] ?? '');
                $f['DateTimeRow' . $n] = ics_pdf_fmt($m['scheduled_at'] ?? '', 'M j') . "\n" . ics_pdf_fmt($m['scheduled_at'] ?? '', 'H:i');
                $f['Meeting NameRow' . $n] = $name;
                $f['PurposeRow' . $n] = (string)($m['purpose'] ?? '');
                $f['AttendeesRow' . $n] = $attendees;
                $f['LocationRow' . $n] = (string)($m['location'] ?? '');
                $headings += [
                    'Meeting NameRow' . $n => $label . ' - Meeting name',
                    'PurposeRow' . $n => $label . ' - Purpose',
                    'AttendeesRow' . $n => $label . ' - Attendees',
                    'LocationRow' . $n => $label . ' - Location',
                ];
            }
            $pages[] = OfficialPdfText::apply('ICS230', $f, $headings, $cont);
        }
        return ['pages' => $pages, 'continuations' => $cont];
    }
}
