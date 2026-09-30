<?php

declare(strict_types=1);

namespace Cloude\Markdown;

/**
 * Minimalist Markdown → HTML parser. Covers the subset typically used in
 * editorial content: ATX headings (#…######), paragraphs, unordered and
 * ordered lists, fenced code blocks (```), inline code, bold (** / __),
 * italic (* / _), links [text](url), images ![alt](src), blockquotes (>),
 * horizontal rules (--- or ***), hard line breaks, and GitHub-flavoured
 * tables with optional column alignment (`:---`, `:---:`, `---:`).
 *
 * NOT supported (by design): footnotes, definition lists,
 * reference-style links, setext headings (=== ---), nested lists.
 *
 * Two modes:
 *
 *   - `toHtml($md)` — for content YOU wrote (pages, docs). Inline HTML is
 *     passed through unescaped (Parsedown-compatible) and links take any
 *     address. Never feed it text a visitor, a customer or a model wrote:
 *     that text could carry `<script>` or a `javascript:` link.
 *   - `toHtml($md, safe: true)` — for text from outside. The text is escaped
 *     before anything else (so no tag or attribute can come from it), links
 *     keep their address only when it is relative or `http`, `https`,
 *     `mailto` or `tel`, and images only when relative or `http` / `https`.
 *     `Cloude\Markdown::toSafeHtml()` is the front door.
 *
 * In both modes every tag the inline step writes (code, images, links) is
 * held as an opaque token until the end, so a later pattern never reads
 * inside one: an image inside a link's text, or a code span inside an
 * address, cannot end up writing attributes. Content inside `code` and
 * ```fenced``` blocks is HTML-escaped.
 *
 * Table format expected:
 *
 *     | Header 1 | Header 2 | Header 3 |
 *     |---|:---:|---:|
 *     | left    | center  | right   |
 *     | row 2   | row 2   | row 2   |
 *
 * Each row on its own line. The separator row (`---`) is mandatory —
 * without it the lines parse as a regular paragraph (Parsedown's
 * behaviour).
 */
class Parser
{
    /** Marks a held tag in the text; stripped from the input, so a text cannot forge one. */
    private const MARK = "\x00";

    private const SAFE_LINK_SCHEMES  = ['http', 'https', 'mailto', 'tel'];
    private const SAFE_IMAGE_SCHEMES = ['http', 'https'];

    /**
     * @param bool $safe true for text from outside: nothing in it becomes a
     *                   tag, an attribute or a script address (see the class docblock)
     */
    public static function toHtml(string $md, bool $safe = false): string
    {
        $md = str_replace(["\r\n", "\r", self::MARK], ["\n", "\n", ''], $md);
        $blocks = self::parseBlocks(explode("\n", $md));
        return self::renderBlocks($blocks, $safe);
    }

