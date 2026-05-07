<?php

declare(strict_types=1);

namespace Cloude;

use ArrayAccess;
use ArrayIterator;
use Countable;
use IteratorAggregate;
use Traversable;

/**
 * Fluent, chainable wrapper around an array. The 80% subset of
 * doctrine/collections / Laravel Collection that you actually use,
 * without becoming the framework.
 *
 * All chainable methods return a new Collection (the receiver is left
 * intact). Terminal methods (count, first, last, sum, …) return scalars.
 * The class is `ArrayAccess`, `Countable` and iterable, so you can use
 * it anywhere a plain array would go.
 *
 *   $top = Collection::make($users)
 *       ->filter(fn ($u) => $u['active'])
 *       ->sortBy('score')
 *       ->take(10)
 *       ->pluck('name', 'id')
 *       ->all();
 *
 * Item access supports dot-paths via `Cloude\Arr` whenever a method
 * accepts a string column key.
 *
 * @template-implements ArrayAccess<array-key, mixed>
 * @template-implements IteratorAggregate<array-key, mixed>
 */
class Collection implements ArrayAccess, Countable, IteratorAggregate
{
    /**
     * @param array<mixed> $items
     */
    public function __construct(private array $items = []) {}

    /**
     * @param iterable<mixed> $items
     */
    public static function make(iterable $items = []): self
    {
        if ($items instanceof self) {
            return new self($items->items);
        }
        if ($items instanceof Traversable) {
            return new self(iterator_to_array($items));
        }
        return new self($items);
    }

    // ── terminals ──────────────────────────────────────────────────────────

    /** @return array<mixed> */
    public function all(): array
    {
        return $this->items;
    }

    public function count(): int
    {
        return count($this->items);
    }

    public function isEmpty(): bool
    {
        return $this->items === [];
    }

    public function isNotEmpty(): bool
    {
        return $this->items !== [];
    }

    /**
     * Returns the first item (optionally matching $cb), or $default.
     */
    public function first(?callable $cb = null, mixed $default = null): mixed
    {
        if ($cb === null) {
            foreach ($this->items as $value) {
                return $value;
            }
            return $default;
        }
        foreach ($this->items as $key => $value) {
            if ($cb($value, $key)) {
                return $value;
            }
        }
        return $default;
    }

    /**
     * Returns the last item (optionally matching $cb), or $default.
     */
    public function last(?callable $cb = null, mixed $default = null): mixed
    {
        if ($cb === null) {
            return $this->items === [] ? $default : end($this->items);
        }
        $found = $default;
        foreach ($this->items as $key => $value) {
            if ($cb($value, $key)) {
                $found = $value;
            }
        }
        return $found;
    }

    public function contains(mixed $needle, bool $strict = true): bool
    {
        return in_array($needle, $this->items, $strict);
    }

    public function every(callable $cb): bool
    {
        foreach ($this->items as $key => $value) {
            if (!$cb($value, $key)) {
                return false;
            }
        }
        return true;
    }

    public function some(callable $cb): bool
    {
        foreach ($this->items as $key => $value) {
            if ($cb($value, $key)) {
                return true;
            }
        }
        return false;
    }

    public function reduce(callable $cb, mixed $initial = null): mixed
    {
        $acc = $initial;
        foreach ($this->items as $key => $value) {
            $acc = $cb($acc, $value, $key);
        }
        return $acc;
    }

    /**
     * Sums the items, or the values at $column when given.
     */
    public function sum(?string $column = null): int|float
    {
        $total = 0;
        foreach ($this->items as $item) {
            $value = $column === null ? $item : Arr::get((array) $item, $column);
            if (is_numeric($value)) {
                $total += $value;
            }
        }
        return $total;
    }

    /**
     * Mean of the items (or the values at $column). Empty → 0.
     */
    public function avg(?string $column = null): float|int
    {
        $count = count($this->items);
        if ($count === 0) {
            return 0;
        }
        return $this->sum($column) / $count;
    }

    public function min(?string $column = null): mixed
    {
        return $this->extreme($column, fn ($a, $b) => $a < $b);
    }

    public function max(?string $column = null): mixed
    {
        return $this->extreme($column, fn ($a, $b) => $a > $b);
    }

    private function extreme(?string $column, callable $cmp): mixed
    {
        $best = null;
        $first = true;
        foreach ($this->items as $item) {
            $value = $column === null ? $item : Arr::get((array) $item, $column);
            if ($first || $cmp($value, $best)) {
                $best = $value;
                $first = false;
            }
        }
        return $best;
    }

    // ── chainable ──────────────────────────────────────────────────────────

    /**
     * Iterates without building a new collection; returns $this for chaining.
     * Return false from the callback to stop early.
     */
    public function each(callable $cb): self
    {
        foreach ($this->items as $key => $value) {
            if ($cb($value, $key) === false) {
                break;
            }
        }
        return $this;
    }

