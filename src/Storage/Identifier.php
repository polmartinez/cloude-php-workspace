<?php

declare(strict_types=1);

namespace Cloude\Storage;

/**
 * Identifier-quoting helper shared by `Cloude\Storage\Query` and
 * `Cloude\Model\Storage\PdoStorage`.
 *
 * PDO can parameterize *values* but not *identifiers* — column names,
 * table names, etc. We can't bind those, so we whitelist the shape
 * (`[A-Za-z_][A-Za-z0-9_]*`) and quote with the right character.
 * Anything outside the whitelist throws `InvalidArgumentException`.
 *
 * Default quote character is backtick (`` ` ``), which works for MySQL
 * and SQLite — the most common case. For Postgres pass `'"'` explicitly,
 * either as the second arg to `quote()` or via `quoteCharFor($pdo)` if
 * you want auto-detection from the driver name.
 *
 *   Identifier::quote('users');                  // → `users`
 *   Identifier::quote('users', '"');             // → "users"  (Postgres-style)
 *   Identifier::quote('users; DROP TABLE x');    // throws
 *
 * The standalone call (no second arg, no PDO needed) is the canonical
 * path: most apps run on MySQL/SQLite and quoting is the most common
 * SQL-helper call there is.
 */
final class Identifier
{
    public static function quote(string $name, string $quoteChar = '`'): string
    {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name) !== 1) {
            throw new \InvalidArgumentException("Invalid SQL identifier: '$name'");
        }
        return $quoteChar . $name . $quoteChar;
    }

    /**
     * Quotes a (possibly dotted) column reference. Handles three shapes:
     *
     *   qualify('email');         // → `email`
     *   qualify('users.email');   // → `users`.`email`
     *   qualify('users.*');       // → `users`.*
     *   qualify('*');             // → *
     *
     * Anything outside `column` / `table.column` / `table.*` / `*` throws.
     * No other dotted forms (`db.schema.table.col`) are supported — drop
     * to raw SQL when you need three-part names.
     */
    public static function qualify(string $expr, string $quoteChar = '`'): string
    {
        if ($expr === '*') {
            return '*';
        }
        if (!str_contains($expr, '.')) {
            return self::quote($expr, $quoteChar);
        }
        $parts = explode('.', $expr);
        if (count($parts) !== 2) {
            throw new \InvalidArgumentException("Invalid qualified identifier: '$expr'");
        }
        [$prefix, $col] = $parts;
        $prefixQ = self::quote($prefix, $quoteChar);
        $colQ    = $col === '*' ? '*' : self::quote($col, $quoteChar);
        return $prefixQ . '.' . $colQ;
    }

    /**
     * Opt-in helper that maps the PDO driver name to the conventional
     * quote character. Use it explicitly when you want auto-detection:
     *
     *   new Query($pdo, 'users', Identifier::quoteCharFor($pdo));
     *
     * The framework does **not** call this implicitly anywhere — the
     * common case (backtick) is the default in Query / PdoStorage
     * constructors. Pass the char yourself when you need something else.
     */
    public static function quoteCharFor(\PDO $pdo): string
    {
        $driver = (string) $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
        return $driver === 'pgsql' ? '"' : '`';
    }
}