    /**
     * @param array<int,string> $lines
     * @return array<int,array<int,mixed>>
     */
    private static function parseBlocks(array $lines): array
    {
        $blocks = [];
        $i = 0;
        $n = count($lines);

        while ($i < $n) {
            $line = $lines[$i];

            if (trim($line) === '') {
                $i++;
                continue;
            }

            // Fenced code block: ```[lang]
            if (preg_match('/^```(\w*)\s*$/', $line, $m)) {
                $lang = $m[1];
                $i++;
                $code = [];
                while ($i < $n && !preg_match('/^```\s*$/', $lines[$i])) {
                    $code[] = $lines[$i];
                    $i++;
                }
                if ($i < $n) {
                    $i++; // closing fence
                }
                $blocks[] = ['code', $lang, implode("\n", $code)];
                continue;
            }

            // ATX heading
            if (preg_match('/^(#{1,6})\s+(.+?)\s*#*\s*$/', $line, $m)) {
                $blocks[] = ['heading', strlen($m[1]), $m[2]];
                $i++;
                continue;
            }

            // Horizontal rule
            if (preg_match('/^\s*([-*_])(?:\s*\1){2,}\s*$/', $line)) {
                $blocks[] = ['hr'];
                $i++;
                continue;
            }

            // Blockquote
            if (preg_match('/^>\s?(.*)/', $line)) {
                $quote = [];
                while ($i < $n && preg_match('/^>\s?(.*)/', $lines[$i], $mq)) {
                    $quote[] = $mq[1];
                    $i++;
                }
                $blocks[] = ['quote', implode("\n", $quote)];
                continue;
            }

            // Unordered list
            if (preg_match('/^[-*+]\s+(.+)/', $line)) {
                $items = [];
                while ($i < $n && preg_match('/^[-*+]\s+(.+)/', $lines[$i], $mi)) {
                    $item = $mi[1];
                    $i++;
                    while ($i < $n && preg_match('/^\s{2,}(.+)/', $lines[$i], $mc)) {
                        $item .= "\n" . $mc[1];
                        $i++;
                    }
                    $items[] = $item;
                }
                $blocks[] = ['ul', $items];
                continue;
            }

            // Ordered list
            if (preg_match('/^\d+\.\s+(.+)/', $line)) {
                $items = [];
                while ($i < $n && preg_match('/^\d+\.\s+(.+)/', $lines[$i], $mi)) {
                    $item = $mi[1];
                    $i++;
                    while ($i < $n && preg_match('/^\s{2,}(.+)/', $lines[$i], $mc)) {
                        $item .= "\n" . $mc[1];
                        $i++;
                    }
                    $items[] = $item;
                }
                $blocks[] = ['ol', $items];
                continue;
            }

            // Table: header row + separator row (`---` cells with optional alignment colons)
            if (str_contains($line, '|') && self::isTableSeparator($lines[$i + 1] ?? '')) {
                $header = self::splitTableRow($line);
                $align  = self::tableAlignments($lines[$i + 1]);
                $i += 2;
                $rows = [];
                while ($i < $n && trim($lines[$i]) !== '' && str_contains($lines[$i], '|')) {
                    $rows[] = self::splitTableRow($lines[$i]);
                    $i++;
                }
                $blocks[] = ['table', $header, $align, $rows];
                continue;
            }

            // Paragraph: collect until blank line or block-starting line
            $para = [$line];
            $i++;
            while ($i < $n) {
                $next = $lines[$i];
                if (
                    trim($next) === ''
                    || preg_match('/^#{1,6}\s+/', $next)
                    || preg_match('/^```/', $next)
                    || preg_match('/^>\s?/', $next)
                    || preg_match('/^[-*+]\s+/', $next)
                    || preg_match('/^\d+\.\s+/', $next)
                    || preg_match('/^\s*([-*_])(?:\s*\1){2,}\s*$/', $next)
                    || (str_contains($next, '|') && self::isTableSeparator($lines[$i + 1] ?? ''))
                ) {
                    break;
                }
                $para[] = $next;
                $i++;
            }
            $blocks[] = ['paragraph', implode("\n", $para)];
        }

        return $blocks;
    }

