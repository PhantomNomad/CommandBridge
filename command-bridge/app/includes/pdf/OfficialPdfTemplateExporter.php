<?php
declare(strict_types=1);

require_once __DIR__ . '/OfficialPdfTemplateRegistry.php';

/**
 * Fills and flattens approved official ICS Canada PDF templates.
 *
 * This service intentionally accepts only templates registered in
 * OfficialPdfTemplateRegistry. Generated documents are created in a temporary
 * non-public directory; callers must send or move the result and then delete it.
 */
final class OfficialPdfTemplateExporter
{
    private string $pdftkBinary;
    private string $temporaryDirectory;

    public function __construct(?string $pdftkBinary = null, ?string $temporaryDirectory = null)
    {
        $configuredBinary = $pdftkBinary ?? getenv('COMMAND_BRIDGE_PDFTK_BIN');
        $configuredTempDirectory = $temporaryDirectory ?? getenv('COMMAND_BRIDGE_PDF_TEMP_DIR');

        $this->pdftkBinary = trim((string)($configuredBinary !== false && $configuredBinary !== '' ? $configuredBinary : 'pdftk'));
        $this->temporaryDirectory = rtrim(
            (string)($configuredTempDirectory !== false && $configuredTempDirectory !== '' ? $configuredTempDirectory : sys_get_temp_dir()),
            DIRECTORY_SEPARATOR
        );

        if ($this->temporaryDirectory === '') {
            $this->temporaryDirectory = sys_get_temp_dir();
        }
    }

    /**
     * @param array<string, string|int|float|bool|array{value: string|int|float|bool, type?: 'text'|'name'}|null> $fields
     * @return string Absolute path to a temporary flattened PDF. Caller must delete it after delivery.
     */
    public function create(string $formCode, array $fields): string
    {
        $template = OfficialPdfTemplateRegistry::get($formCode);
        if (!$template['fillable']) {
            throw new RuntimeException('This official template does not contain fillable fields and requires a purpose-built renderer.');
        }

        $this->assertRuntimeReady();
        $this->assertTemporaryDirectory();

        $basePath = tempnam($this->temporaryDirectory, 'commandbridge_pdf_');
        if ($basePath === false) {
            throw new RuntimeException('Unable to create a temporary PDF export file.');
        }

        @unlink($basePath);
        $fdfPath = $basePath . '.fdf';
        $outputPath = $basePath . '.pdf';

        try {
            $fdf = $this->buildFdf($fields);
            if (file_put_contents($fdfPath, $fdf, LOCK_EX) === false) {
                throw new RuntimeException('Unable to prepare PDF form data.');
            }
            @chmod($fdfPath, 0600);

            $this->runCommand([
                $this->pdftkBinary,
                $template['path'],
                'fill_form',
                $fdfPath,
                'output',
                $outputPath,
                'flatten',
            ]);

            if (!is_file($outputPath) || filesize($outputPath) === 0) {
                throw new RuntimeException('The PDF export engine did not create an output file.');
            }
            @chmod($outputPath, 0600);

            return $outputPath;
        } catch (Throwable $e) {
            @unlink($outputPath);
            throw $e;
        } finally {
            @unlink($fdfPath);
        }
    }

    /**
     * Fills the same template once per page of data and joins the pages into one PDF.
     * Used by forms whose row count can exceed one page of the official template (e.g. ICS 211 check-ins).
     *
     * @param array<int, array<string, mixed>> $pages One field map per output page.
     * @return string Absolute path to a temporary flattened PDF. Caller must delete it after delivery.
     */
    public function createPaged(string $formCode, array $pages, ?array $keepPages = null): string
    {
        $pages = array_values($pages);
        $keepPages = $keepPages === null ? null : array_values($keepPages);
        if ($pages === []) {
            throw new InvalidArgumentException('At least one page of data is required.');
        }
        if (count($pages) === 1 && ($keepPages === null || ($keepPages[0] ?? '') === '')) {
            return $this->create($formCode, $pages[0]);
        }

        $parts = [];
        try {
            foreach ($pages as $i => $fields) {
                $part = $this->create($formCode, $fields);
                $range = $keepPages === null ? '' : trim((string)($keepPages[$i] ?? ''));
                if ($range !== '') {
                    // Keep only some pages of a multi-page template (e.g. the ICS 214 continuation page).
                    $part = $this->selectPages($part, $range);
                }
                $parts[] = $part;
            }
            if (count($parts) === 1) {
                $only = $parts[0];
                $parts = [];
                return $only;
            }

            $basePath = tempnam($this->temporaryDirectory, 'commandbridge_pdf_');
            if ($basePath === false) {
                throw new RuntimeException('Unable to create a temporary PDF export file.');
            }
            @unlink($basePath);
            $merged = $basePath . '.pdf';

            $command = array_merge([$this->pdftkBinary], $parts, ['cat', 'output', $merged]);
            $this->runCommand($command);
            if (!is_file($merged) || filesize($merged) === 0) {
                throw new RuntimeException('The PDF export engine did not create an output file.');
            }
            @chmod($merged, 0600);
            return $merged;
        } finally {
            foreach ($parts as $part) {
                @unlink($part);
            }
        }
    }

