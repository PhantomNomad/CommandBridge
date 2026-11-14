<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/pdf/OfficialPdfText.php';

/**
 * Maps one person's ICS 214 activity log onto the official ICS Canada 214 template.
 * Template page 1 holds 15 activity rows (TimeRowN / Major EventsRowN); page 2 is the continuation page with 30 rows
 * (time fields Text37-55, 60-70; event fields Text5-18, 21-36). Long events wrap onto the next rows.
 * Block 7 (personnel assigned) is left blank: each person keeps their own log in Command Bridge.
 */
final class Ics214OfficialPdfFieldMapper
{
    private const EVENT_WIDTH = 457.0;
    private const EVENT_FONT = 11.0;

    /** Template page-2 field names, in row order. */
    private static function page2Rows(): array
    {
        $time = array_merge(range(37, 55), range(60, 70));
        $event = array_merge(range(5, 18), range(21, 36));
        $rows = [];
        foreach ($time as $i => $t) { $rows[] = ['Text' . $t, 'Text' . $event[$i]]; }
        return $rows;
    }

    /**
     * @param array<int, array{time: string, text: string, manual?: bool, by?: string}> $rows from ics214_rows()
     * @return array{pages: array<int, array<string, mixed>>, keep: array<int, string>}
     */
    public static function build(array $log, array $owner, array $incident, array $op, array $rows): array
    {
        $ownerName = trim((string)(($log['personnel_name'] ?? '') ?: ($owner['name'] ?? '')));
        $position = trim((string)(($log['ICS_position_title'] ?? '') ?: ($owner['position'] ?? '')));
        $status = strtolower((string)($log['status'] ?? ''));
        $prepared = (string)(($log['date_time_prepared'] ?? '') ?: ($log['submitted_at'] ?? '') ?: date('Y-m-d H:i:s'));
        $preparedBy = trim($ownerName . ($position !== '' ? ', ' . $position : ''));
        if (in_array($status, ['submitted', 'approved'], true) && !empty($log['submitted_at'])) {
            $preparedBy .= ' - signed ' . ics_pdf_fmt($log['submitted_at']);
        }
        $p = OfficialPdfText::opParts($op, 'M j');

        // One output line per form row: the time on the first line of each event only.
        $lines = [];
        foreach ($rows as $r) {
            $text = trim(str_replace(["\r\n", "\r"], "\n", (string)($r['text'] ?? '')));
            if (!empty($r['manual']) && trim((string)($r['by'] ?? '')) !== '' && trim((string)$r['by']) !== $ownerName) {
                $text .= ' (entry by ' . trim((string)$r['by']) . ')';
            }
            $wrapped = [];
            foreach (explode("\n", $text) as $para) {
                $wrapped = array_merge($wrapped, SimpleTextPdf::wrap($para, self::EVENT_WIDTH - 6, self::EVENT_FONT) ?: ['']);
            }
            foreach ($wrapped as $i => $w) {
                $lines[] = [$i === 0 ? ics_pdf_fmt($r['time'] ?? '', 'M j H:i') : '', $w];
            }
        }

        $chunks = [array_slice($lines, 0, 15)];
        $rest = array_slice($lines, 15);
        while ($rest) { $chunks[] = array_slice($rest, 0, 30); $rest = array_slice($rest, 30); }
        $total = count($chunks);

        $header = [
            '1 INCIDENT NAME' => ics_pdf_incident_name($incident),
            '2 DATE PREPARED' => ics_pdf_fmt($prepared, 'Y-m-d'),
            '3 TIME PREPARED' => ics_pdf_fmt($prepared, 'H:i'),
            '4. NAME' => $ownerName,
            '5. ICS POSITION' => $position,
            'Text19' => $p['from_time'], 'Text20' => $p['to_time'],
            '9. PREPARED BY (Name and Position)' => $preparedBy,
        ];

        $pages = [];
        $keep = [];
        $p2 = self::page2Rows();
        foreach ($chunks as $ci => $chunk) {
            $f = $header;
            if ($ci === 0) {
                $f['Text56'] = $p['from_date'];
                $f['Text57'] = $p['to_date'];
                $f['Text1'] = '1';
                $f['Text2'] = (string)$total;
                foreach ($chunk as $i => [$time, $event]) {
                    $f['TimeRow' . ($i + 1)] = $time;
                    $f['Major EventsRow' . ($i + 1)] = $event;
                }
                $keep[] = '1';
            } else {
                $f['Text58'] = $p['from_date'];
                $f['Text59'] = $p['to_date'];
                $f['Text3'] = (string)($ci + 1);
                $f['Text4'] = (string)$total;
                foreach ($chunk as $i => [$time, $event]) {
                    $f[$p2[$i][0]] = $time;
                    $f[$p2[$i][1]] = $event;
                }
                $keep[] = '2';
            }
            $noCont = [];
            $pages[] = OfficialPdfText::apply('ICS214', $f, [], $noCont);
        }
        return ['pages' => $pages, 'keep' => $keep];
    }
}