    /**
     * @param array<int,array<int,mixed>> $blocks
     */
    private static function renderBlocks(array $blocks, bool $safe = false): string
    {
        $out = '';
        foreach ($blocks as $b) {
            switch ($b[0]) {
                case 'heading':
                    /** @var int $level */
                    $level = $b[1];
                    /** @var string $text */
                    $text = $b[2];
                    $out .= '<h' . $level . '>' . self::inline($text, $safe) . '</h' . $level . '>' . "\n";
                    break;

                case 'paragraph':
                    /** @var string $text */
                    $text = $b[1];
                    $out .= '<p>' . self::inline($text, $safe) . '</p>' . "\n";
                    break;

                case 'ul':
                    $out .= "<ul>\n";
                    /** @var array<int,string> $items */
                    $items = $b[1];
                    foreach ($items as $item) {
                        $out .= '<li>' . self::inline($item, $safe) . '</li>' . "\n";
                    }
                    $out .= "</ul>\n";
                    break;

                case 'ol':
                    $out .= "<ol>\n";
                    /** @var array<int,string> $items */
                    $items = $b[1];
                    foreach ($items as $item) {
                        $out .= '<li>' . self::inline($item, $safe) . '</li>' . "\n";
                    }
                    $out .= "</ol>\n";
                    break;

                case 'code':
                    /** @var string $lang */
                    $lang = $b[1];
                    /** @var string $body */
                    $body = $b[2];
                    $cls = $lang !== '' ? ' class="language-' . htmlspecialchars($lang, ENT_QUOTES, 'UTF-8') . '"' : '';
                    $out .= '<pre><code' . $cls . '>'
                        . htmlspecialchars($body, ENT_NOQUOTES, 'UTF-8')
                        . "</code></pre>\n";
                    break;

                case 'quote':
                    /** @var string $body */
                    $body = $b[1];
                    $out .= "<blockquote>\n" . self::toHtml($body, $safe) . "</blockquote>\n";
                    break;

                case 'hr':
                    $out .= "<hr>\n";
                    break;

                case 'table':
                    /** @var array<int,string> $header */
                    $header = $b[1];
                    /** @var array<int,string> $align */
                    $align = $b[2];
                    /** @var array<int,array<int,string>> $rows */
                    $rows = $b[3];

                    $out .= "<table>\n<thead>\n<tr>";
                    foreach ($header as $idx => $cell) {
                        $out .= '<th' . self::tableAlignAttr($align[$idx] ?? 'left') . '>'
                            . self::inline($cell, $safe) . '</th>';
                    }
                    $out .= "</tr>\n</thead>\n<tbody>\n";
                    foreach ($rows as $row) {
                        $out .= '<tr>';
                        foreach ($row as $idx => $cell) {
                            $out .= '<td' . self::tableAlignAttr($align[$idx] ?? 'left') . '>'
                                . self::inline($cell, $safe) . '</td>';
                        }
                        $out .= "</tr>\n";
                    }
                    $out .= "</tbody>\n</table>\n";
                    break;
            }
        }
        return $out;
    }

    /**
     * Detects a table-separator row: each cell is `---`, `:---`, `---:`
     * or `:---:` (with at least 3 dashes). Surrounding `|` chars are
     * tolerated, as is leading/trailing whitespace.
     */
    private static function isTableSeparator(string $line): bool
    {
        $line = trim($line);
        if ($line === '' || !str_contains($line, '-')) {
            return false;
        }
        // Strip outer | chars
        $line = trim($line, '|');
        $cells = preg_split('/\s*\|\s*/', $line) ?: [];
        if ($cells === []) {
            return false;
        }
        foreach ($cells as $cell) {
            $cell = trim($cell);
            if (preg_match('/^:?-{3,}:?$/', $cell) !== 1) {
                return false;
            }
        }
        return true;
    }

    /**
     * @return array<int,'left'|'center'|'right'>
     */
    private static function tableAlignments(string $separator): array
    {
        $line  = trim(trim($separator), '|');
        $cells = preg_split('/\s*\|\s*/', $line) ?: [];
        return array_map(static function (string $cell): string {
            $cell = trim($cell);
            $left  = str_starts_with($cell, ':');
            $right = str_ends_with($cell, ':');
            return match (true) {
                $left && $right => 'center',
                $right          => 'right',
                default         => 'left',
            };
        }, $cells);
    }

    /**
     * Splits a table row on `|`, trimming the optional leading/trailing
     * pipe and surrounding whitespace.
     *
     * @return array<int,string>
     */
    private static function splitTableRow(string $line): array
    {
        $line = trim($line);
        if (str_starts_with($line, '|')) {
            $line = substr($line, 1);
        }
        if (str_ends_with($line, '|')) {
            $line = substr($line, 0, -1);
        }
        return array_map('trim', explode('|', $line));
    }

    private static function tableAlignAttr(string $align): string
    {
        return $align === 'left' ? '' : ' style="text-align:' . $align . '"';
    }

