<?php

declare(strict_types=1);

namespace Cloude\Model;

/**
 * Opt-in attribute type coercion for `Cloude\Model\Model` subclasses.
 *
 * Declare a `$casts` map on the model and the framework will normalise
 * values when reading (storage → PHP) and writing (PHP → storage). Null
 * always passes through untouched — that's by design, so nullable
 * columns don't surprise you with `0` / `''` / wrong defaults.
 *
 *   class Product extends Model
 *   {
 *       protected static string $table = 'products';
 *
 *       protected static array $casts = [
 *           'id'          => 'int',
 *           'price'       => 'decimal:2',     // string "12.50" both ways
 *           'in_stock'    => 'bool',
 *           'tags'        => 'json',          // ['a', 'b']  ↔ '["a","b"]'
 *           'created_at'  => 'datetime',      // DateTimeImmutable ↔ 'Y-m-d H:i:s'
 *           'status'      => 'enum:' . Status::class,   // BackedEnum
 *       ];
 *   }
 *
 *   $p = Product::find(1);
 *   $p->price + 10;            // INVALID — decimals stay strings; use bcmath
 *   $p->in_stock === true;     // bool
 *   $p->created_at->format('c');
 *   $p->status === Status::Active;
 *
 * Supported types:
 *
 *   - `int` / `integer`
 *   - `float` / `double` / `real`
 *   - `string`
 *   - `bool` / `boolean`           (writes 1/0 for DB friendliness)
 *   - `decimal` / `decimal:N`      (string with N decimals, default 2)
 *   - `json` / `array`             (associative array)
 *   - `datetime` / `datetime:FMT`  (DateTimeImmutable, default 'Y-m-d H:i:s')
 *   - `date` / `date:FMT`          (DateTimeImmutable, default 'Y-m-d')
 *   - `enum:FQCN`                  (BackedEnum — `value` round-trips)
 *
 * Unknown types throw `\InvalidArgumentException` so typos surface fast.
 */
final class Cast
{
    /**
     * Apply the read-side (storage → PHP) conversion for a single attribute.
     */
    public static function read(mixed $value, string $type): mixed
    {
        if ($value === null) {
            return null;
        }
        [$base, $arg] = self::split($type);
        return match ($base) {
            'int', 'integer'          => (int) $value,
            'float', 'double', 'real' => (float) $value,
            'string'                  => (string) $value,
            'bool', 'boolean'         => filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? (bool) $value,
            'decimal'                 => number_format((float) $value, self::decimals($arg), '.', ''),
            'json', 'array'           => self::readJson($value),
            'datetime'                => self::readDateTime((string) $value),
            'date'                    => self::readDateTime((string) $value),
            'enum'                    => self::readEnum($value, $arg),
            default                   => throw new \InvalidArgumentException("Unknown cast type: '$type'"),
        };
    }

    /**
     * Apply the write-side (PHP → storage) conversion for a single attribute.
     */
    public static function write(mixed $value, string $type): mixed
    {
        if ($value === null) {
            return null;
        }
        [$base, $arg] = self::split($type);
        return match ($base) {
            'int', 'integer'          => (int) $value,
            'float', 'double', 'real' => (float) $value,
            'string'                  => (string) $value,
            'bool', 'boolean'         => (bool) $value ? 1 : 0,
            'decimal'                 => number_format((float) $value, self::decimals($arg), '.', ''),
            'json', 'array'           => json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'datetime'                => self::writeDateTime($value, $arg ?? 'Y-m-d H:i:s'),
            'date'                    => self::writeDateTime($value, $arg ?? 'Y-m-d'),
            'enum'                    => self::writeEnum($value),
            default                   => throw new \InvalidArgumentException("Unknown cast type: '$type'"),
        };
    }

    /**
     * Splits `'decimal:2'` into `['decimal', '2']`. Always returns two
     * elements; the second is `null` when no argument is present.
     *
     * @return array{0:string, 1:?string}
     */
    private static function split(string $type): array
    {
        $pos = strpos($type, ':');
        if ($pos === false) {
            return [strtolower($type), null];
        }
        return [strtolower(substr($type, 0, $pos)), substr($type, $pos + 1)];
    }

    private static function decimals(?string $arg): int
    {
        return $arg !== null && ctype_digit($arg) ? (int) $arg : 2;
    }

    private static function readJson(mixed $value): mixed
    {
        if (is_array($value)) {
            return $value;     // already decoded by a previous read
        }
        $decoded = json_decode((string) $value, true);
        return is_array($decoded) ? $decoded : $decoded;   // keep scalar JSON too
    }

    private static function readDateTime(string $value): \DateTimeImmutable
    {
        return new \DateTimeImmutable($value);
    }

    /**
     * @param class-string<\BackedEnum>|null $fqcn
     */
    private static function readEnum(mixed $value, ?string $fqcn): \BackedEnum
    {
        if ($fqcn === null || !enum_exists($fqcn)) {
            throw new \InvalidArgumentException("Cast 'enum:$fqcn' needs a valid BackedEnum class name");
        }
        if ($value instanceof \BackedEnum) {
            return $value;
        }
        /** @var class-string<\BackedEnum> $fqcn */
        return $fqcn::from($value);
    }

    private static function writeDateTime(mixed $value, string $fmt): string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format($fmt);
        }
        // Allow callers to assign a raw string and have it normalised.
        return (new \DateTimeImmutable((string) $value))->format($fmt);
    }

    private static function writeEnum(mixed $value): mixed
    {
        if ($value instanceof \BackedEnum) {
            return $value->value;
        }
        return $value;   // already the scalar value
    }
}