    /**
     * Returns a new temporary PDF holding only $range of $pdf ("1", "2-end", "1 3"); deletes $pdf.
     */
    public function selectPages(string $pdf, string $range): string
    {
        if (!preg_match('/^[0-9]+(-([0-9]+|end))?( [0-9]+(-([0-9]+|end))?)*$/', $range)) {
            @unlink($pdf);
            throw new InvalidArgumentException('Invalid page range.');
        }
        $this->assertTemporaryDirectory();
        $basePath = tempnam($this->temporaryDirectory, 'commandbridge_pdf_');
        if ($basePath === false) {
            @unlink($pdf);
            throw new RuntimeException('Unable to create a temporary PDF export file.');
        }
        @unlink($basePath);
        $out = $basePath . '.pdf';
        try {
            $this->runCommand(array_merge([$this->pdftkBinary, $pdf, 'cat'], explode(' ', $range), ['output', $out]));
            if (!is_file($out) || filesize($out) === 0) {
                throw new RuntimeException('The PDF export engine did not create an output file.');
            }
            @chmod($out, 0600);
            return $out;
        } finally {
            @unlink($pdf);
        }
    }

    /**
     * For a template without fillable fields (ICS 218): lays $overlayPdf (text on transparent pages drawn at the
     * template's coordinates) over copies of one page of the official template, one copy per overlay page.
     * $overlayPdf is deleted.
     */
    public function stampTemplate(string $formCode, string $overlayPdf, int $overlayPages, int $templatePage = 1): string
    {
        $template = OfficialPdfTemplateRegistry::get($formCode);
        $this->assertRuntimeReady();
        $this->assertTemporaryDirectory();
        $basePath = tempnam($this->temporaryDirectory, 'commandbridge_pdf_');
        if ($basePath === false) {
            @unlink($overlayPdf);
            throw new RuntimeException('Unable to create a temporary PDF export file.');
        }
        @unlink($basePath);
        $background = $basePath . '_bg.pdf';
        $out = $basePath . '.pdf';
        try {
            $page = (string)max(1, min($templatePage, (int)($template['pages'] ?? 1)));
            $this->runCommand(array_merge([$this->pdftkBinary, $template['path'], 'cat'], array_fill(0, max(1, $overlayPages), $page),
                ['output', $background]));
            $this->runCommand([$this->pdftkBinary, $background, 'multistamp', $overlayPdf, 'output', $out]);
            if (!is_file($out) || filesize($out) === 0) {
                throw new RuntimeException('The PDF export engine did not create an output file.');
            }
            @chmod($out, 0600);
            return $out;
        } finally {
            @unlink($background);
            @unlink($overlayPdf);
        }
    }

    /**
     * Appends the pages of $extraPdf (e.g. a continuation sheet) to $basePdf. Both inputs are deleted;
     * returns the path of a new temporary PDF.
     */
    public function appendPdf(string $basePdf, string $extraPdf): string
    {
        $this->assertTemporaryDirectory();
        $basePath = tempnam($this->temporaryDirectory, 'commandbridge_pdf_');
        if ($basePath === false) {
            throw new RuntimeException('Unable to create a temporary PDF export file.');
        }
        @unlink($basePath);
        $merged = $basePath . '.pdf';
        try {
            $this->runCommand([$this->pdftkBinary, $basePdf, $extraPdf, 'cat', 'output', $merged]);
            if (!is_file($merged) || filesize($merged) === 0) {
                throw new RuntimeException('The PDF export engine did not create an output file.');
            }
            @chmod($merged, 0600);
            return $merged;
        } finally {
            @unlink($basePdf);
            @unlink($extraPdf);
        }
    }

