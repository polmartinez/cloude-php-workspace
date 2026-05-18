<?php

declare(strict_types=1);

namespace Cloude\Storage;

/**
 * Internal value object used by {@see Query} to represent one entry in
 * the WHERE list. Either a simple predicate (`col op val`) or a nested
 * group of child clauses produced by `whereGroup()` / `orWhereGroup()`.
 *
 * Lives in its own file so the Query builder can introspect a parsed
 * tree without exposing a heavy `WhereExpr` hierarchy. Not part of the
 * public API — instantiate via the named constructors.
 *
 * @internal
 */
final class WhereClause
{
    /**
     * @param string                  $conn     'AND' or 'OR'
     * @param string                  $col      column reference (may be qualified)
     * @param string                  $op       SQL operator (= != ... BETWEEN ...)
     * @param mixed                   $val      bound value, list, or null
     * @param bool                    $isGroup  true when this is a nested group
     * @param list<self>              $children child clauses when $isGroup
     */
    private function __construct(
        public readonly string $conn,
        public readonly string $col,
        public readonly string $op,
        public readonly mixed $val,
        public readonly bool $isGroup,
        public readonly array $children,
    ) {}

    public static function predicate(string $conn, string $col, string $op, mixed $val): self
    {
        return new self($conn, $col, $op, $val, false, []);
    }

    /** @param list<self> $children */
    public static function group(string $conn, array $children): self
    {
        return new self($conn, '', '', null, true, $children);
    }
}
