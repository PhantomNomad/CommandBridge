<?php
declare(strict_types=1);

/** Maps the ICS 210 Resource Status Change record onto the official ICS Canada 210 template (28 rows per page). */
final class Ics210OfficialPdfFieldMapper
{
    public const ROWS_PER_PAGE = 28;

    /**
     * @param array<string, mixed> $header form_210_headers row (may be empty)
     * @param array<int, array<string, mixed>> $rows form_210_status_rows
     * @return array<int, array<string, string>> one field map per PDF page
     */
    public static function pages(array $header, array $incident, array $op, array $rows, array $formRecord): array
    {
        $chunks = array_chunk($rows, self::ROWS_PER_PAGE) ?: [[]];
        $pages = [];

        foreach ($chunks as $chunk) {
            $start = (string)($op['op_start'] ?? $op['start_time'] ?? '');
            $end = (string)($op['op_end'] ?? $op['end_time'] ?? '');
            $preparedBy = trim((string)($header['prepared_by'] ?? $formRecord['prepared_by_name'] ?? ''));
            $position = trim((string)($header['prepared_by_position'] ?? $formRecord['prepared_by_position_title'] ?? ''));
            $preparedAt = (string)($header['prepared_date_time'] ?? $formRecord['prepared_at'] ?? $formRecord['updated_at'] ?? '');

            $fields = [
                '1 Incident Name' => ics_pdf_incident_name($incident),
                'Text7' => ics_pdf_fmt($start, 'Y-m-d'),
                'Text9' => ics_pdf_fmt($start, 'H:i'),
                'Text2' => ics_pdf_fmt($end, 'Y-m-d'),
                'Text3' => ics_pdf_fmt($end, 'H:i'),
                '8 Comments' => trim((string)($header['comments'] ?? '')),
                'Text11' => trim($preparedBy . ($position !== '' ? ' - ' . $position : '')),
                'Text1' => ics_pdf_fmt($preparedAt),
            ];

            foreach (array_values($chunk) as $i => $row) {
                $n = $i + 1;
                $changed = (string)($row['status_date_time'] ?? '');
                $fields["3 Resource NumberRow$n"] = trim((string)($row['resource_number'] ?? ''));
                $fields["4 New Status Available Assigned OSRow$n"] = trim((string)($row['new_status'] ?? ''));
                $fields["5 From Assignment and StatusRow$n"] = trim((string)($row['from_location'] ?? ''));
                $fields["6 To Assignment and StatusRow$n"] = trim((string)($row['to_location'] ?? ''));
                // The form prints "Time and Date of Change" as two boxes, in that order.
                $fields["7 Time and Date of ChangeRow$n"] = ics_pdf_fmt($changed, 'H:i');
                $fields["7 Time and Date of ChangeRow{$n}_2"] = ics_pdf_fmt($changed, 'Y-m-d');
            }

            $pages[] = array_filter($fields, static fn (string $v): bool => $v !== '');
        }

        return $pages;
    }
}
