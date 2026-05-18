<?php

declare(strict_types=1);

namespace Cloude\Storage;

/**
 * Lightweight value object that pairs a SQL table name with an optional
 * alias. The point: keep `Model::table()` / `Model::field()` /
 * `Model::as()` typed and static so consumer code (and AI agents) can
 * build joins without scattering string literals.
 *
 *   $u = User::as('u');
 *   $o = Order::as('o');
 *
 *   $q = User::query()
 *       ->select($u->field('id'), $u->field('email'), $o->field('total'))
 *       ->join($o, $o->field('user_id'), '=', $u->field('id'))
 *       ->where($o->field('status'), 'paid');
 *
 *   $q->compile();
 *   // → SELECT `u`.`id`, `u`.`email`, `o`.`total`
 *   //   FROM `users` AS `u` JOIN `orders` AS `o` ON `o`.`user_id` = `u`.`id`
 *   //   WHERE `o`.`status` = 'paid'
 *
 * `field()` returns dotted names (`'u.email'`), not pre-quoted SQL — the
 * Query builder does the quoting via `Identifier::qualify()` so the same
 * string works as a SELECT column, a WHERE column, and a JOIN target.
 */
final class TableRef implements \Stringable
{
    public function __construct(
        public readonly string $table,
        public readonly ?string $alias = null,
    ) {}

    /**
     * The identifier you use to qualify columns — alias when present,
     * otherwise the table name.
     */
    public function name(): string
    {
        return $this->alias ?? $this->table;
    }

    /**
     * Returns `'alias.column'` (or `'table.column'` when no alias is set).
     * Pass `'*'` to qualify a star: `'u.*'`.
     */
    public function field(string $column): string
    {
        return $this->name() . '.' . $column;
    }

    /**
     * Build the typed `[column, alias]` pair that `Query::select()`
     * accepts. The column is qualified by this TableRef's name (its
     * alias when present, otherwise the table name):
     *
     *   $u = User::as('u');
     *   $u->alias('name', 'who');
     *   // → ['u.name', 'who']
     *
     *   User::query()->from($u)
     *       ->select($u->alias('name', 'who'))
     *       ->get();
     *
     * @return array{0:string, 1:string}
     */
    public function alias(string $column, string $alias): array
    {
        return [$this->field($column), $alias];
    }

    /**
     * The FROM / JOIN clause expression with proper identifier quoting.
     *
     *   (new TableRef('users'))->expression();           // `users`
     *   (new TableRef('users', 'u'))->expression();      // `users` AS `u`
     */
    public function expression(string $quoteChar = '`'): string
    {
        $t = Identifier::quote($this->table, $quoteChar);
        return $this->alias === null
            ? $t
            : $t . ' AS ' . Identifier::quote($this->alias, $quoteChar);
    }

    public function __toString(): string
    {
        return $this->expression();
    }
}
