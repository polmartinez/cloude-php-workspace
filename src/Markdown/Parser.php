<?php

declare(strict_types=1);

namespace Cloude\Markdown;

/**
 * Minimalist Markdown → HTML parser. Covers the subset typically used in
 * editorial content: ATX headings (#…######), paragraphs, unordered and
 * ordered lists, fenced code blocks (```), inline code, bold (** / __),
 * italic (* / _), links [text](url), images ![alt](src), blockquotes (>),
 * horizontal rules (--- or ***), and hard line breaks.
 *
 * NOT supported (by design): tables, footnotes, definition lists,
 * reference-style links, setext headings (=== ---), nested lists.
 *
 * Inline HTML is passed through unescaped (Parsedown-compatible). Content
 * inside `code` and ```fenced``` blocks is HTML-escaped.
 */
class Parser
{
    public static function toHtml(string $md): string
    {
        $md = str_replace(["\r\n", "\r"], "\n", $md);
        $blocks = self::parseBlocks(explode("\n", $md));
        return self::renderBlocks($blocks);
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
    private static function renderBlocks(array $blocks): string
    {
        $out = '';
        foreach ($blocks as $b) {
            switch ($b[0]) {
                case 'heading':
                    /** @var int $level */
                    $level = $b[1];
                    /** @var string $text */
                    $text = $b[2];
                    $out .= '<h' . $level . '>' . self::inline($text) . '</h' . $level . '>' . "\n";
                    break;

                case 'paragraph':
                    /** @var string $text */
                    $text = $b[1];
                    $out .= '<p>' . self::inline($text) . '</p>' . "\n";
                    break;

                case 'ul':
                    $out .= "<ul>\n";
                    /** @var array<int,string> $items */
                    $items = $b[1];
                    foreach ($items as $item) {
                        $out .= '<li>' . self::inline($item) . '</li>' . "\n";
                    }
                    $out .= "</ul>\n";
                    break;

                case 'ol':
                    $out .= "<ol>\n";
                    /** @var array<int,string> $items */
                    $items = $b[1];
                    foreach ($items as $item) {
                        $out .= '<li>' . self::inline($item) . '</li>' . "\n";
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
                    $out .= "<blockquote>\n" . self::toHtml($body) . "</blockquote>\n";
                    break;

                case 'hr':
                    $out .= "<hr>\n";
                    break;
            }
        }
        return $out;
    }

    /**
     * Inline transformations. Inline code is extracted first so its contents
     * aren't touched by other patterns; placeholders are restored at the end.
     */
    private static function inline(string $text): string
    {
        $codes = [];
        $text = (string) preg_replace_callback(
            '/`([^`]+)`/',
            static function (array $m) use (&$codes): string {
                $codes[] = '<code>' . htmlspecialchars($m[1], ENT_NOQUOTES, 'UTF-8') . '</code>';
                return "\x00" . (count($codes) - 1) . "\x00";
            },
            $text,
        );

        // Images: ![alt](src "title"?)
        $text = (string) preg_replace_callback(
            '/!\[([^\]]*)\]\(([^)\s]+)(?:\s+"([^"]*)")?\)/',
            static function (array $m): string {
                $alt = htmlspecialchars($m[1], ENT_QUOTES, 'UTF-8');
                $src = htmlspecialchars($m[2], ENT_QUOTES, 'UTF-8');
                $title = isset($m[3]) ? ' title="' . htmlspecialchars($m[3], ENT_QUOTES, 'UTF-8') . '"' : '';
                return '<img src="' . $src . '" alt="' . $alt . '"' . $title . '>';
            },
            $text,
        );

        // Links: [text](url "title"?)
        $text = (string) preg_replace_callback(
            '/\[([^\]]+)\]\(([^)\s]+)(?:\s+"([^"]*)")?\)/',
            static function (array $m): string {
                $href = htmlspecialchars($m[2], ENT_QUOTES, 'UTF-8');
                $title = isset($m[3]) ? ' title="' . htmlspecialchars($m[3], ENT_QUOTES, 'UTF-8') . '"' : '';
                return '<a href="' . $href . '"' . $title . '>' . $m[1] . '</a>';
            },
            $text,
        );

        // Bold (must come before italic so ** isn't eaten as two *)
        $text = (string) preg_replace('/\*\*([^*\n]+)\*\*/', '<strong>$1</strong>', $text);
        $text = (string) preg_replace('/__([^_\n]+)__/', '<strong>$1</strong>', $text);

        // Italic — avoid matching * inside words ("foo*bar*baz") to reduce false positives.
        $text = (string) preg_replace('/(?<![\w*])\*([^*\n]+)\*(?![\w*])/', '<em>$1</em>', $text);
        $text = (string) preg_replace('/(?<![\w_])_([^_\n]+)_(?![\w_])/', '<em>$1</em>', $text);

        // Hard line break: trailing two spaces, or backslash + newline.
        $text = str_replace("  \n", "<br>\n", $text);
        $text = (string) preg_replace("/\\\\\n/", "<br>\n", $text);

        $text = (string) preg_replace_callback(
            "/\x00(\d+)\x00/",
            static fn (array $m): string => $codes[(int) $m[1]],
            $text,
        );

        return $text;
    }
}
