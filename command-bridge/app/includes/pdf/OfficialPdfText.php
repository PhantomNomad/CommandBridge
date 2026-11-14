<?php
declare(strict_types=1);

require_once __DIR__ . '/SimpleTextPdf.php';
require_once __DIR__ . '/OfficialPdfTemplateRegistry.php';

/**
 * Helpers shared by the official-template field mappers (forms/<form>/Ics*OfficialPdfFieldMapper.php).
 *
 * The official ICS Canada boxes are a fixed size. A mapper fills its fields with the full text, then calls apply(),
 * which uses each box's real size and font (assets/pdf/official/fields/<template>.json, made by
 * tools/pdf_field_metadata.py) to cut text that does not fit at a word boundary, mark it "... (cont.)" and queue the
 * full text for the continuation sheet (see ics_pdf_send_official_with_continuation()).
 */
final class OfficialPdfText
{
    /** Smallest font a box set to "auto size" may shrink to before its text is cut. */
    private const MIN_AUTO_MULTILINE = 8.0;
    private const MIN_AUTO_SINGLE = 7.0;

    /** @var array<string, array<string, array<int, float|int>>> */
    private static array $meta = [];

    /** Box sizes for one template: field name => [width, height, font size (0 = auto), multi-line, page]. */
    public static function meta(string $formCode): array
    {
        $template = OfficialPdfTemplateRegistry::get($formCode);
        $file = OfficialPdfTemplateRegistry::templateDirectory() . '/fields/' . pathinfo($template['file'], PATHINFO_FILENAME) . '.json';
        if (!isset(self::$meta[$file])) {
            $decoded = is_file($file) ? json_decode((string)file_get_contents($file), true) : null;
            self::$meta[$file] = is_array($decoded) ? $decoded : [];
        }
        return self::$meta[$file];
    }

    /**
     * Fit every text value into its box. $headings names the fields whose full text should go on the continuation
     * sheet when it is cut (field name => heading); other fields are just cut with "...".
     *
     * @param array<string, mixed> $fields
     * @param array<string, string> $headings
     * @param array<int, array{heading: string, text: string}> $cont
     * @return array<string, mixed>
     */
    public static function apply(string $formCode, array $fields, array $headings, array &$cont): array
    {
        $meta = self::meta($formCode);
        foreach ($fields as $name => $value) {
            if (!is_string($value) || trim($value) === '' || !isset($meta[$name])) { continue; }
            [$w, $h, $size, $multi] = $meta[$name];
            $heading = (string)($headings[$name] ?? '');
            if ((int)$multi === 1) {
                $fs = (float)$size > 0 ? (float)$size : self::MIN_AUTO_MULTILINE;
                $fields[$name] = self::fit($value, (float)$w, (float)$h, $fs, $heading, $cont);
            } else {
                $one = self::oneLine($value);
                $fs = (float)$size > 0 ? (float)$size : self::MIN_AUTO_SINGLE;
                $cut = self::line($one, (float)$w, $fs);
                if ($cut !== $one && $heading !== '') { $cont[] = ['heading' => $heading, 'text' => trim($value)]; }
                $fields[$name] = $cut;
            }
        }
        return self::clean($fields);
    }

    /** Newlines become "; " (for single-line boxes). */
    public static function oneLine(string $text): string
    {
        return trim((string)preg_replace('/\s*\n+\s*/', '; ', trim(str_replace(["\r\n", "\r"], "\n", $text))));
    }
    /**
     * @param float $w box width in points
     * @param float $h box height in points
     * @param array<int, array{heading: string, text: string}> $cont continuation blocks (appended to)
     */
    public static function fit(string $text, float $w, float $h, float $fs, string $heading, array &$cont, float $leading = 1.15): string
    {
        $text = trim(str_replace(["\r\n", "\r"], "\n", $text));
        if ($text === '') { return ''; }
        $maxLines = max(1, (int)floor(($h - 1) / ($fs * $leading)));
        $inner = $w - 6;
        $lines = SimpleTextPdf::wrap($text, $inner, $fs);
        if (count($lines) <= $maxLines) { return $text; }

        if ($heading !== '') { $cont[] = ['heading' => $heading, 'text' => $text]; }
        $keep = array_slice($lines, 0, $maxLines);
        $marker = $heading !== '' ? ' ... (cont.)' : '...';
        $last = (string)array_pop($keep);
        while ($last !== '' && SimpleTextPdf::textWidth($last . $marker, $fs) > $inner) {
            $pos = mb_strrpos($last, ' ');
            $last = $pos === false ? mb_substr($last, 0, max(0, mb_strlen($last) - 1)) : mb_substr($last, 0, $pos);
        }
        $keep[] = rtrim($last) . $marker;
        return implode("\n", $keep);
    }

    /** One line for a single-line box: newlines become "; " and anything wider than the box is cut with "...". */
    public static function line(string $text, float $w, float $fs = 9.5): string
    {
        $text = self::oneLine($text);
        if ($text === '' || SimpleTextPdf::textWidth($text, $fs) <= $w - 4) { return $text; }
        while ($text !== '' && SimpleTextPdf::textWidth($text . '...', $fs) > $w - 4) {
            $text = mb_substr($text, 0, mb_strlen($text) - 1);
        }
        return rtrim($text) . '...';
    }

    /** A checked box/radio state for the FDF ('Yes' unless the template uses another export value). */
    public static function check(string $state = 'Yes'): array
    {
        return ['value' => $state, 'type' => 'name'];
    }

    /**
     * Start/end of an operational period split for the ICS "From: Date / Time  To: Date / Time" boxes.
     * $dateFormat: the boxes on some forms are too narrow for Y-m-d; pass 'M j' (e.g. "Nov 13") for those.
     *
     * @return array{from_date: string, from_time: string, to_date: string, to_time: string}
     */
    public static function opParts(array $op, string $dateFormat = 'Y-m-d'): array
    {
        $start = (string)($op['op_start'] ?? $op['start_time'] ?? '');
        $end = (string)($op['op_end'] ?? $op['end_time'] ?? '');
        return [
            'from_date' => ics_pdf_fmt($start, $dateFormat),
            'from_time' => ics_pdf_fmt($start, 'H:i'),
            'to_date' => ics_pdf_fmt($end, $dateFormat),
            'to_time' => ics_pdf_fmt($end, 'H:i'),
        ];
    }

    /** Drop empty values (the FDF only needs fields that have something in them). */
    public static function clean(array $fields): array
    {
        return array_filter($fields, static fn ($v): bool => is_array($v) || trim((string)$v) !== '');
    }
}
