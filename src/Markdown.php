<?php

declare(strict_types=1);

namespace Cloude;

/**
 * Parsedown wrapper with support for a simple "key: value" YAML frontmatter.
 *
 * Required dependency: composer require erusev/parsedown
 */
class Markdown
{
    /**
     * Parses YAML frontmatter + Markdown body.
     *
     * @return array{
     *   meta: array<string,mixed>,
     *   html: string,
     *   paragraphs: array<int,string>,
     *   raw: string,
     *   description: string,
     *   noindex: bool
     * }
     */
    public static function parse(string $content): array
    {
        $meta = [];
        $body = $content;

        if (str_starts_with(trim($content), '---')) {
            $parts = preg_split('/^---\s*$/m', $content, 3);
            if (count($parts) >= 3) {
                $meta = self::parseYaml(trim($parts[1]));
                $body = trim($parts[2]);
            }
        }

        $bodyHtml = self::toHtml($body);
        $cleanBody = strip_tags($bodyHtml);
        $cleanBody = preg_replace('/\s+/', ' ', trim($cleanBody));

        $description = Str::upTo($cleanBody, '.');
        if (mb_strlen($description) < 60) {
            $description = Str::truncate($cleanBody, 155);
        }

        $noindex = (isset($meta['type']) && $meta['type'] === 'error');

        preg_match_all('/<(p|ul|h2)>.*?<\/\1>/s', $bodyHtml, $paragraphMatches);
        $paragraphs = $paragraphMatches[0];

        return [
            'meta'        => $meta,
            'html'        => $bodyHtml,
            'paragraphs'  => $paragraphs,
            'raw'         => $body,
            'description' => $meta['description'] ?? $description,
            'noindex'     => $noindex,
        ];
    }

    /**
     * Markdown -> HTML via Parsedown.
     *
     * @throws \RuntimeException if Parsedown is not installed.
     */
    public static function toHtml(string $md): string
    {
        if (!class_exists(\Parsedown::class)) {
            throw new \RuntimeException(
                'Cloude\\Markdown requires erusev/parsedown. Install it: composer require erusev/parsedown'
            );
        }
        return (new \Parsedown())->text($md);
    }

    /**
     * Minimal YAML parser: only supports single-line "key: value" pairs.
     * Handles booleans true/false and single/double-quoted values.
     *
     * @return array<string,mixed>
     */
    private static function parseYaml(string $yaml): array
    {
        $result = [];
        foreach (explode("\n", $yaml) as $line) {
            if (preg_match('/^([a-zA-Z_][a-zA-Z0-9_]*):\s*(.*)$/', trim($line), $m)) {
                $value = trim($m[2], "\"'");
                if ($value === 'true') {
                    $value = true;
                }
                if ($value === 'false') {
                    $value = false;
                }
                $result[$m[1]] = $value;
            }
        }
        return $result;
    }
}
