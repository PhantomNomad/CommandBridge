<?php
declare(strict_types=1);

/**
 * Tiny dependency-free PDF writer for plain text continuation sheets (Letter size, Helvetica).
 * Also provides Helvetica text measuring so callers can tell how much text fits in a form box.
 */
final class SimpleTextPdf
{
    /** Helvetica advance widths (1/1000 em) for ASCII 32..126. */
    private const W = [278,278,355,556,556,889,667,191,333,333,389,584,278,333,278,278,556,556,556,556,556,556,556,556,556,556,278,278,584,584,584,556,
        1015,667,667,722,722,667,611,778,722,278,500,667,556,833,722,778,667,778,722,667,611,722,667,944,667,667,611,278,278,278,469,556,
        333,556,556,500,556,556,278,556,556,222,222,500,222,833,556,556,556,556,333,500,278,556,500,722,500,500,500,334,260,334,584];

    public static function textWidth(string $text, float $fontSize, bool $bold = false): float
    {
        $sum = 0;
        foreach (self::chars($text) as $c) {
            $o = ord($c);
            $sum += ($o >= 32 && $o <= 126) ? self::W[$o - 32] : 556;
        }
        return $sum * $fontSize / 1000 * ($bold ? 1.06 : 1.0);
    }

    /** Convert UTF-8 to the single-byte WinAnsi range used by the base-14 fonts; unknown characters become '?'. */
    private static function toLatin(string $s): string
    {
        $s = str_replace(["\r\n", "\r"], "\n", $s);
        $s = strtr($s, ["\u{2018}" => "'", "\u{2019}" => "'", "\u{201C}" => '"', "\u{201D}" => '"', "\u{2013}" => '-', "\u{2014}" => '-', "\u{2026}" => '...', "\u{00A0}" => ' ']);
        if (function_exists('mb_convert_encoding')) {
            $out = @mb_convert_encoding($s, 'Windows-1252', 'UTF-8');
            if (is_string($out)) { return $out; }
        }
        $out = @iconv('UTF-8', 'Windows-1252//TRANSLIT//IGNORE', $s);
        return is_string($out) ? $out : preg_replace('/[^\x20-\x7E\n]/', '?', $s);
    }

    /** @return string[] */
    private static function chars(string $s): array { return str_split(self::toLatin($s)) ?: []; }

    /**
     * Greedy word wrap that honours existing line breaks.
     * @return string[] lines (UTF-8 in, UTF-8 out)
     */
    public static function wrap(string $text, float $maxWidth, float $fontSize, bool $bold = false): array
    {
        $lines = [];
        foreach (explode("\n", str_replace(["\r\n", "\r"], "\n", $text)) as $para) {
            $para = rtrim($para);
            if ($para === '') { $lines[] = ''; continue; }
            $cur = '';
            foreach (preg_split('/\s+/', $para) ?: [] as $word) {
                // A single word wider than the box is split by characters.
                while (self::textWidth($word, $fontSize, $bold) > $maxWidth && mb_strlen($word) > 1) {
                    $cut = mb_strlen($word);
                    while ($cut > 1 && self::textWidth(mb_substr($word, 0, $cut), $fontSize, $bold) > $maxWidth) { $cut--; }
                    if ($cur !== '') { $lines[] = $cur; $cur = ''; }
                    $lines[] = mb_substr($word, 0, $cut);
                    $word = mb_substr($word, $cut);
                }
                $try = $cur === '' ? $word : $cur . ' ' . $word;
                if ($cur !== '' && self::textWidth($try, $fontSize, $bold) > $maxWidth) { $lines[] = $cur; $cur = $word; }
                else { $cur = $try; }
            }
            $lines[] = $cur;
        }
        return $lines;
    }

    /**
     * @param array<int, array{heading?: string, text?: string}> $blocks
     * @param string[] $headerLines printed under the title on every page
     */
    public static function render(string $title, array $headerLines, array $blocks): string
    {
        $pw = 612; $ph = 792; $m = 50; $fs = 10; $lead = 12.5; $width = $pw - 2 * $m;
        $pages = []; $ops = ''; $y = 0; $pageNo = 0;

        $newPage = function () use (&$pages, &$ops, &$y, &$pageNo, $title, $headerLines, $pw, $ph, $m, $width) {
            if ($ops !== '') { $pages[] = $ops; }
            $ops = ''; $pageNo++;
            $y = $ph - $m;
            $ops .= self::text($m, $y - 14, 'F2', 14, $title);
            $y -= 32;
            foreach ($headerLines as $h) { $ops .= self::text($m, $y, 'F1', 10, $h); $y -= 13; }
            $ops .= sprintf("0.5 w %.2f %.2f m %.2f %.2f l S\n", $m, $y - 3, $m + $width, $y - 3);
            $y -= 20;
        };
        $newPage();

        foreach ($blocks as $b) {
            $heading = (string)($b['heading'] ?? '');
            if ($heading !== '') {
                if ($y < $m + 40) { $newPage(); }
                foreach (self::wrap($heading, $width, 11, true) as $hl) { $ops .= self::text($m, $y, 'F2', 11, $hl); $y -= 14; }
                $y -= 2;
            }
            foreach (self::wrap((string)($b['text'] ?? ''), $width, $fs) as $line) {
                if ($y < $m + 14) { $newPage(); }
                if ($line !== '') { $ops .= self::text($m, $y, 'F1', $fs, $line); }
                $y -= $lead;
            }
            $y -= 12;
        }
        $pages[] = $ops;

        $objs = [];
        $objs[1] = '<< /Type /Catalog /Pages 2 0 R >>';
        $kids = [];
        $n = 5;
        $pageObjs = [];
        foreach ($pages as $i => $content) {
            $pageId = $n++; $contentId = $n++;
            $kids[] = "$pageId 0 R";
            $total = count($pages);
            $content .= self::text($m, 28, 'F1', 8, 'ICS 209 continuation sheet - page ' . ($i + 1) . ' of ' . $total);
            $pageObjs[$pageId] = "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 $pw $ph] /Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents $contentId 0 R >>";
            $pageObjs[$contentId] = "<< /Length " . strlen($content) . " >>\nstream\n" . $content . "endstream";
        }
        $objs[2] = '<< /Type /Pages /Kids [' . implode(' ', $kids) . '] /Count ' . count($kids) . ' >>';
        $objs[3] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
        $objs[4] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>';
        foreach ($pageObjs as $id => $body) { $objs[$id] = $body; }
        ksort($objs);

        $out = "%PDF-1.4\n"; $offsets = [];
        foreach ($objs as $id => $body) { $offsets[$id] = strlen($out); $out .= "$id 0 obj\n$body\nendobj\n"; }
        $xref = strlen($out);
        $max = max(array_keys($objs));
        $out .= "xref\n0 " . ($max + 1) . "\n0000000000 65535 f \n";
        for ($i = 1; $i <= $max; $i++) { $out .= sprintf("%010d 00000 n \n", $offsets[$i] ?? 0); }
        $out .= "trailer\n<< /Size " . ($max + 1) . " /Root 1 0 R >>\nstartxref\n$xref\n%%EOF\n";
        return $out;
    }

    private static function text(float $x, float $y, string $font, float $size, string $s): string
    {
        $s = self::toLatin($s);
        $s = str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $s);
        return sprintf("BT /%s %.1f Tf %.2f %.2f Td (%s) Tj ET\n", $font, $size, $x, $y, $s);
    }
}
