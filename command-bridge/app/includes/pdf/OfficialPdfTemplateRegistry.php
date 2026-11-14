<?php
declare(strict_types=1);

/**
 * Central registry for the official ICS Canada PDF templates bundled with Command Bridge.
 *
 * Templates marked as non-fillable (ICS 218) are filled by stamping a text overlay onto the official page
 * (OfficialPdfTemplateExporter::stampTemplate()). Do not accept user-supplied paths here.
 */
final class OfficialPdfTemplateRegistry
{
    private const TEMPLATES = [
        'ICS201'   => ['file' => 'ICS201.pdf',    'fillable' => true],
        'ICS202'   => ['file' => 'ICS202.pdf',    'fillable' => true],
        'ICS203'   => ['file' => 'ICS203.pdf',    'fillable' => true],
        'ICS203A'  => ['file' => 'ICS203A.pdf',   'fillable' => true],
        'ICS204'   => ['file' => 'ICS204.pdf',    'fillable' => true],
        'ICS205'   => ['file' => 'ICS205.pdf',    'fillable' => true],
        'ICS205A'  => ['file' => 'ICS205A.pdf',   'fillable' => true],
        'ICS206'   => ['file' => 'ICS206.pdf',    'fillable' => true],
        'ICS207'   => ['file' => 'ICS207.pdf',    'fillable' => true],
        'ICS208'   => ['file' => 'ICS208.pdf',    'fillable' => true],
        'ICS209'   => ['file' => 'ICS209.pdf',    'fillable' => true],
        'ICS209WF' => ['file' => 'ICS209-wf.pdf', 'fillable' => true],
        'ICS210'   => ['file' => 'ICS210.pdf',    'fillable' => true],
        'ICS211'   => ['file' => 'ICS211.pdf',    'fillable' => true],
        'ICS213'   => ['file' => 'ICS213.pdf',    'fillable' => true],
        'ICS213RR' => ['file' => 'ICS213RR.pdf',  'fillable' => true],
        'ICS214'   => ['file' => 'ICS214.pdf',    'fillable' => true],
        'ICS215'   => ['file' => 'ICS215.pdf',    'fillable' => true],
        'ICS215A'  => ['file' => 'ICS215A.pdf',   'fillable' => true],
        'ICS216'   => ['file' => 'ICS216.pdf',    'fillable' => true],
        'ICS217'   => ['file' => 'ICS217.pdf',    'fillable' => true],
        'ICS218'   => ['file' => 'ICS218.pdf',    'fillable' => false, 'pages' => 3],
        'ICS220'   => ['file' => 'ICS220.pdf',    'fillable' => true],
        'ICS221'   => ['file' => 'ICS221.pdf',    'fillable' => true],
        'ICS224'   => ['file' => 'ICS224.pdf',    'fillable' => true],
        'ICS225'   => ['file' => 'ICS225.pdf',    'fillable' => true],
        'ICS230'   => ['file' => 'ICS230.pdf',    'fillable' => true],
        'ICS232'   => ['file' => 'ICS232.pdf',    'fillable' => true],
        'ICS233'   => ['file' => 'ICS233.pdf',    'fillable' => false],
        'ICS234'   => ['file' => 'ICS234.pdf',    'fillable' => true],
        'ICS260'   => ['file' => 'ICS260.pdf',    'fillable' => false],
        'ICS309'   => ['file' => 'ICS309.pdf',    'fillable' => true],
    ];

    public static function normalizeFormCode(string $formCode): string
    {
        return str_replace([' ', '_', '-'], '', strtoupper(trim($formCode)));
    }

    /**
     * @return array{form_code: string, file: string, path: string, fillable: bool, pages: int}
     */
    public static function get(string $formCode): array
    {
        $normalized = self::normalizeFormCode($formCode);
        if (!isset(self::TEMPLATES[$normalized])) {
            throw new InvalidArgumentException('No official PDF template is registered for this form.');
        }

        $template = self::TEMPLATES[$normalized];
        $path = self::templateDirectory() . DIRECTORY_SEPARATOR . $template['file'];

        if (!is_file($path) || !is_readable($path)) {
            throw new RuntimeException('The official PDF template is unavailable on this server.');
        }

        return [
            'form_code' => $normalized,
            'file' => $template['file'],
            'path' => $path,
            'fillable' => (bool)$template['fillable'],
            'pages' => (int)($template['pages'] ?? 1),
        ];
    }

    /**
     * @return array<int, array{form_code: string, file: string, path: string, fillable: bool}>
     */
    public static function all(): array
    {
        $templates = [];
        foreach (array_keys(self::TEMPLATES) as $formCode) {
            try {
                $templates[] = self::get($formCode);
            } catch (RuntimeException $e) {
                $template = self::TEMPLATES[$formCode];
                $templates[] = [
                    'form_code' => $formCode,
                    'file' => $template['file'],
                    'path' => self::templateDirectory() . DIRECTORY_SEPARATOR . $template['file'],
                    'fillable' => (bool)$template['fillable'],
                ];
            }
        }

        return $templates;
    }

    public static function templateDirectory(): string
    {
        return dirname(__DIR__, 2) . '/assets/pdf/official';
    }
}