    /**
     * Sends a generated PDF as a download and always removes its temporary file.
     */
    public function sendDownload(string $pdfPath, string $filename): void
    {
        $this->sendPdf($pdfPath, $filename, false);
    }

    /**
     * Streams the generated PDF for browser preview and printing.
     */
    public function sendInline(string $pdfPath, string $filename): void
    {
        $this->sendPdf($pdfPath, $filename, true);
    }

    private function sendPdf(string $pdfPath, string $filename, bool $inline): void
    {
        if (!is_file($pdfPath) || !is_readable($pdfPath)) {
            throw new RuntimeException('The generated PDF is no longer available.');
        }
        // bin/form_snapshot.php (saving a copy of a form before it is revised): write the PDF to a file and stop.
        $capture = (string)($GLOBALS['cb_pdf_capture_path'] ?? '');
        if ($capture !== '' && PHP_SAPI === 'cli') {
            $ok = @copy($pdfPath, $capture);
            @unlink($pdfPath);
            while (ob_get_level() > 0) { ob_end_clean(); }
            exit($ok ? 0 : 6);
        }
        if (headers_sent()) {
            throw new RuntimeException('PDF output headers cannot be sent because output has already started.');
        }

        $safeFilename = preg_replace('/[^A-Za-z0-9._-]+/', '_', basename($filename)) ?: 'command_bridge_form.pdf';
        if (!str_ends_with(strtolower($safeFilename), '.pdf')) {
            $safeFilename .= '.pdf';
        }

        try {
            header('Content-Type: application/pdf');
            header('Content-Length: ' . (string)filesize($pdfPath));
            header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . $safeFilename . '"');
            header('X-Content-Type-Options: nosniff');
            readfile($pdfPath);
        } finally {
            @unlink($pdfPath);
        }
    }

    /**
     * @return array{
     *   ready: bool,
     *   execution_available: bool,
     *   engine_available: bool,
     *   pdftk_binary: string,
     *   temporary_directory: string,
     *   temporary_directory_writable: bool,
     *   registered_templates: int,
     *   fillable_templates: int,
     *   missing_templates: array<int, string>
     * }
     */
    public function status(): array
    {
        $templates = OfficialPdfTemplateRegistry::all();
        $missingTemplates = [];
        $fillableTemplates = 0;

        foreach ($templates as $template) {
            if ($template['fillable']) {
                $fillableTemplates++;
            }
            if (!is_file($template['path']) || !is_readable($template['path'])) {
                $missingTemplates[] = $template['file'];
            }
        }

        $executionAvailable = $this->canExecuteProcesses();
        $engineAvailable = false;
        if ($executionAvailable) {
            try {
                if (!str_contains($this->pdftkBinary, DIRECTORY_SEPARATOR)
                    || (is_file($this->pdftkBinary) && is_executable($this->pdftkBinary))) {
                    $this->runCommand([$this->pdftkBinary, '--version']);
                    $engineAvailable = true;
                }
            } catch (Throwable $e) {
                $engineAvailable = false;
            }
        }

        $temporaryDirectoryWritable = is_dir($this->temporaryDirectory) && is_writable($this->temporaryDirectory);

        return [
            'ready' => $executionAvailable && $engineAvailable && $temporaryDirectoryWritable && $missingTemplates === [],
            'execution_available' => $executionAvailable,
            'engine_available' => $engineAvailable,
            'pdftk_binary' => $this->pdftkBinary,
            'temporary_directory' => $this->temporaryDirectory,
            'temporary_directory_writable' => $temporaryDirectoryWritable,
            'registered_templates' => count($templates),
            'fillable_templates' => $fillableTemplates,
            'missing_templates' => $missingTemplates,
        ];
    }

    private function assertRuntimeReady(): void
    {
        if (!$this->canExecuteProcesses()) {
            throw new RuntimeException('The PDF export engine is not available. Configure pdftk and permit PHP to run the approved PDF export command.');
        }

        $this->assertTemporaryDirectory();

        if (str_contains($this->pdftkBinary, DIRECTORY_SEPARATOR)
            && (!is_file($this->pdftkBinary) || !is_executable($this->pdftkBinary))) {
            throw new RuntimeException('The configured PDF export binary is not executable.');
        }

        try {
            $this->runCommand([$this->pdftkBinary, '--version']);
        } catch (RuntimeException $e) {
            throw new RuntimeException('The configured PDF export engine could not be started.');
        }
    }

    private function assertTemporaryDirectory(): void
    {
        if (!is_dir($this->temporaryDirectory) || !is_writable($this->temporaryDirectory)) {
            throw new RuntimeException('The configured PDF temporary directory is not writable.');
        }
    }

    private function canExecuteProcesses(): bool
    {
        if (!function_exists('proc_open')) {
            return false;
        }

        $disabled = array_filter(array_map('trim', explode(',', (string)ini_get('disable_functions'))));
        return !in_array('proc_open', $disabled, true);
    }

    /**
     * @param array<int, string> $command
     */
    private function runCommand(array $command): void
    {
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = @proc_open($command, $descriptors, $pipes, null, null, ['bypass_shell' => true]);
        if (!is_resource($process)) {
            throw new RuntimeException('Unable to start the PDF export engine.');
        }

        fclose($pipes[0]);
        $standardOutput = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $standardError = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        if ($exitCode !== 0) {
            $detail = trim((string)$standardError);
            if ($detail === '') {
                $detail = trim((string)$standardOutput);
            }
            if ($detail !== '') {
                $detail = substr($detail, 0, 500);
            }
            throw new RuntimeException('The PDF export engine failed' . ($detail !== '' ? ': ' . $detail : '.'));
        }
    }

    /**
     * @param array<string, string|int|float|bool|array{value: string|int|float|bool, type?: 'text'|'name'}|null> $fields
     */
    private function buildFdf(array $fields): string
    {
        $entries = [];

        foreach ($fields as $fieldName => $field) {
            $fieldName = trim((string)$fieldName);
            if ($fieldName === '' || $field === null) {
                continue;
            }

            $type = 'text';
            $value = $field;
            if (is_array($field)) {
                $value = $field['value'] ?? '';
                $type = (string)($field['type'] ?? 'text');
            }

            if (!is_scalar($value)) {
                throw new InvalidArgumentException('PDF field values must be scalar values.');
            }
            if (!in_array($type, ['text', 'name'], true)) {
                throw new InvalidArgumentException('PDF field type must be text or name.');
            }

            $encodedValue = $type === 'name'
                ? $this->encodePdfName((string)$value)
                : $this->encodePdfText((string)$value);

            $entries[] = '<< /T ' . $this->encodePdfText($fieldName) . ' /V ' . $encodedValue . ' >>';
        }

        return "%FDF-1.2\n%\xE2\xE3\xCF\xD3\n1 0 obj\n<< /FDF << /Fields [\n"
            . implode("\n", $entries)
            . "\n] >> >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n";
    }

    private function encodePdfText(string $value): string
    {
        $value = str_replace(["\r\n", "\r"], "\n", $value);

        if (function_exists('mb_convert_encoding')) {
            $utf16 = mb_convert_encoding($value, 'UTF-16BE', 'UTF-8');
        } elseif (function_exists('iconv')) {
            $utf16 = iconv('UTF-8', 'UTF-16BE//IGNORE', $value);
        } else {
            throw new RuntimeException('PHP must provide mbstring or iconv to create PDF form data.');
        }

        if ($utf16 === false) {
            throw new RuntimeException('Unable to encode PDF form data.');
        }

        return '<FEFF' . strtoupper(bin2hex($utf16)) . '>';
    }

    private function encodePdfName(string $value): string
    {
        $value = trim($value);
        if ($value === '' || !preg_match('/^[A-Za-z0-9_.-]+$/', $value)) {
            throw new InvalidArgumentException('PDF checkbox and radio values must use a safe PDF name.');
        }

        return '/' . $value;
    }
}
