<?php

declare(strict_types=1);

namespace Cloude;

/**
 * Pragmatic JSON Schema validator. Covers the subset that matters for
 * validating MCP tool inputs, REST API request bodies, and config files.
 *
 * Supported keywords:
 *   - type (string|integer|number|boolean|array|object|null, or list)
 *   - required, properties, additionalProperties
 *   - enum
 *   - items, minItems, maxItems
 *   - minimum, maximum
 *   - minLength, maxLength, pattern
 *
 * NOT supported (out of scope, kept that way on purpose):
 *   - $ref / $defs, allOf / oneOf / anyOf, not, if/then/else
 *   - format, contentEncoding, contentMediaType
 *   - patternProperties, dependencies
 *   - schema meta-validation
 *
 * Validation collects every error and returns them as a list of strings.
 * Each error is prefixed with the dotted path to the offending node, so
 * "$args.country: missing required property 'country'" stays usable in
 * a CLI or HTTP error message.
 *
 *   $errors = JsonSchema::validate($args, $schema);
 *   if ($errors !== []) {
 *       throw new \InvalidArgumentException(implode('; ', $errors));
 *   }
 */
class JsonSchema
{
    /**
     * @param array<mixed> $schema
     * @return array<int,string>
     */
    public static function validate(mixed $data, array $schema, string $path = '$'): array
    {
        $errors = [];

        // type
        if (isset($schema['type'])) {
            $expected = is_array($schema['type']) ? $schema['type'] : [$schema['type']];
            $actual = self::typeOf($data);
            $ok = false;
            foreach ($expected as $t) {
                if ($actual === $t) {
                    $ok = true;
                    break;
                }
                // JSON's "number" admits integers too.
                if ($t === 'number' && $actual === 'integer') {
                    $ok = true;
                    break;
                }
                // PHP can't tell {} from [] — both decode to []. Treat empty
                // array as a valid empty object when the schema asks for one.
                if ($t === 'object' && $data === []) {
                    $ok = true;
                    break;
                }
            }
            if (!$ok) {
                $errors[] = "$path: expected " . implode('|', $expected) . ", got $actual";
                return $errors;
            }
        }

        // enum
        if (isset($schema['enum']) && is_array($schema['enum'])) {
            if (!in_array($data, $schema['enum'], true)) {
                $errors[] = "$path: value not in enum [" . implode(', ', array_map(self::stringify(...), $schema['enum'])) . ']';
            }
        }

        // string constraints
        if (is_string($data)) {
            if (isset($schema['minLength']) && mb_strlen($data) < (int) $schema['minLength']) {
                $errors[] = "$path: string shorter than minLength " . $schema['minLength'];
            }
            if (isset($schema['maxLength']) && mb_strlen($data) > (int) $schema['maxLength']) {
                $errors[] = "$path: string longer than maxLength " . $schema['maxLength'];
            }
            if (isset($schema['pattern']) && @preg_match('/' . str_replace('/', '\/', (string) $schema['pattern']) . '/u', $data) !== 1) {
                $errors[] = "$path: value does not match pattern '{$schema['pattern']}'";
            }
        }

        // numeric constraints
        if (is_int($data) || is_float($data)) {
            if (isset($schema['minimum']) && $data < $schema['minimum']) {
                $errors[] = "$path: less than minimum {$schema['minimum']}";
            }
            if (isset($schema['maximum']) && $data > $schema['maximum']) {
                $errors[] = "$path: greater than maximum {$schema['maximum']}";
            }
        }

        // object: required + properties + additionalProperties
        if (self::typeOf($data) === 'object' || ($data === [] && (($schema['type'] ?? null) === 'object'))) {
            $obj = (array) $data;
            foreach ($schema['required'] ?? [] as $req) {
                if (!array_key_exists($req, $obj)) {
                    $errors[] = "$path: missing required property '$req'";
                }
            }
            $props = $schema['properties'] ?? [];
            $additional = $schema['additionalProperties'] ?? true;
            foreach ($obj as $key => $value) {
                $childPath = $path . '.' . $key;
                if (isset($props[$key]) && is_array($props[$key])) {
                    $errors = array_merge($errors, self::validate($value, $props[$key], $childPath));
                } elseif ($additional === false) {
                    $errors[] = "$childPath: additional property not allowed";
                } elseif (is_array($additional)) {
                    $errors = array_merge($errors, self::validate($value, $additional, $childPath));
                }
            }
        }

        // array: items + minItems/maxItems
        if (self::typeOf($data) === 'array') {
            if (isset($schema['items']) && is_array($schema['items'])) {
                foreach ($data as $i => $value) {
                    $errors = array_merge($errors, self::validate($value, $schema['items'], $path . "[$i]"));
                }
            }
            if (isset($schema['minItems']) && count($data) < (int) $schema['minItems']) {
                $errors[] = "$path: fewer items than minItems " . $schema['minItems'];
            }
            if (isset($schema['maxItems']) && count($data) > (int) $schema['maxItems']) {
                $errors[] = "$path: more items than maxItems " . $schema['maxItems'];
            }
        }

        return $errors;
    }

    /**
     * @param array<mixed> $schema
     */
    public static function isValid(mixed $data, array $schema): bool
    {
        return self::validate($data, $schema) === [];
    }

    /**
     * Returns the JSON Schema type name corresponding to the PHP value.
     * PHP can't distinguish {} from [], so an indexed array is "array"
     * and an associative array is "object".
     */
    private static function typeOf(mixed $v): string
    {
        if (is_string($v)) {
            return 'string';
        }
        if (is_int($v)) {
            return 'integer';
        }
        if (is_float($v)) {
            return 'number';
        }
        if (is_bool($v)) {
            return 'boolean';
        }
        if ($v === null) {
            return 'null';
        }
        if (is_array($v)) {
            return array_is_list($v) ? 'array' : 'object';
        }
        return gettype($v);
    }

    private static function stringify(mixed $v): string
    {
        if (is_string($v)) {
            return "'$v'";
        }
        if (is_bool($v)) {
            return $v ? 'true' : 'false';
        }
        if ($v === null) {
            return 'null';
        }
        return (string) $v;
    }
}
