<?php
declare(strict_types=1);

/**
 * Translates the Command Bridge ICS 202 record into fields on the approved
 * ICS Canada 202 AcroForm template. Keep application-to-template mapping here
 * rather than in the PDF endpoint so subsequent form exports follow one pattern.
 */
final class Ics202OfficialPdfFieldMapper
{
    /**
     * @param array<string, mixed> $record
     * @param array<string, mixed> $incident
     * @param array<string, mixed> $operationalPeriod
     * @return array<string, string|array{value: string, type: 'name'}>
     */
    public static function map(array $record, array $incident, array $operationalPeriod): array
    {
        $fields = [
            '1 Incident Name_5' => self::text($incident['incident_name'] ?? $incident['name'] ?? ''),
            'Date From' => self::dateValue($operationalPeriod['start_time'] ?? ''),
            'Date To' => self::dateValue($operationalPeriod['end_time'] ?? ''),
            'Time From' => self::timeValue($operationalPeriod['start_time'] ?? ''),
            'Time To' => self::timeValue($operationalPeriod['end_time'] ?? ''),
            '3 Objectives' => self::text($record['objectives'] ?? ''),
            '4 Operational Period Command Emphasis' => self::text($record['operational_period_command_emphasis'] ?? ''),
            'General Situational Awareness' => self::text($record['general_situational_awareness'] ?? ''),
            '5 Site Safety Plan Required Yes No Approved Site Safety Plans Located at' => self::text($record['site_safety_plan_location'] ?? ''),
            '7 Prepared by Name' => self::text($record['prepared_by_name'] ?? ''),
            'PositionTitle_5' => self::text($record['prepared_by_position'] ?? ''),
            '8 Approved by Incident Commander Name' => self::text($record['approved_by_ic_name'] ?? ''),
            'DateTime_5' => self::dateTimeValue($record['approved_at'] ?? ''),
            'IAP Page' => self::text($record['iap_page'] ?? ''),
        ];

        $siteSafetyRequired = strtolower(self::text($record['site_safety_plan_required'] ?? ''));
        if (in_array($siteSafetyRequired, ['1', 'yes', 'y', 'true'], true)) {
            $fields['Yes'] = self::checkbox();
        } elseif (in_array($siteSafetyRequired, ['0', 'no', 'n', 'false'], true)) {
            $fields['No'] = self::checkbox();
        }

        $checkboxes = [
            'iap_include_ics203' => 'ICS 203',
            'iap_include_ics204' => 'ICS 204',
            'iap_include_ics205' => 'ICS 205',
            'iap_include_ics205a' => 'ICS 205A',
            'iap_include_ics206' => 'ICS 206',
            'iap_include_ics207' => 'ICS 207',
            'iap_include_ics208' => 'ICS 208',
            'iap_include_map_chart' => 'Map/Chart',
            'iap_include_weather_forecast' => 'Weather Forecast/Tides/Current',
        ];
        foreach ($checkboxes as $recordField => $templateField) {
            if (self::isChecked($record[$recordField] ?? null)) {
                $fields[$templateField] = self::checkbox();
            }
        }

        // The official template provides four named "Other Attachments" slots.
        // Use the first for an explicitly included medical plan.
        if (self::isChecked($record['iap_include_medical_plan'] ?? null)) {
            $fields['Check 1'] = self::checkbox();
            $fields['Other Attachments 1'] = 'Medical Plan';
        }

        return array_filter(
            $fields,
            static fn ($value): bool => is_array($value) || $value !== ''
        );
    }

    /**
     * @return array{value: string, type: 'name'}
     */
    private static function checkbox(): array
    {
        return ['value' => 'Yes', 'type' => 'name'];
    }

    private static function isChecked(mixed $value): bool
    {
        return in_array(strtolower(trim((string)$value)), ['1', 'yes', 'y', 'true', 'on'], true);
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
