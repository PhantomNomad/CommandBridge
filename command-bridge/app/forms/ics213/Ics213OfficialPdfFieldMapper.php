<?php
declare(strict_types=1);

/** Maps one ICS 213 General Message onto the official ICS Canada 213 template. */
final class Ics213OfficialPdfFieldMapper
{
    /**
     * @param array<string, mixed> $message form_213_messages row
     * @param array<string, mixed> $incident
     * @return array<string, string>
     */
    public static function map(array $message, array $incident): array
    {
        $when = (string)($message['date_time'] ?? '');
        $replyWhen = (string)($message['reply_date_time'] ?? '');
        $repliedBy = trim((string)($message['reply_by'] ?? ''));

        $fields = [
            '1 Incident Name Optional' => ics_pdf_incident_name($incident),
            '2 To Name and Position' => trim((string)($message['to_name'] ?? '')),
            '3 From Name and Position' => trim((string)($message['from_name'] ?? '')),
            '4 Subject' => trim((string)($message['subject'] ?? '')),
            '5 Date' => ics_pdf_fmt($when, 'Y-m-d'),
            '6 Time' => ics_pdf_fmt($when, 'H:i'),
            '7 Message' => trim((string)($message['message'] ?? '')),
            '8 Approved by Name' => trim((string)($message['approved_by'] ?? '')),
            '9 Reply' => trim((string)($message['reply'] ?? '')),
            '10 Replied by Name' => $repliedBy,
            'PositionTitle_14' => trim((string)($message['reply_position'] ?? '')),
            'DateTime_14' => ics_pdf_fmt($replyWhen),
        ];

        return array_filter($fields, static fn (string $v): bool => $v !== '');
    }
}
