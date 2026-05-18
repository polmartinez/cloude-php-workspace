<?php

declare(strict_types=1);

namespace Cloude\Storage;

/**
 * Framework wrapper around `\PDOException`. Carried by every query that
 * goes through `Cloude\Storage\Query` or `Cloude\Model\Storage\PdoStorage`
 * so consumers can `catch (StorageException $e)` once, regardless of
 * driver, without leaking PDO into their layering.
 *
 * The original PDOException is always preserved as `getPrevious()`. The
 * SQL and bindings of the failing statement are exposed as public
 * readonly properties — handy for logging, **never** safe to echo to
 * end-users (bindings may carry secrets).
 *
 * Subclasses cover the common SQLSTATEs so callers can pattern-match
 * cheaply (no string parsing):
 *
 *   - {@see TableNotFoundException}        42S02 / 42P01
 *   - {@see ColumnNotFoundException}       42S22 / 42703
 *   - {@see DuplicateKeyException}         23000 (MySQL 1062) / 23505 (PG)
 *   - {@see IntegrityConstraintException}  23xxx (everything else)
 *   - {@see ConnectionException}           08xxx, HY000 driver-name issues
 *   - {@see SyntaxErrorException}          42000 / 42601
 *
 * Unmapped SQLSTATEs surface as the base `StorageException`.
 *
 *   try {
 *       User::query()->insert(['email' => $email]);
 *   } catch (\Cloude\Storage\DuplicateKeyException $e) {
 *       // friendly "already registered" message
 *   } catch (\Cloude\Storage\StorageException $e) {
 *       // anything else — log $e->sql, $e->bindings, $e->sqlState
 *   }
 */
class StorageException extends \RuntimeException
{
    /** @var list<mixed> */
    public readonly array $bindings;

    /**
     * @param list<mixed> $bindings
     */
    public function __construct(
        string $message,
        public readonly string $sqlState,
        public readonly string $sql,
        array $bindings,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
        $this->bindings = $bindings;
    }

    /**
     * Prepare and execute a statement, wrapping any `\PDOException` as a
     * `StorageException` (or one of its specialised subclasses).
     *
     * Returns the executed `\PDOStatement` so callers can `fetchAll()` /
     * `fetchColumn()` / read `rowCount()` as usual.
     *
     * @param list<mixed> $params
     */
    public static function execute(\PDO $pdo, string $sql, array $params = []): \PDOStatement
    {
        try {
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            return $stmt;
        } catch (\PDOException $e) {
            throw self::wrap($e, $sql, $params);
        }
    }

    /**
     * Build the right subclass for a given `\PDOException`. Dispatch is
     * on the SQLSTATE (`errorInfo[0]`); the MySQL-side driver code
     * (`errorInfo[1]`) refines the choice when SQLSTATE alone is too
     * coarse (e.g. MySQL collapses every integrity violation into 23000).
     *
     * @param list<mixed> $bindings
     */
    public static function wrap(\PDOException $e, string $sql, array $bindings = []): self
    {
        $info     = $e->errorInfo ?? [];
        $sqlState = (string) ($info[0] ?? $e->getCode() ?? '');
        $driverCode = $info[1] ?? null;
        $message  = $e->getMessage();

        return match (true) {
            $sqlState === '42S02' || $sqlState === '42P01'
                => new TableNotFoundException($message, $sqlState, $sql, $bindings, $e),
            $sqlState === '42S22' || $sqlState === '42703'
                => new ColumnNotFoundException($message, $sqlState, $sql, $bindings, $e),
            // Duplicate key: MySQL → SQLSTATE 23000 + driver code 1062.
            //                Postgres → SQLSTATE 23505.
            ($sqlState === '23000' && (int) $driverCode === 1062),
            $sqlState === '23505'
                => new DuplicateKeyException($message, $sqlState, $sql, $bindings, $e),
            str_starts_with($sqlState, '23')
                => new IntegrityConstraintException($message, $sqlState, $sql, $bindings, $e),
            str_starts_with($sqlState, '08')
                => new ConnectionException($message, $sqlState, $sql, $bindings, $e),
            $sqlState === '42000' || $sqlState === '42601'
                => new SyntaxErrorException($message, $sqlState, $sql, $bindings, $e),
            default
            => new self($message, $sqlState, $sql, $bindings, $e),
        };
    }
}
