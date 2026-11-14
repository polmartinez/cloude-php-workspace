<?php

declare(strict_types=1);

namespace Cloude\Tests\Model;

use Cloude\Config;
use Cloude\Model\Model;
use Cloude\Model\Storage\ArrayStorage;
use Cloude\Storage\Connection;
use Cloude\Storage\Transaction;
use Cloude\Testing\TestCase;

/** No $connection declared → 'default'. */
final class ConnUser extends Model
{
    protected static string $table = 'users';
    /** @var array<string,string> */
    protected static array $types = ['id' => 'int'];
}

final class ConnEvent extends Model
{
    protected static string $table = 'events';
    protected static string|array $connection = 'analytics';
    /** @var array<string,string> */
    protected static array $types = ['id' => 'int', 'user_id' => 'int'];
}

/** Second model on 'analytics': shares the PDO with ConnEvent. */
final class ConnEventTotal extends Model
{
    protected static string $table = 'event_totals';
    protected static string|array $connection = 'analytics';
}

/**
 * One model per named connection, resolved from `storage.php`. Each
 * name is a separate in-memory SQLite database here, so a row written
 * through one connection is invisible from the other.
 */
final class ModelConnectionsTest extends TestCase
{
    private string $configDir;

    protected function setUp(): void
    {
        $this->configDir = sys_get_temp_dir() . '/cloude-conn-' . bin2hex(random_bytes(4));
        mkdir($this->configDir, 0700, true);
        file_put_contents($this->configDir . '/storage.php', '<?php return ' . var_export([
            'default'   => ['driver' => 'pdo', 'dsn' => 'sqlite::memory:'],
            'analytics' => ['driver' => 'pdo', 'dsn' => 'sqlite::memory:'],
        ], true) . ';');
        Config::reset();
        Config::configure($this->configDir);
        Connection::reset();
        Model::resetStorage();

        Connection::pdo('default')->exec('CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT)');
        Connection::pdo('analytics')->exec('CREATE TABLE events (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, kind TEXT)');
        Connection::pdo('analytics')->exec('CREATE TABLE event_totals (kind TEXT PRIMARY KEY, n INTEGER)');
    }

    protected function tearDown(): void
    {
        Transaction::reset();
        Model::resetStorage();
        Connection::reset();
        Config::reset();
        @unlink($this->configDir . '/storage.php');
        @rmdir($this->configDir);
    }

    public function testEachModelUsesItsConnection(): void
    {
        self::assertSame('default', ConnUser::connectionName());
        self::assertSame('analytics', ConnEvent::connectionName());
        self::assertSame('analytics', ConnEventTotal::connectionName());

        self::assertSame(Connection::pdo('default'), ConnUser::storage()->pdo());
        self::assertSame(Connection::pdo('analytics'), ConnEvent::storage()->pdo());
        self::assertSame(ConnEvent::storage()->pdo(), ConnEventTotal::storage()->pdo(), 'same name → same PDO');
    }

    public function testRowsLandInTheModelsOwnDatabase(): void
    {
        $u = ConnUser::create(['name' => 'Ada']);
        ConnEvent::create(['user_id' => $u->id, 'kind' => 'login']);

        self::assertSame(1, (int) Connection::pdo('default')->query('SELECT COUNT(*) FROM users')->fetchColumn());
        self::assertSame(1, (int) Connection::pdo('analytics')->query('SELECT COUNT(*) FROM events')->fetchColumn());

        // The other database does not even have the table.
        self::assertFalse(
            (bool) Connection::pdo('default')->query("SELECT 1 FROM sqlite_master WHERE name = 'events'")->fetchColumn(),
        );

        self::assertSame('Ada', ConnUser::find(1)->name);
        self::assertSame('login', ConnEvent::query()->where('user_id', $u->id)->value('kind'));
        self::assertSame(1, ConnEvent::count());
    }

    public function testTransactionRunsOnTheModelsConnection(): void
    {
        ConnUser::create(['name' => 'kept']);

        try {
            ConnEvent::transaction(function (): void {
                ConnEvent::create(['user_id' => 1, 'kind' => 'a']);
                ConnEventTotal::upsert(['kind' => 'a'], ['n' => 1]);
                self::assertTrue(Transaction::inTransaction('analytics'));
                self::assertFalse(Transaction::inTransaction('default'));
                throw new \RuntimeException('rollback');
            });
        } catch (\RuntimeException) {
        }

        self::assertSame(0, ConnEvent::count(), 'both analytics models rolled back together');
        self::assertSame(0, ConnEventTotal::count());
        self::assertSame(1, ConnUser::count(), 'the default connection was not touched');

        $n = ConnEvent::transaction(function (): int {
            ConnEvent::create(['user_id' => 1, 'kind' => 'b']);
            return ConnEvent::count();
        });
        self::assertSame(1, $n);
        self::assertFalse(Transaction::inTransaction('analytics'));
    }

    public function testDefaultTransactionDoesNotCoverAnotherConnection(): void
    {
        try {
            Transaction::run(function (): void {
                ConnUser::create(['name' => 'gone']);
                ConnEvent::create(['user_id' => 1, 'kind' => 'stays']);
                throw new \RuntimeException('rollback');
            });                                                  // 'default' only
        } catch (\RuntimeException) {
        }

        self::assertSame(0, ConnUser::count());
        self::assertSame(1, ConnEvent::count(), 'analytics wrote outside the default transaction');
    }

    public function testConnectionNameIsNullWithoutANamedPdo(): void
    {
        ConnEvent::configure(new ArrayStorage([]));
        self::assertNull(ConnEvent::connectionName());

        $this->expectException(\LogicException::class);
        ConnEvent::transaction(fn () => null);
    }

    public function testConfigureWithAnotherPooledPdoIsReflected(): void
    {
        ConnEvent::configure(new \Cloude\Model\Storage\PdoStorage(Connection::pdo('default'), 'users'));
        self::assertSame('default', ConnEvent::connectionName());
    }

    public function testResetStorageGoesBackToTheDeclaredConnection(): void
    {
        ConnEvent::configure(new ArrayStorage([]));
        ConnEvent::resetStorage();
        self::assertSame('analytics', ConnEvent::connectionName());

        // Only that class was forgotten.
        $users = ConnUser::storage();
        ConnEvent::resetStorage();
        self::assertSame($users, ConnUser::storage());
    }

    public function testUnknownConnectionNameSaysWhichModelAndName(): void
    {
        $model = new class () extends Model {
            protected static string $table = 'x';
            protected static string|array $connection = 'replica';
        };

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("connection 'replica'");
        $model::find(1);
    }
}
