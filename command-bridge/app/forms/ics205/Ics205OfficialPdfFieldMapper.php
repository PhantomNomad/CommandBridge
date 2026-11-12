<?php
declare(strict_types=1);

/**
 * Translates the Command Bridge ICS 205 record into fields on the approved
 * ICS Canada 205 AcroForm template.
 */
final class Ics205OfficialPdfFieldMapper
{
    /**
     * @param array<string, mixed> $header
     * @param array<string, mixed> $incident
     * @param array<string, mixed> $operationalPeriod
     * @param array<int, array<string, mixed>> $channels
     * @param array<string, mixed> $formRecord
     * @return array<string, string>
     */
    public static function map(
        array $header,
        array $incident,
        array $operationalPeriod,
        array $channels,
        array $formRecord = []
    ): array {
        $preparedAt = $formRecord['updated_at']
            ?? $formRecord['prepared_at']
            ?? $formRecord['created_at']
            ?? '';

        $fields = [
            '1 INCIDENT NAME' => self::text($incident['incident_name'] ?? $incident['name'] ?? ''),
            '2 DATETIME PREPARED' => self::dateTimeValue($preparedAt),
            'Text1' => self::dateValue($operationalPeriod['op_start'] ?? $operationalPeriod['start_time'] ?? ''),
            'Text2' => self::timeValue($operationalPeriod['op_start'] ?? $operationalPeriod['start_time'] ?? ''),
            'Text3' => self::dateValue($operationalPeriod['op_end'] ?? $operationalPeriod['end_time'] ?? ''),
            'Text4' => self::timeValue($operationalPeriod['op_end'] ?? $operationalPeriod['end_time'] ?? ''),
            'PREPARED BY (Communications Unit)' => self::preparedBy($header),
        ];

        /*
         * The official ICS 205 template provides thirteen radio-channel rows.
         * Preserve the saved row order so the PDF matches the web form.
         */
        for ($rowNumber = 1; $rowNumber <= 13; $rowNumber++) {
            $channel = (array)($channels[$rowNumber - 1] ?? []);
            $fields['SystemTypeRow' . $rowNumber] = self::text($channel['system_type'] ?? '');
            $fields['ChannelRow' . $rowNumber] = self::text($channel['channel'] ?? '');
            $fields['FunctionRow' . $rowNumber] = self::text($channel['function'] ?? '');
            $fields['FrequencyTone Row' . $rowNumber] = self::text($channel['frequency_tone'] ?? '');
            $fields['AssignmentRow' . $rowNumber] = self::text($channel['assignment'] ?? '');
            $fields['RemarksRow' . $rowNumber] = self::text($channel['remarks'] ?? '');
        }

        return array_filter($fields, static fn (string $value): bool => $value !== '');
    }

    /**
     * @param array<string, mixed> $header
     */
    private static function preparedBy(array $header): string
    {
        return trim(
            self::text($header['prepared_by_name'] ?? '')
            . (trim(self::text($header['prepared_by_position_title'] ?? '')) !== ''
                ? ' - ' . self::text($header['prepared_by_position_title'] ?? '')
                : '')
        );
    }

    private static function text(mixed $value): string
    {
        return trim((string)$value);
    }

    private static function dateValue(mixed $value): string
    {
        $dateTime = self::toDateTime($value);
        return $dateTime ? $dateTime->format('Y-m-d') : self::text($value);
    }

    private static function timeValue(mixed $value): string
    {
        $dateTime = self::toDateTime($value);
        return $dateTime ? $dateTime->format('H:i') : '';
    }

    private static function dateTimeValue(mixed $value): string
    {
        $dateTime = self::toDateTime($value);
        return $dateTime ? $dateTime->format('Y-m-d H:i') : self::text($value);
    }

    private static function toDateTime(mixed $value): ?DateTimeImmutable
    {
        $value = trim((string)$value);
        if ($value === '') {
            return null;
        }

        try {
            return new DateTimeImmutable($value);
        } catch (Throwable) {
            return null;
        }
    }
}
