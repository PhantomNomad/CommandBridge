<?php
declare(strict_types=1);

/**
 * In-app help.
 *
 * Every page gets a "Help" button (render_header). Its help text is a Markdown file under app/help/topics/ whose path
 * mirrors the page: /admin/smtp.php -> help/topics/admin/smtp.md, /forms/ics204/index.php -> forms/ics204/index.md.
 * The text is shown in a side panel (help/view.php?topic=...&panel=1) or full page (help/view.php?topic=...).
 * Apache never serves the .md files directly (the site denies *.md); they are read here and rendered with a small,
 * escaping Markdown converter, so help files cannot inject HTML or script.
 *
 * Writing help: see docs/DEVELOPMENT.md ("In-app help").
 */

const CB_HELP_DIR = __DIR__ . '/../help/topics';

/** Pages that never get their own help file; they use the help of the page they belong to. */
const CB_HELP_SKIP_PAGES = ['pdf.php', 'pdf_preview.php', 'help.php'];

/** Help topics anyone may read without signing in. */
const CB_HELP_PUBLIC_TOPICS = ['login'];

/** Valid topic names: lowercase path segments, e.g. "admin/smtp" or "forms/ics204/index". */
function cb_help_topic_is_valid(string $topic): bool
{
    return (bool)preg_match('#^[a-z0-9_]+(/[a-z0-9_]+){0,3}$#', $topic);
}

function cb_help_topic_file(string $topic): ?string
{
    if (!cb_help_topic_is_valid($topic)) {
        return null;
    }
    $file = CB_HELP_DIR . '/' . $topic . '.md';
    return is_file($file) ? $file : null;
}

/** The help topic for the page being shown: derived from its path, falling back to its folder's index page. */
function cb_help_topic_for_current_page(): string
{
    $script = (string)($_SERVER['SCRIPT_NAME'] ?? '');
    $base = defined('APP_BASE_URL') ? rtrim((string)APP_BASE_URL, '/') : '';
    if ($base !== '' && str_starts_with($script, $base)) {
        $script = substr($script, strlen($base));
    }
    $topic = strtolower(preg_replace('#\.php$#', '', ltrim($script, '/')) ?? '');
    if (cb_help_topic_file($topic) === null) {
        $index = preg_replace('#/[a-z0-9_]+$#', '/index', $topic) ?? '';
        if ($index !== $topic && cb_help_topic_file($index) !== null) {
            return $index;
        }
    }
    return cb_help_topic_is_valid($topic) ? $topic : 'index';
}

/** @return array{title:string, html:string, found:bool} */
function cb_help_load(string $topic): array
{
    $file = cb_help_topic_file($topic);
    if ($file === null) {
        return [
            'title' => 'Help',
            'html' => '<p>There is no help for this page yet.</p><p><a href="' . cb_help_e(cb_help_url('index')) . '" data-help-topic="index">See all help topics</a></p>',
            'found' => false,
        ];
    }
    $markdown = (string)file_get_contents($file);
    $title = 'Help';
    if (preg_match('/^#\s+(.+)$/m', $markdown, $m)) {
        $title = trim($m[1]);
    }
    return ['title' => $title, 'html' => cb_help_markdown_to_html($markdown), 'found' => true];
}

function cb_help_url(string $topic, bool $panel = false): string
{
    $base = defined('APP_BASE_URL') ? rtrim((string)APP_BASE_URL, '/') : '';
    [$name, $anchor] = array_pad(explode('#', $topic, 2), 2, '');
    return $base . '/help/view.php?topic=' . str_replace('%2F', '/', rawurlencode($name)) . ($panel ? '&panel=1' : '') . ($anchor !== '' ? '#' . rawurlencode($anchor) : '');
}

function cb_help_e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Lowercase, hyphenated anchor for a heading: "Microsoft 365 (Graph)" -> "microsoft-365-graph". */
function cb_help_slug(string $text): string
{
    $slug = strtolower(trim(preg_replace('/[^A-Za-z0-9]+/', '-', $text) ?? '', '-'));
    return $slug !== '' ? $slug : 'section';
}

/**
 * Inline Markdown on already-escaped text: `code`, **bold**, *italic*, [text](url).
 * Links may be https://, http://, mailto:, #anchor, a relative app path (/admin/smtp.php) or help:topic#anchor.
 */
