<?php
declare(strict_types=1);

/**
 * Maps the ICS 205A Communications List onto the official ICS Canada 205A template (34 rows per page).
 * The template names its "Name (Alphabetized)" column as one parent field with 34 children, so the
 * n-th row's name is written to "Name AlphabetizedRow1.<n-1>" (and ".19.<n-20>" from row 20 on).
 */
final class Ics205AOfficialPdfFieldMapper
{
    public const ROWS_PER_PAGE = 34;

    private static function nameField(int $rowIndex): string
    {
        return $rowIndex < 19
            ? 'Name AlphabetizedRow1.' . $rowIndex
            : 'Name AlphabetizedRow1.19.' . ($rowIndex - 19);
    }

    /**
     * @param array<string, mixed> $header form_205a_headers row
     * @param array<int, array<string, mixed>> $entries form_205a_entries rows
     * @return array<int, array<string, string>>
     */
    public static function pages(array $header, array $incident, array $op, array $entries, array $formRecord): array
    {
        $chunks = array_chunk($entries, self::ROWS_PER_PAGE) ?: [[]];
        $pages = [];

        foreach ($chunks as $chunk) {
            $fields = [
                '1 Incident Name' => ics_pdf_incident_name($incident),
                'Text44' => ics_pdf_fmt($op['op_start'] ?? $op['start_time'] ?? ''),
                'Text45' => ics_pdf_fmt($op['op_end'] ?? $op['end_time'] ?? ''),
                '3 Basic Local Communications Information' => trim((string)($header['notes'] ?? '')),
                'Name' => trim((string)($formRecord['prepared_by_name'] ?? '')),
                'Text46' => trim((string)($formRecord['prepared_by_position_title'] ?? '')),
                'Text48' => ics_pdf_fmt($formRecord['prepared_at'] ?? $formRecord['updated_at'] ?? ''),
            ];

            foreach (array_values($chunk) as $i => $entry) {
                $n = $i + 1;
                $fields["Incident Assigned PositionRow$n"] = trim((string)($entry['incident_assigned_position'] ?? ''));
                $fields[self::nameField($i)] = trim((string)($entry['person_name'] ?? ''));
                $fields["Methods of Contact phone pager cell etcRow$n"] = trim((string)($entry['contact_methods'] ?? ''));
            }

            $pages[] = array_filter($fields, static fn (string $v): bool => $v !== '');
        }

        return $pages;
    }
}
