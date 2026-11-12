<?php
declare(strict_types=1);

/**
 * Maps the ICS 211 Check-In List onto the official ICS Canada 211 template (18 rows per page).
 *
 * The template's cell names are not meaningful (Text38.3.2.4 ...), so Ics211TemplateFieldMap.php holds a
 * row/column -> field-name table that was generated from the position of each field on the page.
 */
final class Ics211OfficialPdfFieldMapper
{
    public const ROWS_PER_PAGE = 18;

    private const LOCATION_BOXES = [
        'base' => 'Check Box Base',
        'camp' => 'Check Box43',
        'staging area' => 'Check Box44',
        'icp' => 'Check Box45',
        'helibase' => 'Check Box46',
        'other' => 'Check Box47',
    ];

    /**
     * @param array<string, mixed> $header form_211_headers row (may be empty)
     * @param array<int, array<string, mixed>> $entries form_211_entries rows
     * @return array<int, array<string, mixed>> one field map per PDF page
     */
    public static function pages(array $header, array $incident, array $entries, array $formRecord): array
    {
        $map = require __DIR__ . '/Ics211TemplateFieldMap.php';
        $chunks = array_chunk($entries, self::ROWS_PER_PAGE) ?: [[]];
        $totalPages = count($chunks);
        $pages = [];

        $location = trim((string)($header['check_in_location'] ?? ''));
        $locationKey = strtolower($location);
        $preparedBy = trim((string)($formRecord['prepared_by_name'] ?? ''));
        $position = trim((string)($header['prepared_by_position_title'] ?? $formRecord['prepared_by_position_title'] ?? ''));

        foreach ($chunks as $pageIndex => $chunk) {
            $fields = [
                '1 INCIDENT NAME' => ics_pdf_incident_name($incident),
                'INCIDENT NUMBER' => trim((string)($incident['incident_number'] ?? '')),
                'Text41' => ics_pdf_fmt($header['start_datetime'] ?? '', 'Y-m-d'),
                'Text42' => ics_pdf_fmt($header['start_datetime'] ?? '', 'H:i'),
                'Text53' => (string)($pageIndex + 1),
                'Text54' => (string)$totalPages,
                '17 PREPARED BY Name and Position' => trim($preparedBy . ($position !== '' ? ' - ' . $position : '')),
            ];

            if ($location !== '') {
                $box = self::LOCATION_BOXES[$locationKey] ?? self::LOCATION_BOXES['other'];
                $fields[$box] = ['value' => 'Yes', 'type' => 'name'];
                if (!isset(self::LOCATION_BOXES[$locationKey])) {
                    $fields['Text48'] = $location;
                }
            }

            $remarks = [];
            foreach (array_values($chunk) as $i => $e) {
                $cells = $map[$i + 1] ?? [];
                $name = trim((string)($e['person_name'] ?? ''));
                $resourceId = trim((string)($e['resource_identifier'] ?? ''));
                if ($resourceId !== '' && $resourceId !== $name) {
                    $name = $name !== '' ? $name . ' (' . $resourceId . ')' : $resourceId;
                }

                $values = [
                    'agency' => $e['home_agency'] ?? '',
                    'cat' => $e['category'] ?? '',
                    'kind' => $e['kind'] ?? '',
                    'type' => $e['type_st_tf'] ?? '',
                    'name' => $name,
                    'ldw' => $e['ldw_request_number'] ?? '',
                    'order' => $e['order_request_number'] ?? '',
                    'datetime' => ics_pdf_fmt($e['arrival_date'] ?? $e['checkin_time'] ?? '', 'm-d H:i'),
                    'leader' => $e['leader_name'] ?? '',
                    'total' => (isset($e['total_personnel']) && $e['total_personnel'] !== null) ? (string)$e['total_personnel'] : '',
                    'contact' => $e['contact_information'] ?? '',
                    'departure' => $e['departure_point'] ?? '',
                    'travel' => ($e['method_of_travel'] ?? '') !== '' ? $e['method_of_travel'] : ($e['transportation_info'] ?? ''),
                    'assignment' => ($e['incident_assignment'] ?? '') !== '' ? $e['incident_assignment'] : ($e['incident_assigned_position'] ?? ''),
                    'quals' => $e['other_qualifications'] ?? '',
                    'sent' => !empty($e['sent_to_resource_unit']) ? 'Yes' : '',
                ];
                foreach ($values as $col => $value) {
                    $value = trim((string)$value);
                    if ($value !== '' && isset($cells[$col])) {
                        $fields[$cells[$col]] = $value;
                    }
                }

                $remark = trim((string)($e['remarks'] ?? ''));
                if ($remark !== '') {
                    $remarks[] = ($name !== '' ? $name . ': ' : '') . $remark;
                }
            }
            if ($remarks) {
                $fields['REMARKS or COMMENTS'] = implode("\n", $remarks);
            }

            $pages[] = array_filter($fields, static fn ($v): bool => $v !== '' && $v !== null);
        }

        return $pages;
    }
}
