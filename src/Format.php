<?php

declare(strict_types=1);

namespace Cloude;

/**
 * One-stop format conversion. Each top-level method dispatches by input type:
 * a string is decoded into an array, an array is encoded back into a string.
 *
 *   Format::json('{"a":1}');         // → ['a' => 1]
 *   Format::json(['a' => 1]);        // → '{"a":1}'
 *   Format::yaml("a: 1\nb: true");   // → ['a' => '1', 'b' => true]
 *   Format::yaml(['a' => 1]);        // → "a: 1\n"
 *   Format::markdown("# Hi");        // → "<h1>Hi</h1>\n"
 *
 * For dependency injection or when you need a guaranteed return type, use the
 * explicit helpers (jsonDecode / jsonEncode / yamlDecode / yamlEncode).
 *
 * YAML support is intentionally minimal: a flat "key: value" subset matching
 * the parser used for markdown frontmatter. Nested structures are not
 * supported — encoding one throws InvalidArgumentException; if you need
 * nesting, use JSON instead.
 */
class Format
{
    // ── JSON ────────────────────────────────────────────────────────────────

    /**
     * Dispatch: string → array (decode), array → JSON string (encode).
     *
     * @return array<mixed>|string
     */
    public static function json(string|array $input, bool $pretty = false): array|string
    {
        return is_array($input) ? self::jsonEncode($input, $pretty) : self::jsonDecode($input);
    }