function cb_help_inline(string $text): string
{
    $codes = [];
    $text = preg_replace_callback('/`([^`]+)`/', static function (array $m) use (&$codes): string {
        $codes[] = '<code>' . cb_help_e($m[1]) . '</code>';
        return "\x01" . (count($codes) - 1) . "\x01";
    }, $text) ?? $text;

    $text = cb_help_e($text);

    $text = preg_replace_callback('/\[([^\]]+)\]\(([^)\s]+)\)/', static function (array $m): string {
        $label = $m[1];
        $url = html_entity_decode($m[2], ENT_QUOTES, 'UTF-8');
        if (str_starts_with($url, 'help:')) {
            $topic = substr($url, 5);
            [$name] = explode('#', $topic, 2);
            if (!cb_help_topic_is_valid($name)) {
                return $label;
            }
            return '<a href="' . cb_help_e(cb_help_url($topic)) . '" data-help-topic="' . cb_help_e($topic) . '">' . $label . '</a>';
        }
        if (preg_match('#^(https?://|mailto:)#i', $url)) {
            return '<a href="' . cb_help_e($url) . '" target="_blank" rel="noopener noreferrer">' . $label . '</a>';
        }
        if (preg_match('#^(\#[A-Za-z0-9_-]+|/[A-Za-z0-9_./?=&-]*)$#', $url)) {
            return '<a href="' . cb_help_e($url) . '">' . $label . '</a>';
        }
        return $label;   // anything else (javascript:, data:, ...) becomes plain text
    }, $text) ?? $text;

    $text = preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', $text) ?? $text;
    $text = preg_replace('/(?<![\w*])\*(?!\s)(.+?)(?<!\s)\*(?![\w*])/', '<em>$1</em>', $text) ?? $text;

    return preg_replace_callback("/\x01(\\d+)\x01/", static fn(array $m): string => $codes[(int)$m[1]] ?? '', $text) ?? $text;
}

/**
 * A small Markdown subset: # headings (with anchors), paragraphs, - and 1. lists (one level), ``` code blocks,
 * | tables |, > notes (a note starting with "Warning:" is shown as a warning), --- rules, and the inline formats above.
 * All text is escaped; raw HTML in a help file is shown as text.
 */
