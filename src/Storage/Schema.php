<?php

declare(strict_types=1);

namespace Cloude\Storage;

/**
 * Tiny declarative DDL emitter — produces `CREATE TABLE` / `DROP TABLE`
 * SQL from structured arrays. **Not a migration framework.** No
 * versioning, no up/down, no schema diffing, no runner. Use it to
 * bootstrap a dev database, write a one-off install script, or feed
 * the generated SQL into your real migration tool of choice (Phinx,
 * Doctrine, plain `.sql` files).
 *
 * Target dialect: MySQL. The same SQL is mostly valid on Postgres
 * (booleans + serial types differ — emit Postgres-style by passing
 * `'pgsql'`). SQLite supports a subset (no `ALTER TABLE ... ADD
 * CONSTRAINT`); see `createTableSql()`'s embedded constraints option.
 *
 * ## Column descriptor
 *
 *   'email' => [
 *       'type'           => 'varchar(255)',
 *       'null'           => false,        // default true
 *       'default'        => 'no@one.test', // default omitted
 *       'auto_increment' => false,        // MySQL only
 *       'primary'        => false,        // primary key (composite via $primaryKey)
 *       'unsigned'       => false,        // MySQL only
 *       'comment'        => null,
 *   ]
 *
 * ## Index descriptor
 *
 *   ['type' => 'unique' | 'index', 'columns' => ['email'], 'name' => 'uq_users_email']
 *
 *   `name` is optional — when omitted, derived as
 *   `{prefix}_{table}_{col1}_{col2}...`.
 *
 * ## Foreign-key descriptor
 *
 *   [
 *       'columns'    => ['role_id'],
 *       'references' => 'roles',
 *       'on'         => ['id'],
 *       'on_delete'  => 'cascade' | 'set null' | 'restrict' | 'no action' | 'set default',
 *       'on_update'  => 'cascade' | 'set null' | 'restrict' | 'no action' | 'set default',
 *       'name'       => 'fk_users_role_id',     // optional
 *   ]
 *
 * Both action keys are validated against the standard ANSI
 * referential-action set; anything else throws. **`on_delete` and
 * `on_update` always appear in the emitted SQL**, defaulting to
 * `NO ACTION` (the SQL standard) when not declared. The output is
 * always explicit so the database — and any reader of the
 * generated `ALTER TABLE` — knows exactly what each constraint
 * does, no implicit defaults left to driver / engine.
 */
final class Schema
{
    /** Allowed referential-action values for FK on_update / on_delete. */
    private const REFERENTIAL_ACTIONS = [
        'cascade', 'set null', 'restrict', 'no action', 'set default',
    ];

    /**
     * Builds the full `CREATE TABLE` statement. Indexes and FKs are
     * embedded inside the CREATE for compatibility with engines that
     * don't support `ALTER TABLE ADD CONSTRAINT` (notably SQLite).
     *
     * @param array<string, array<string,mixed>>        $columns
     * @param list<array<string,mixed>>                 $indexes
     * @param list<array<string,mixed>>                 $foreignKeys
     */
    public static function createTableSql(
        string $table,
        array $columns,
        array $indexes = [],
        array $foreignKeys = [],
        string $dialect = 'mysql',
    ): string {
        if ($columns === []) {
            throw new \InvalidArgumentException("Schema::createTableSql('$table'): \$columns is empty");
        }
        $q = self::quoteCharFor($dialect);

        // Detect composite PK first so we can decide whether to inline
        // `PRIMARY KEY` on each column or declare it once at the table
        // level. Inlining a single column is the more compact / idiomatic
        // form; the table-level declaration is required for composites.
        $primaries = [];
        foreach ($columns as $name => $def) {
            if (!empty($def['primary'])) {
                $primaries[] = $name;
            }
        }
        $inlinePrimary = count($primaries) === 1;

        $lines = [];
        foreach ($columns as $name => $def) {
            $lines[] = '  ' . self::columnDdl($name, $def, $dialect, $inlinePrimary);
        }
        if (!$inlinePrimary && count($primaries) > 1) {
            $lines[] = '  PRIMARY KEY (' . self::quoteList($primaries, $q) . ')';
        }

        foreach ($indexes as $idx) {
            $lines[] = '  ' . self::indexDdl($table, $idx, $q);
        }

        foreach ($foreignKeys as $fk) {
            $lines[] = '  ' . self::foreignKeyDdl($table, $fk, $q);
        }

        $sql = 'CREATE TABLE ' . self::quote($table, $q) . " (\n"
            . implode(",\n", $lines)
            . "\n)";

        // Engine-specific tail: MySQL benefits from explicit InnoDB +
        // utf8mb4. Postgres / SQLite leave the line off.
        if ($dialect === 'mysql') {
            $sql .= ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
        }
        return $sql;
    }