    /**
     * Decodes a JSON string. Throws JsonException on invalid JSON.
     *
     * @return array<mixed>
     */
    public static function jsonDecode(string $json): array
    {
        if ($json === '') {
            return [];
        }
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            throw new \JsonException('JSON did not decode into an array (got ' . gettype($decoded) . ')');
        }
        return $decoded;
    }

    /**
     * Encodes data as JSON. Defaults to UNESCAPED_UNICODE | UNESCAPED_SLASHES;
     * pass $pretty = true for indented output. Throws JsonException on error.
     *
     * @param array<mixed> $data
     */
    public static function jsonEncode(array $data, bool $pretty = false): string
    {
        $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR;
        if ($pretty) {
            $flags |= JSON_PRETTY_PRINT;
        }
        return json_encode($data, $flags);
    }

    // ── YAML (flat, frontmatter-style) ──────────────────────────────────────

    /**
     * Dispatch: string → array (decode), array → YAML string (encode).
     *
     * @return array<string,mixed>|string
     */
    public static function yaml(string|array $input): array|string
    {
        return is_array($input) ? self::yamlEncode($input) : self::yamlDecode($input);
    }

    /**
     * Parses a flat "key: value" YAML body. Lines that don't match are
     * silently skipped. Booleans `true` / `false` are decoded; other values
     * stay as strings (the parser is intentionally permissive — callers
     * coerce types when they care).
     *
     * @return array<string,mixed>
     */
    public static function yamlDecode(string $yaml): array
    {
        $result = [];
        foreach (explode("\n", $yaml) as $line) {
            if (preg_match('/^([a-zA-Z_][a-zA-Z0-9_]*):\s*(.*)$/', trim($line), $m)) {
                $value = trim($m[2], "\"'");
                if ($value === 'true') {
                    $value = true;
                } elseif ($value === 'false') {
                    $value = false;
                }
                $result[$m[1]] = $value;
            }
        }
        return $result;
    }

    /**
     * Encodes a flat associative array as "key: value" YAML. Nested arrays
     * are not supported and raise InvalidArgumentException.
     *
     * @param array<string,mixed> $data
     */
    public static function yamlEncode(array $data): string
    {
        $out = '';
        foreach ($data as $key => $value) {
            if (!is_string($key) || !preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $key)) {
                throw new \InvalidArgumentException(
                    "YAML key '" . (string) $key . "' is not a simple identifier (the minimal parser only supports [A-Za-z_][A-Za-z0-9_]*)",
                );
            }
            if (is_array($value)) {
                throw new \InvalidArgumentException(
                    "YAML encoding does not support nested arrays (key '$key'); use JSON for nested data",
                );
            }
            $out .= $key . ': ' . self::yamlScalar($value) . "\n";
        }
        return $out;
    }

    /**
     * Formats a scalar for YAML output. Strings get double-quoted whenever
     * the unquoted form would be misread by the parser (contains `:`,
     * leading/trailing whitespace, or matches a reserved literal).
     */
    private static function yamlScalar(mixed $value): string
    {
        if ($value === null) {
            return '""';
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }
        if (!is_string($value)) {
            throw new \InvalidArgumentException(
                'YAML encoding does not support values of type ' . gettype($value),
            );
        }
        $needsQuotes = $value === ''
            || $value !== trim($value)
            || str_contains($value, ':')
            || str_contains($value, '#')
            || str_contains($value, "\n")
            || str_contains($value, '"')
            || in_array(strtolower($value), ['true', 'false', 'null', 'yes', 'no', '~'], true)
            || preg_match('/^-?\d+(\.\d+)?$/', $value) === 1;
        if ($needsQuotes) {
            return '"' . addcslashes($value, "\"\\") . '"';
        }
        return $value;
    }

    // ── XML ─────────────────────────────────────────────────────────────────

    /**
     * Dispatch: string → array (decode), array → XML string (encode).
     *
     * Encoding conventions (apply to both directions when sensible):
     *   - Keys prefixed with '@' are attributes:    ['@xmlns' => '...']
     *   - Key '#text' is the node's text content:   ['#text' => 'hi']
     *   - List arrays repeat the element:           ['url' => [['loc' => 'a'], ['loc' => 'b']]]
     *                                                → <url><loc>a</loc></url><url><loc>b</loc></url>
     *
     * @return array<mixed>|string
     */
    public static function xml(string|array $input, string $rootElement = 'root', bool $pretty = false): array|string
    {
        return is_array($input) ? self::xmlEncode($input, $rootElement, $pretty) : self::xmlDecode($input);
    }

    /**
     * Decodes an XML string into an array via SimpleXMLElement → JSON
     * round-trip. Attributes are merged into the element body; mixed
     * content (text + children) is approximated. Throws RuntimeException
     * on malformed XML.
     *
     * @return array<mixed>
     */
    public static function xmlDecode(string $xml): array
    {
        $prev = libxml_use_internal_errors(true);
        try {
            $sx = simplexml_load_string($xml);
            if ($sx === false) {
                $err = libxml_get_last_error();
                throw new \RuntimeException(
                    'Invalid XML' . ($err !== false ? ': ' . trim($err->message) : ''),
                );
            }
            $json = json_encode($sx);
            if ($json === false) {
                throw new \RuntimeException('XML → array conversion failed');
            }
            $arr = json_decode($json, true);
            return is_array($arr) ? $arr : [];
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($prev);
        }
    }

    /**
     * Encodes an array as an XML document. If the array has exactly one
     * top-level key and that key is a string, it becomes the root element
     * name; $rootElement is used as a fallback otherwise.
     *
     * @param array<mixed> $data
     */
    public static function xmlEncode(array $data, string $rootElement = 'root', bool $pretty = false): string
    {
        $rootName = $rootElement;
        $rootValue = $data;
        if (count($data) === 1) {
            $first = array_key_first($data);
            if (is_string($first)) {
                $rootName = $first;
                $rootValue = $data[$first];
            }
        }

        $dom = new \DOMDocument('1.0', 'UTF-8');
        $dom->formatOutput = $pretty;
        $root = $dom->createElement($rootName);
        $dom->appendChild($root);

        if (is_array($rootValue)) {
            self::xmlFillElement($dom, $root, $rootValue);
        } else {
            $root->appendChild($dom->createTextNode((string) $rootValue));
        }

        return $dom->saveXML() ?: '';
    }

    /**
     * @param array<mixed> $data
     */
    private static function xmlFillElement(\DOMDocument $dom, \DOMElement $parent, array $data): void
    {
        foreach ($data as $key => $value) {
            if (is_string($key) && str_starts_with($key, '@')) {
                $parent->setAttribute(substr($key, 1), (string) $value);
                continue;
            }
            if ($key === '#text') {
                $parent->appendChild($dom->createTextNode((string) $value));
                continue;
            }
            if (!is_string($key)) {
                throw new \InvalidArgumentException(
                    'XML encoding requires string keys at every level (got int key under <' . $parent->tagName . '>)',
                );
            }
            self::xmlAppend($dom, $parent, $key, $value);
        }
    }

    private static function xmlAppend(\DOMDocument $dom, \DOMElement $parent, string $name, mixed $value): void
    {
        if (is_array($value) && array_is_list($value)) {
            foreach ($value as $item) {
                self::xmlAppend($dom, $parent, $name, $item);
            }
            return;
        }
        $child = $dom->createElement($name);
        $parent->appendChild($child);
        if (is_array($value)) {
            self::xmlFillElement($dom, $child, $value);
            return;
        }
        if ($value !== null) {
            $child->appendChild($dom->createTextNode((string) $value));
        }
    }

    // ── Markdown ────────────────────────────────────────────────────────────

    /**
     * Renders a markdown body to HTML using the framework's in-house parser.
     * For frontmatter parsing + paragraph extraction, use Cloude\Markdown::parse.
     */
    public static function markdown(string $md): string
    {
        return Markdown\Parser::toHtml($md);
    }
}
