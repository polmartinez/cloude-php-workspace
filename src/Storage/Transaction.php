<?php

declare(strict_types=1);

namespace Cloude\Storage;

/**
 * Lightweight transaction helper over the `Connection` pool, with
 * SAVEPOINT-based nesting and PDOException wrapping via
 * {@see StorageException}.
 *
 * Three reasons to use this instead of `$pdo->beginTransaction()`
 * directly:
 *
 *   1. **Named-connection convenience** — no need to grab the PDO
 *      yourself: `Transaction::begin('default')` or just
 *      `Transaction::begin()` for the default pool.
 *   2. **Nested transactions via SAVEPOINTs** — PDO's native
 *      `beginTransaction()` throws on a second call (most drivers
 *      don't support real nesting). We track depth per connection
 *      and issue `SAVEPOINT sp_N` / `RELEASE` / `ROLLBACK TO` for
 *      inner calls, making `Transaction::run()` safely composable
 *      across nested service / repository methods.
 *   3. **Exception wrapping** — a failed `BEGIN` / `COMMIT` /
 *      `ROLLBACK` surfaces as a typed `Cloude\Storage\StorageException`
 *      (or a more specific subclass), not a raw `\PDOException`.
 *
 * The outermost level uses PDO's own `beginTransaction()` / `commit()`
 * / `rollBack()`, so `$pdo->inTransaction()` agrees with
 * `Transaction::inTransaction()`.
 *
 * ## Three usage shapes
 *
 * ### 1. Closure (preferred — auto rollback on throw)
 *
 *     Transaction::run(function (): void {
 *         User::create([...]);
 *         AuditLog::create([...]);
 *     });
 *
 * Throws → ROLLBACK. Returns → COMMIT. The callable's return value
 * is forwarded.
 *
 * ### 2. Manual begin / commit / rollback
 *
 *     Transaction::begin();
 *     try {
 *         User::create([...]);
 *         AuditLog::create([...]);
 *         Transaction::commit();
 *     } catch (\Throwable $e) {
 *         Transaction::rollback();
 *         throw $e;
 *     }
 *
 * ### 3. Inspection
 *
 *     Transaction::inTransaction();   // bool — any depth > 0
 *     Transaction::depth();           // current SAVEPOINT depth (0 outside)
 *
 * ## Connection name
 *
 * Every method accepts an optional `$connection` string (defaults to
 * `'default'`). The framework reads `Connection::pdo($connection)` to
 * resolve the underlying PDO. To run transactions against a non-default
 * connection just pass its name everywhere consistently:
 *
 *     Transaction::run(fn () => …, 'analytics');
 *
 * ## What this is NOT
 *
 *   - Not a distributed-transaction coordinator
 *   - Not a saga / outbox helper
 *   - Doesn't auto-retry on serialization failures (caller's job —
 *     wrap with a retry loop if needed)
 */
final class Transaction
{
    /**
     * Per-connection nesting depth. `0` = no transaction; `1` = the
     * outermost BEGIN; `2+` = SAVEPOINT levels above that.
     *
     * @var array<string, int>
     */
    private static array $depth = [];

    public static function begin(string $connection = 'default'): void
    {
        $pdo = Connection::pdo($connection);
        $depth = self::$depth[$connection] ?? 0;

        if ($depth === 0) {
            self::guard(static fn () => $pdo->beginTransaction(), 'BEGIN');
        } else {
            self::exec($pdo, 'SAVEPOINT ' . self::savepointName($depth));
        }
        self::$depth[$connection] = $depth + 1;
    }

    public static function commit(string $connection = 'default'): void
    {
        $depth = self::$depth[$connection] ?? 0;
        if ($depth === 0) {
            throw new \LogicException(
                "Transaction::commit('$connection'): no active transaction",
            );
        }
        $pdo = Connection::pdo($connection);

        if ($depth === 1) {
            // A DDL statement on MySQL commits implicitly; PDO then has
            // nothing left to commit and would throw. Treat it as done.
            if ($pdo->inTransaction()) {
                self::guard(static fn () => $pdo->commit(), 'COMMIT');
            }
        } else {
            // Inner commit releases the matching savepoint.
            self::exec($pdo, 'RELEASE SAVEPOINT ' . self::savepointName($depth - 1));
        }
        self::$depth[$connection] = $depth - 1;
    }

    /**
     * Roll back to the most recent BEGIN / SAVEPOINT. Idempotent when
     * there's nothing to roll back (no exception), so it's safe to call
     * from `catch` / `finally` blocks even if the begin step didn't run.
     */
    public static function rollback(string $connection = 'default'): void
    {
        $depth = self::$depth[$connection] ?? 0;
        if ($depth === 0) {
            return;
        }
        $pdo = Connection::pdo($connection);

        // The counter drops even when the statement fails: the level is
        // gone either way, and a stuck counter would turn every later
        // begin() on this connection into a SAVEPOINT outside a transaction.
        self::$depth[$connection] = $depth - 1;

        if ($depth === 1) {
            if ($pdo->inTransaction()) {
                self::guard(static fn () => $pdo->rollBack(), 'ROLLBACK');
            }
        } else {
            self::exec($pdo, 'ROLLBACK TO SAVEPOINT ' . self::savepointName($depth - 1));
        }
    }

    public static function inTransaction(string $connection = 'default'): bool
    {
        return (self::$depth[$connection] ?? 0) > 0;
    }

    public static function depth(string $connection = 'default'): int
    {
        return self::$depth[$connection] ?? 0;
    }

    /**
     * Run `$fn` inside a transaction. Commits on clean return, rolls
     * back and rethrows on any `\Throwable`. The callable's return
     * value is forwarded.
     *
     * Nested calls are safe — inner invocations use SAVEPOINTs.
     *
     *   $orderId = Transaction::run(function () use ($payload) {
     *       $order = Order::create($payload);
     *       Inventory::reserve($order->id, $payload['items']);
     *       return $order->id;
     *   });
     */
    public static function run(callable $fn, string $connection = 'default'): mixed
    {
        self::begin($connection);
        try {
            $result = $fn();
            self::commit($connection);
            return $result;
        } catch (\Throwable $e) {
            try {
                self::rollback($connection);
            } catch (\Throwable) {
                // The failure that brought us here is the one worth
                // reporting (a dropped connection fails the rollback too).
            }
            throw $e;
        }
    }

    /**
     * **Test-only.** Reset the framework's per-connection depth counter
     * — useful between tests that share a connection pool. Does NOT
     * touch the underlying PDO; if the DB is mid-transaction the caller
     * must reconcile manually.
     */
    public static function reset(?string $connection = null): void
    {
        if ($connection === null) {
            self::$depth = [];
        } else {
            unset(self::$depth[$connection]);
        }
    }

    /**
     * Savepoint statements go through `PDO::exec()`, not a prepared
     * statement: MySQL refuses to prepare them when emulated prepares
     * are off.
     */
    private static function exec(\PDO $pdo, string $sql): void
    {
        self::guard(static fn () => $pdo->exec($sql), $sql);
    }

    /** Runs a PDO call, turning a `\PDOException` into a `StorageException`. */
    private static function guard(callable $call, string $sql): void
    {
        try {
            $call();
        } catch (\PDOException $e) {
            throw StorageException::wrap($e, $sql);
        }
    }

    private static function savepointName(int $level): string
    {
        return 'cloude_sp_' . $level;
    }
}
