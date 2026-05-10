<?php

declare(strict_types=1);

namespace Cloude\Db;

/**
 * Identifier-quoting helper shared by `Cloude\Db\Query` and
 * `Cloude\Model\Storage\PdoStorage`.
 *
 * PDO can parameterize *values* but not *identifiers* — column names,
 * table names, etc. We can't bind those, so we whitelist the shape
 * (`[A-Za-z_][A-Za-z0-9_]*`) and quote with the right character for the
 * driver. Anything outside the whitelist throws.
 *
 * Quote characters by driver:
 *   - MySQL, SQLite        → backticks  (default)
 *   - Postgres             → double quotes
 *
 * If your column genuinely needs spaces or hyphens, the framework's
 * answer is "use a different column name". This is the seam between
 * "minimal SQL builder" and "fully general SQL parser" — and we picked
 * the former on purpose.
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
     * Picks the right quote character for the connection's driver.
     */
    public static function quoteCharFor(\PDO $pdo): string
    {
        $driver = (string) $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
        return $driver === 'pgsql' ? '"' : '`';
    }
}