    /**
     * Inline transformations. Every tag written here — code, images, links —
     * is swapped for a token and put back at the end, so no later pattern
     * reads inside one. In safe mode the text is escaped first: what follows
     * works on entities, and the only tags are the ones written here.
     */
    private static function inline(string $text, bool $safe = false): string
    {
        if ($safe) {
            $text = htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }
        $tokens = [];
        $hold = static function (string $html) use (&$tokens): string {
            $tokens[] = $html;
            return self::MARK . (count($tokens) - 1) . self::MARK;
        };
        // An attribute's value: already escaped in safe mode, escaped here otherwise.
        $attr = static fn (string $value): string => $safe ? $value : htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
        // A title's quotes are entities once the text is escaped.
        $quote = $safe ? '&quot;' : '"';
        $title = '(?:\s+' . $quote . '((?:(?!' . $quote . ')[^' . self::MARK . '])*)' . $quote . ')?';

        $text = (string) preg_replace_callback(
            '/`([^`]+)`/',
            static fn (array $m): string => $hold('<code>' . ($safe ? $m[1] : htmlspecialchars($m[1], ENT_NOQUOTES, 'UTF-8')) . '</code>'),
            $text,
        );

        // Images: ![alt](src "title"?). Neither the alt text nor the address holds a token.
        $text = (string) preg_replace_callback(
            '/!\[([^\]' . self::MARK . ']*)\]\(([^)\s' . self::MARK . ']+)' . $title . '\)/',
            static function (array $m) use ($hold, $attr, $safe): string {
                if ($safe && !self::allowed($m[2], self::SAFE_IMAGE_SCHEMES)) {
                    return $m[1];
                }
                $titleAttr = isset($m[3]) ? ' title="' . $attr($m[3]) . '"' : '';
                return $hold('<img src="' . $attr($m[2]) . '" alt="' . $attr($m[1]) . '"' . $titleAttr . '>');
            },
            $text,
        );

        // Links: [text](url "title"?). The text stays in place, between the two
        // tokens of its tag, so bold, italic and code still apply to it.
        $text = (string) preg_replace_callback(
            '/\[([^\]]+)\]\(([^)\s' . self::MARK . ']+)' . $title . '\)/',
            static function (array $m) use ($hold, $attr, $safe): string {
                if ($safe && !self::allowed($m[2], self::SAFE_LINK_SCHEMES)) {
                    return $m[1];
                }
                $titleAttr = isset($m[3]) ? ' title="' . $attr($m[3]) . '"' : '';
                return $hold('<a href="' . $attr($m[2]) . '"' . $titleAttr . '>') . $m[1] . $hold('</a>');
            },
            $text,
        );

        // Bold (must come before italic so ** isn't eaten as two *).
        // Interior allows a single '*' as long as it's not followed by
        // another one — otherwise nested italics like
        //   **Jabalí (*Sus scrofa*)**
        // would fail to match the bold and leave literal '**' behind.
        $text = (string) preg_replace('/\*\*((?:[^*\n]|\*(?!\*))+)\*\*/', '<strong>$1</strong>', $text);
        $text = (string) preg_replace('/__((?:[^_\n]|_(?!_))+)__/', '<strong>$1</strong>', $text);

        // Italic — avoid matching * inside words ("foo*bar*baz") to reduce false positives.
        $text = (string) preg_replace('/(?<![\w*])\*([^*\n]+)\*(?![\w*])/', '<em>$1</em>', $text);
        $text = (string) preg_replace('/(?<![\w_])_([^_\n]+)_(?![\w_])/', '<em>$1</em>', $text);

        // Hard line break: trailing two spaces, or backslash + newline.
        $text = str_replace("  \n", "<br>\n", $text);
        $text = (string) preg_replace("/\\\\\n/", "<br>\n", $text);

        return (string) preg_replace_callback(
            '/' . self::MARK . '(\d+)' . self::MARK . '/',
            static fn (array $m): string => $tokens[(int) $m[1]] ?? '',
            $text,
        );
    }

    /**
     * Safe mode: whether an address, escaped as it goes in the attribute, is
     * relative or has one of the schemes. It is read as a browser reads it —
     * entities decoded, leading control characters and spaces dropped, tabs
     * and line breaks ignored — so `java&#9;script:` does not pass as relative.
     *
     * @param list<string> $schemes
     */
    private static function allowed(string $escaped, array $schemes): bool
    {
        $url = html_entity_decode($escaped, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $url = ltrim(str_replace(["\t", "\n", "\r"], '', $url), "\x00..\x20");
        if (preg_match('/^([a-zA-Z][a-zA-Z0-9+.\-]*):/', $url, $m) !== 1) {
            return true;
        }
        return in_array(strtolower($m[1]), $schemes, true);
    }
}
