<?php

declare(strict_types=1);

namespace Cloude\Tests\Storage;

use Cloude\DateTime;
use Cloude\Model\Model;
use Cloude\Model\Storage\PdoStorage;
use Cloude\Storage\ColumnNotFoundException;
use Cloude\Storage\Connection;
use Cloude\Storage\DuplicateKeyException;
use Cloude\Storage\Query;
use Cloude\Storage\Schema;
use Cloude\Storage\StorageException;
use Cloude\Storage\TableNotFoundException;
use Cloude\Storage\Transaction;
use Cloude\Testing\TestCase;

final class PgUser extends Model
{
    protected static string $table = 'cloude_it_users';
    /** @var list<string> */
    protected static array $properties = ['id', 'email', 'name', 'age', 'active', 'flag', 'meta', 'created_at'];
    /** @var array<string,string> */
    protected static array $types = [
        'id'         => 'int',
        'age'        => 'int',
        'active'     => 'bool',
        'meta'       => 'json',
        'created_at' => 'datetime',
    ];
}

/**
 * Runs against a real PostgreSQL server, and only when one is named:
 *
 *   CLOUDE_TEST_PGSQL_DSN='pgsql:host=127.0.0.1;port=5432;dbname=cloude_test' \
 *   CLOUDE_TEST_PGSQL_USER=postgres CLOUDE_TEST_PGSQL_PASS=secret composer test
 *
 * Without the DSN every test here is reported as skipped. The tables
 * it creates are prefixed `cloude_it_` and dropped afterwards.
 */
final class PgsqlIntegrationTest extends TestCase
{
    private \PDO $pdo;

    protected function setUp(): void
    {
        $dsn = getenv('CLOUDE_TEST_PGSQL_DSN');
        if (!is_string($dsn) || $dsn === '') {
            $this->markTestSkipped('CLOUDE_TEST_PGSQL_DSN is not set');
        }
        $this->pdo = new \PDO(
            $dsn,
            getenv('CLOUDE_TEST_PGSQL_USER') ?: null,
            getenv('CLOUDE_TEST_PGSQL_PASS') ?: null,
            [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION],
        );
        $this->pdo->exec('DROP TABLE IF EXISTS cloude_it_users, cloude_it_tags');
        $this->pdo->exec('CREATE TABLE cloude_it_users (
            id         SERIAL PRIMARY KEY,
            email      TEXT UNIQUE,
            name       TEXT,
            age        INTEGER,
            active     BOOLEAN DEFAULT true,
            flag       SMALLINT DEFAULT 1,
            meta       JSONB,
            created_at TIMESTAMP
        )');
        $this->pdo->exec('CREATE TABLE cloude_it_tags (slug TEXT PRIMARY KEY, label TEXT)');

        Connection::set('cloude_it_pg', $this->pdo);
        PgUser::configure(new PdoStorage($this->pdo, 'cloude_it_users'));
    }