function cb_help_markdown_to_html(string $markdown): string
{
    $lines = preg_split('/\r\n|\r|\n/', $markdown) ?: [];
    $html = [];
    $paragraph = [];
    $list = null;        // 'ul' | 'ol'
    $listItems = [];
    $listStart = 1;
    $usedSlugs = [];

    $flushParagraph = static function () use (&$paragraph, &$html): void {
        if ($paragraph) {
            $html[] = '<p>' . cb_help_inline(implode(' ', $paragraph)) . '</p>';
            $paragraph = [];
        }
    };
    $flushList = static function () use (&$list, &$listItems, &$listStart, &$html): void {
        if ($list !== null) {
            $open = $list === 'ol' && $listStart !== 1 ? '<ol start="' . $listStart . '">' : '<' . $list . '>';
            $html[] = $open . implode('', array_map(static fn($i) => '<li>' . cb_help_inline($i) . '</li>', $listItems)) . '</' . $list . '>';
            $list = null;
            $listItems = [];
        }
    };

    $count = count($lines);
    for ($i = 0; $i < $count; $i++) {
        $line = $lines[$i];
        $trim = trim($line);

        if (str_starts_with($trim, '```')) {                       // code block
            $flushParagraph(); $flushList();
            $code = [];
            for ($i++; $i < $count && !str_starts_with(trim($lines[$i]), '```'); $i++) {
                $code[] = $lines[$i];
            }
            $html[] = '<pre><code>' . cb_help_e(implode("\n", $code)) . '</code></pre>';
            continue;
        }
        if ($trim === '') {
            $flushParagraph(); $flushList();
            continue;
        }
        if (preg_match('/^(#{1,4})\s+(.+)$/', $trim, $m)) {         // heading
            $flushParagraph(); $flushList();
            $level = strlen($m[1]);
            $slug = cb_help_slug($m[2]);
            $base = $slug;
            for ($n = 2; isset($usedSlugs[$slug]); $n++) { $slug = $base . '-' . $n; }
            $usedSlugs[$slug] = true;
            $html[] = '<h' . $level . ' id="' . cb_help_e($slug) . '">' . cb_help_inline($m[2]) . '</h' . $level . '>';
            continue;
        }
        if (preg_match('/^(-{3,}|\*{3,})$/', $trim)) {              // rule
            $flushParagraph(); $flushList();
            $html[] = '<hr>';
            continue;
        }
        if (str_starts_with($trim, '|')) {                          // table
            $flushParagraph(); $flushList();
            $rows = [];
            for (; $i < $count && str_starts_with(trim($lines[$i]), '|'); $i++) {
                $rows[] = array_map('trim', explode('|', trim(trim($lines[$i]), '|')));
            }
            $i--;
            $out = '<div class="help-table-wrap"><table class="help-table">';
            foreach ($rows as $r => $cells) {
                if ($r === 1 && preg_match('/^:?-+:?$/', $cells[0] ?? '')) {
                    continue;                                       // header separator row
                }
                $tag = $r === 0 ? 'th' : 'td';
                $out .= '<tr>' . implode('', array_map(static fn($c) => "<$tag>" . cb_help_inline($c) . "</$tag>", $cells)) . '</tr>';
            }
            $html[] = $out . '</table></div>';
            continue;
        }
        if (str_starts_with($trim, '>')) {                          // note / warning
            $flushParagraph(); $flushList();
            $note = [];
            for (; $i < $count && str_starts_with(trim($lines[$i]), '>'); $i++) {
                $note[] = ltrim(substr(trim($lines[$i]), 1));
            }
            $i--;
            $text = implode(' ', $note);
            $class = preg_match('/^\*{0,2}(Warning|Important)\b/i', $text) ? 'help-note help-warning' : 'help-note';
            $html[] = '<div class="' . $class . '">' . cb_help_inline($text) . '</div>';
            continue;
        }
        if (preg_match('/^([-*]|\d+[.)])\s+(.+)$/', $trim, $m)) {   // list item
            $flushParagraph();
            $type = ctype_digit($m[1][0]) ? 'ol' : 'ul';
            if ($list !== $type) { $flushList(); $list = $type; $listStart = $type === 'ol' ? max(1, (int)$m[1]) : 1; }
            $listItems[] = $m[2];
            continue;
        }
        if ($list !== null && preg_match('/^\s{2,}\S/', $line)) {   // continuation of a list item
            $listItems[count($listItems) - 1] .= ' ' . $trim;
            continue;
        }
        $flushList();
        $paragraph[] = $trim;
    }
    $flushParagraph(); $flushList();
    return implode("\n", $html);
}

/**
 * Pages that should have a help file: every page that calls render_header(), except includes and CB_HELP_SKIP_PAGES.
 * Used by the self-check to catch new pages without help. @return list<string> topics
 */
function cb_help_expected_topics(): array
{
    $root = realpath(__DIR__ . '/..');
    if ($root === false) {
        return [];
    }
    $topics = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
        $path = (string)$file;
        if (!str_ends_with($path, '.php') || in_array(basename($path), CB_HELP_SKIP_PAGES, true)) {
            continue;
        }
        $rel = str_replace('\\', '/', substr($path, strlen($root) + 1));
        if (preg_match('#^(includes|vendor|bin|api|help|storage)/#', $rel)) {
            continue;
        }
        if (!str_contains((string)file_get_contents($path), 'render_header(')) {
            continue;
        }
        $topics[] = strtolower(substr($rel, 0, -4));
    }
    sort($topics);
    return $topics;
}

/** All help files, for the Help index. @return list<array{topic:string,title:string}> */
function cb_help_all_topics(): array
{
    $out = [];
    if (!is_dir(CB_HELP_DIR)) {
        return $out;
    }
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(CB_HELP_DIR, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
        $path = str_replace('\\', '/', (string)$file);
        if (!str_ends_with($path, '.md')) {
            continue;
        }
        $topic = substr($path, strlen(str_replace('\\', '/', CB_HELP_DIR)) + 1, -3);
        if (!cb_help_topic_is_valid($topic) || $topic === 'index') {
            continue;
        }
        $title = preg_match('/^#\s+(.+)$/m', (string)file_get_contents($path), $m) ? trim($m[1]) : $topic;
        $out[] = ['topic' => $topic, 'title' => $title];
    }
    usort($out, static fn($a, $b) => strcmp($a['topic'], $b['topic']));
    return $out;
}