    public static function dropTableSql(string $table, string $dialect = 'mysql'): string
    {
        $q = self::quoteCharFor($dialect);
        return 'DROP TABLE IF EXISTS ' . self::quote($table, $q);
    }

    /**
     * Standalone `CREATE [UNIQUE] INDEX` for a single index descriptor.
     * Use when the table already exists and you only want to attach
     * indexes (common when columns come from a separate migration
     * step). The inline form embedded in {@see createTableSql()} is
     * different SQL — this one is portable across MySQL / Postgres /
     * SQLite without the `UNIQUE KEY <name> (...)` quirk.
     *
     *   CREATE UNIQUE INDEX `uq_users_email` ON `users` (`email`);
     *   CREATE INDEX        `idx_users_role` ON `users` (`role_id`);
     *
     * @param array<string,mixed> $idx
     */
    public static function indexSql(string $table, array $idx, string $dialect = 'mysql'): string
    {
        $q       = self::quoteCharFor($dialect);
        $type    = strtolower((string) ($idx['type'] ?? 'index'));
        $columns = (array) ($idx['columns'] ?? throw new \InvalidArgumentException(
            "Index on '$table' is missing 'columns'",
        ));
        if ($columns === []) {
            throw new \InvalidArgumentException("Index on '$table' has empty 'columns'");
        }
        $name = (string) ($idx['name'] ?? self::derivedIndexName($table, $columns, $type));

        $kw = match ($type) {
            'unique', 'uq' => 'CREATE UNIQUE INDEX',
            'index',  'ix' => 'CREATE INDEX',
            default        => throw new \InvalidArgumentException(
                "Index on '$table' has unknown type '$type' (use 'unique' or 'index')",
            ),
        };
        return $kw . ' ' . self::quote($name, $q)
            . ' ON ' . self::quote($table, $q)
            . ' (' . self::quoteList($columns, $q) . ')';
    }

    /**
     * Standalone `ALTER TABLE ... ADD CONSTRAINT ... FOREIGN KEY ...`.
     * Same purpose as {@see indexSql()}: attach an FK to an existing
     * table without rewriting the CREATE.
     *
     *   ALTER TABLE `users` ADD CONSTRAINT `fk_users_role_id`
     *     FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`)
     *     ON DELETE SET NULL ON UPDATE CASCADE;
     *
     * @param array<string,mixed> $fk
     */
    public static function foreignKeySql(string $table, array $fk, string $dialect = 'mysql'): string
    {
        $q          = self::quoteCharFor($dialect);
        $columns    = (array) ($fk['columns'] ?? throw new \InvalidArgumentException(
            "FK on '$table' is missing 'columns'",
        ));
        $references = (string) ($fk['references'] ?? throw new \InvalidArgumentException(
            "FK on '$table' is missing 'references'",
        ));
        $on         = (array) ($fk['on'] ?? throw new \InvalidArgumentException(
            "FK on '$table' is missing 'on' (target column list)",
        ));
        $name = (string) ($fk['name'] ?? self::derivedFkName($table, $columns));

        $sql = 'ALTER TABLE ' . self::quote($table, $q)
            . ' ADD CONSTRAINT ' . self::quote($name, $q)
            . ' FOREIGN KEY (' . self::quoteList($columns, $q) . ')'
            . ' REFERENCES ' . self::quote($references, $q)
            . ' (' . self::quoteList($on, $q) . ')'
            . ' ON DELETE ' . self::referentialAction((string) ($fk['on_delete'] ?? 'no action'))
            . ' ON UPDATE ' . self::referentialAction((string) ($fk['on_update'] ?? 'no action'));
        return $sql;
    }

    // ── building blocks ──────────────────────────────────────────────────

    /**
     * @param array<string,mixed> $def
     */
    private static function columnDdl(string $name, array $def, string $dialect, bool $inlinePrimary): string
    {
        $q = self::quoteCharFor($dialect);
        $type = (string) ($def['type'] ?? throw new \InvalidArgumentException(
            "Column '$name' is missing 'type'",
        ));

        $parts = [self::quote($name, $q), $type];

        if (!empty($def['unsigned']) && $dialect === 'mysql') {
            $parts[] = 'UNSIGNED';
        }

        $nullable = $def['null'] ?? true;
        $parts[] = $nullable ? 'NULL' : 'NOT NULL';

        if (array_key_exists('default', $def)) {
            $parts[] = 'DEFAULT ' . self::renderDefault($def['default']);
        }

        if (!empty($def['auto_increment']) && $dialect === 'mysql') {
            $parts[] = 'AUTO_INCREMENT';
        }

        if (!empty($def['primary']) && $inlinePrimary) {
            $parts[] = 'PRIMARY KEY';
        }

        if (!empty($def['comment'])) {
            $parts[] = 'COMMENT ' . self::renderDefault((string) $def['comment']);
        }

        return implode(' ', $parts);
    }