    public function map(callable $cb): self
    {
        $result = [];
        foreach ($this->items as $key => $value) {
            $result[$key] = $cb($value, $key);
        }
        return new self($result);
    }

    public function filter(?callable $cb = null): self
    {
        if ($cb === null) {
            return new self(array_filter($this->items));
        }
        $result = [];
        foreach ($this->items as $key => $value) {
            if ($cb($value, $key)) {
                $result[$key] = $value;
            }
        }
        return new self($result);
    }

    public function reject(callable $cb): self
    {
        return $this->filter(static fn ($v, $k): bool => !$cb($v, $k));
    }

    /**
     * Extracts a column, optionally re-keying. Both arguments support
     * dot-paths.
     */
    public function pluck(string $column, ?string $key = null): self
    {
        return new self(Arr::pluck($this->items, $column, $key));
    }

    /**
     * Re-keys the collection by $key (string column or callable).
     */
    public function keyBy(string|callable $key): self
    {
        $extract = is_string($key) ? static fn ($it) => Arr::get((array) $it, $key) : $key;
        $result = [];
        foreach ($this->items as $k => $item) {
            $newKey = $extract($item, $k);
            $result[$newKey] = $item;
        }
        return new self($result);
    }

    /**
     * Buckets items by $key into a Collection of lists.
     */
    public function groupBy(string|callable $key): self
    {
        $extract = is_string($key) ? static fn ($it) => Arr::get((array) $it, $key) : $key;
        $result = [];
        foreach ($this->items as $k => $item) {
            $groupKey = $extract($item, $k);
            $result[$groupKey][] = $item;
        }
        return new self($result);
    }

    /**
     * Sort by $key (string column or callable returning a comparable).
     * Ascending by default; pass $descending=true to reverse.
     */
    public function sortBy(string|callable $key, bool $descending = false): self
    {
        $extract = is_string($key) ? static fn ($it) => Arr::get((array) $it, $key) : $key;
        $copy = $this->items;
        uasort($copy, $descending
            ? static fn ($a, $b) => $extract($b) <=> $extract($a)
            : static fn ($a, $b) => $extract($a) <=> $extract($b));
        return new self($copy);
    }

    /**
     * Generic sort. With no callback, sorts values ascending preserving keys.
     */
    public function sort(?callable $cb = null): self
    {
        $copy = $this->items;
        if ($cb !== null) {
            uasort($copy, $cb);
        } else {
            asort($copy);
        }
        return new self($copy);
    }

    public function reverse(bool $preserveKeys = true): self
    {
        return new self(array_reverse($this->items, $preserveKeys));
    }

    public function take(int $n): self
    {
        return new self(array_slice($this->items, 0, $n, true));
    }

    public function slice(int $offset, ?int $length = null): self
    {
        return new self(array_slice($this->items, $offset, $length, true));
    }

    /**
     * @return self<int,array<mixed>>
     */
    public function chunk(int $size): self
    {
        if ($size <= 0) {
            return new self([]);
        }
        return new self(array_chunk($this->items, $size, true));
    }

    /**
     * Removes duplicates. Without $column, compares values directly
     * (SORT_REGULAR). With $column, dedupes by that field.
     */
    public function unique(?string $column = null): self
    {
        if ($column === null) {
            return new self(array_unique($this->items, SORT_REGULAR));
        }
        $seen = [];
        $result = [];
        foreach ($this->items as $key => $item) {
            $value = Arr::get((array) $item, $column);
            if (!in_array($value, $seen, true)) {
                $seen[] = $value;
                $result[$key] = $item;
            }
        }
        return new self($result);
    }

    public function values(): self
    {
        return new self(array_values($this->items));
    }

    public function keys(): self
    {
        return new self(array_keys($this->items));
    }

    /**
     * Recursive merge with later-overrides semantics (delegates to Arr::merge).
     *
     * @param iterable<mixed> ...$others
     */
    public function merge(iterable ...$others): self
    {
        $arrays = [$this->items];
        foreach ($others as $o) {
            if ($o instanceof self) {
                $arrays[] = $o->items;
            } elseif ($o instanceof Traversable) {
                $arrays[] = iterator_to_array($o);
            } else {
                $arrays[] = $o;
            }
        }
        return new self(Arr::merge(...$arrays));
    }

    // ── ArrayAccess / Countable / IteratorAggregate ───────────────────────

    public function offsetExists(mixed $offset): bool
    {
        return array_key_exists($offset, $this->items);
    }

    public function offsetGet(mixed $offset): mixed
    {
        return $this->items[$offset] ?? null;
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        if ($offset === null) {
            $this->items[] = $value;
        } else {
            $this->items[$offset] = $value;
        }
    }

    public function offsetUnset(mixed $offset): void
    {
        unset($this->items[$offset]);
    }

    public function getIterator(): ArrayIterator
    {
        return new ArrayIterator($this->items);
    }
}