    protected function tearDown(): void
    {
        if (!isset($this->pdo)) {
            return;
        }
        Transaction::reset('cloude_it_pg');
        if ($this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
        $this->pdo->exec('DROP TABLE IF EXISTS cloude_it_users, cloude_it_tags');
        Connection::reset('cloude_it_pg');
    }

    private function users(): Query
    {
        return new Query($this->pdo, 'cloude_it_users');
    }

    public function testIdentifiersAreDoubleQuotedWithoutBeingAsked(): void
    {
        self::assertSame('SELECT * FROM "cloude_it_users" WHERE "age" > 18', $this->users()->where('age', '>', 18)->compile());
        self::assertSame([], $this->users()->get());
    }

    public function testInsertReturnsTheGeneratedKey(): void
    {
        self::assertSame(1, $this->users()->insert(['email' => 'a@x']));
        self::assertSame(2, $this->users()->insert(['email' => 'b@x']));
        self::assertSame('c@x', $this->users()->insert(['email' => 'c@x'], returning: 'email'));
    }

    public function testInsertIntoATableWithoutASequenceDoesNotReportAnotherTablesKey(): void
    {
        $this->users()->insert(['email' => 'a@x']);          // advances a sequence in this session

        $tags = new Query($this->pdo, 'cloude_it_tags');
        self::assertNull($tags->insert(['slug' => 's1', 'label' => 'One']));

        // Through the storage adapter the declared key comes back.
        $storage = new PdoStorage($this->pdo, 'cloude_it_tags', 'slug');
        self::assertSame('s2', $storage->insert(['slug' => 's2', 'label' => 'Two']));
    }

    public function testBooleansGoIntoBooleanAndIntegerColumns(): void
    {
        $this->users()->insert(['email' => 't@x', 'active' => true, 'flag' => true]);
        $this->users()->insert(['email' => 'f@x', 'active' => false, 'flag' => false]);

        self::assertSame(['t@x'], $this->users()->where('active', true)->pluck('email'));
        self::assertSame(['f@x'], $this->users()->where('flag', false)->pluck('email'));
        self::assertSame(['t@x'], $this->users()->where('active', 1)->pluck('email'));
    }

    public function testGroupByHavingAndGroupedCount(): void
    {
        foreach ([['a@x', true], ['b@x', true], ['c@x', false]] as [$email, $active]) {
            $this->users()->insert(['email' => $email, 'active' => $active]);
        }

        $rows = $this->users()
            ->select('active')->selectRaw('COUNT(*)', 'n')
            ->groupBy('active')->havingRaw('COUNT(*) >= ?', [2])
            ->get();
        self::assertSame([['active' => true, 'n' => 2]], $rows);

        self::assertSame(2, $this->users()->groupBy('active')->count());
        self::assertSame(3, $this->users()->count());
    }

    public function testUpdateAndDeleteHonourOrderByAndLimit(): void
    {
        foreach ([10, 20, 30] as $age) {
            $this->users()->insert(['email' => "u$age@x", 'age' => $age]);
        }

        self::assertSame(1, $this->users()->where('age', '>', 0)->orderBy('age', 'DESC')->limit(1)->update(['name' => 'oldest']));
        self::assertSame('u30@x', $this->users()->where('name', 'oldest')->value('email'));

        self::assertSame(2, $this->users()->orderBy('age')->limit(2)->delete());
        self::assertSame(['u30@x'], $this->users()->pluck('email'));
    }

    public function testIlikeIsCaseInsensitive(): void
    {
        $this->users()->insert(['email' => 'a@x', 'name' => 'Ada']);

        self::assertSame(0, $this->users()->where('name', 'LIKE', 'ada%')->count());
        self::assertSame(1, $this->users()->where('name', 'ILIKE', 'ada%')->count());
    }

    public function testErrorsAreTyped(): void
    {
        $this->users()->insert(['email' => 'a@x']);

        $caught = [];
        foreach ([
            fn () => $this->users()->insert(['email' => 'a@x']),
            fn () => (new Query($this->pdo, 'cloude_it_nope'))->get(),
            fn () => $this->users()->where('nope', 1)->get(),
        ] as $call) {
            try {
                $call();
                $caught[] = null;
            } catch (StorageException $e) {
                $caught[] = $e::class;
            }
        }
        self::assertSame(
            [DuplicateKeyException::class, TableNotFoundException::class, ColumnNotFoundException::class],
            $caught,
        );
    }

    public function testModelRoundTripWithCasts(): void
    {
        $u = PgUser::create([
            'email'      => 'ada@x',
            'name'       => 'Ada',
            'age'        => 36,
            'active'     => true,
            'meta'       => ['langs' => ['en', 'fr']],
            'created_at' => new DateTime('2026-01-02 03:04:05'),
        ]);
        self::assertSame(1, $u->id);

        $read = PgUser::find(1);
        self::assertSame(36, $read->age);
        self::assertTrue($read->active);
        self::assertSame(['langs' => ['en', 'fr']], $read->meta);
        self::assertSame('2026-01-02 03:04:05', (string) $read->created_at);

        // Read and not touched: nothing to write.
        // (jsonb comes back re-spaced and the boolean as a real bool;
        // neither counts as a change.)
        self::assertSame([], $read->dirty());
        $read->active = false;
        self::assertTrue($read->isDirty('active'));
        self::assertFalse($read->isDirty('age'));
        $read->save();

        self::assertFalse(PgUser::find(1)->active);
        self::assertSame(1, PgUser::count(['active' => false]));

        $again = PgUser::updateOrCreate(['email' => 'ada@x'], ['name' => 'Ada Lovelace']);
        self::assertSame(1, $again->id);
        self::assertSame(1, PgUser::count());

        self::assertTrue($again->delete());
        self::assertNull(PgUser::find(1));
    }

    public function testNestedTransactionRecoversFromAFailedStatement(): void
    {
        Transaction::run(function (): void {
            $this->users()->insert(['email' => 'outer@x']);
            try {
                Transaction::run(function (): void {
                    $this->users()->insert(['email' => 'outer@x']);       // duplicate → aborts the inner level
                }, 'cloude_it_pg');
            } catch (DuplicateKeyException) {
                // PostgreSQL would refuse every later statement had the
                // savepoint not been rolled back.
            }
            $this->users()->insert(['email' => 'after@x']);
        }, 'cloude_it_pg');

        self::assertSame(['outer@x', 'after@x'], $this->users()->orderBy('id')->pluck('email'));
        self::assertFalse($this->pdo->inTransaction());
    }

    public function testSchemaEmitsSqlPostgresAccepts(): void
    {
        $this->pdo->exec('DROP TABLE IF EXISTS cloude_it_tags');
        $sql = Schema::createTableSql(
            'cloude_it_tags',
            [
                'id'         => ['type' => 'int', 'primary' => true, 'auto_increment' => true],
                'slug'       => ['type' => 'varchar(32)', 'null' => false],
                'label'      => ['type' => 'longtext', 'null' => true, 'comment' => "the tag's label"],
                'hidden'     => ['type' => 'tinyint(1)', 'null' => false, 'default' => 0],
                'created_at' => ['type' => 'datetime', 'null' => true],
            ],
            [
                ['type' => 'unique', 'columns' => ['slug']],
                ['type' => 'index', 'columns' => ['created_at']],
            ],
            [],
            'pgsql',
        );
        $this->pdo->exec($sql);

        $tags = new Query($this->pdo, 'cloude_it_tags');
        self::assertSame(1, $tags->insert(['slug' => 'one']));
        $this->expectException(DuplicateKeyException::class);
        $tags->insert(['slug' => 'one']);
    }
}