    /**
     * Render an index inside the `CREATE TABLE (...)` body. Output:
     *   `UNIQUE KEY uq_users_email (`email`)`
     *   `KEY idx_users_created_at (`created_at`)`
     *
     * @param array<string,mixed> $idx
     */
    private static function indexDdl(string $table, array $idx, string $q): string
    {
        $type    = strtolower((string) ($idx['type'] ?? 'index'));
        $columns = (array) ($idx['columns'] ?? throw new \InvalidArgumentException(
            "Index on '$table' is missing 'columns'",
        ));
        if ($columns === []) {
            throw new \InvalidArgumentException("Index on '$table' has empty 'columns'");
        }
        $name = (string) ($idx['name'] ?? self::derivedIndexName($table, $columns, $type));

        $kw = match ($type) {
            'unique', 'uq' => 'UNIQUE KEY',
            'index',  'ix' => 'KEY',
            default        => throw new \InvalidArgumentException(
                "Index on '$table' has unknown type '$type' (use 'unique' or 'index')",
            ),
        };
        return $kw . ' ' . self::quote($name, $q) . ' (' . self::quoteList($columns, $q) . ')';
    }

    /**
     * @param array<string,mixed> $fk
     */
    private static function foreignKeyDdl(string $table, array $fk, string $q): string
    {
        $columns    = (array) ($fk['columns'] ?? throw new \InvalidArgumentException(
            "FK on '$table' is missing 'columns'",
        ));
        $references = (string) ($fk['references'] ?? throw new \InvalidArgumentException(
            "FK on '$table' is missing 'references'",
        ));
        $on         = (array) ($fk['on'] ?? throw new \InvalidArgumentException(
            "FK on '$table' is missing 'on' (target column list)",
        ));
        $name = (string) ($fk['name'] ?? self::derivedFkName($table, $columns));

        $sql = 'CONSTRAINT ' . self::quote($name, $q)
            . ' FOREIGN KEY (' . self::quoteList($columns, $q) . ')'
            . ' REFERENCES ' . self::quote($references, $q)
            . ' (' . self::quoteList($on, $q) . ')'
            . ' ON DELETE ' . self::referentialAction((string) ($fk['on_delete'] ?? 'no action'))
            . ' ON UPDATE ' . self::referentialAction((string) ($fk['on_update'] ?? 'no action'));
        return $sql;
    }

    private static function referentialAction(string $action): string
    {
        $lc = strtolower(trim($action));
        if (!in_array($lc, self::REFERENTIAL_ACTIONS, true)) {
            throw new \InvalidArgumentException(
                "Unknown referential action '$action' "
                . '(allowed: ' . implode(', ', self::REFERENTIAL_ACTIONS) . ')',
            );
        }
        return strtoupper($lc);
    }

    /**
     * Render a DEFAULT value as SQL literal. Strings starting with
     * known SQL keywords (`CURRENT_TIMESTAMP`, `NOW()`, etc.) pass
     * through verbatim; everything else is quoted.
     */
    private static function renderDefault(mixed $value): string
    {
        if ($value === null) {
            return 'NULL';
        }
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }
        $s = (string) $value;
        $upper = strtoupper(ltrim($s));
        if (str_starts_with($upper, 'CURRENT_TIMESTAMP')
            || str_starts_with($upper, 'CURRENT_DATE')
            || str_starts_with($upper, 'CURRENT_TIME')
            || str_starts_with($upper, 'NOW(')
            || str_starts_with($upper, 'UUID()')
            || str_starts_with($upper, 'NULL')
        ) {
            return $s;
        }
        return "'" . str_replace("'", "''", $s) . "'";
    }

    /**
     * @param list<string> $columns
     */
    private static function derivedIndexName(string $table, array $columns, string $type): string
    {
        $prefix = match ($type) {
            'unique', 'uq' => 'uq',
            default        => 'idx',
        };
        return $prefix . '_' . $table . '_' . implode('_', $columns);
    }

    /**
     * @param list<string> $columns
     */
    private static function derivedFkName(string $table, array $columns): string
    {
        return 'fk_' . $table . '_' . implode('_', $columns);
    }

    /**
     * @param list<string> $names
     */
    private static function quoteList(array $names, string $q): string
    {
        return implode(', ', array_map(
            static fn (string $n) => self::quote($n, $q),
            $names,
        ));
    }

    private static function quote(string $name, string $q): string
    {
        return Identifier::quote($name, $q);
    }

    private static function quoteCharFor(string $dialect): string
    {
        return $dialect === 'pgsql' ? '"' : '`';
    }
}
