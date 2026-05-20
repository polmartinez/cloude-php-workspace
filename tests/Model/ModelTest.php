<?php

declare(strict_types=1);

namespace Cloude\Tests\Model;

use Cloude\Config;
use Cloude\Model\Model;
use Cloude\Model\Storage\ArrayStorage;
use Cloude\Model\Storage\PdoStorage;
use Cloude\Storage\Connection;
use Cloude\Testing\TestCase;

final class TestUser extends Model
{
    protected static string $table = 'users';
    /** @var list<string> */
    protected static array $properties = ['id', 'email', 'name', 'active'];
}

final class UnconfiguredModel extends Model
{
    protected static string $table = 'unconfigured';
    protected static string|array $connection = 'definitely_not_in_config';
}

final class AutoResolvedModel extends Model
{
    protected static string $table = 'autoresolved';
    protected static string|array $connection = 'fake';
}

/**
 * Demonstrates the inline `$connection` array form (v0.23+): no
 * `storage.php` entry needed; the config IS the property value.
 * Only works for file-based drivers (json / json_collection / array)
 * because PDO connections must be named for the pool.
 */
final class InlineConfigModel extends Model
{
    protected static string $table = 'inline';
    protected static string|array $connection = [
        'driver' => 'array',
        'data'   => [
            ['id' => 'a', 'name' => 'Alpha'],
            ['id' => 'b', 'name' => 'Beta'],
        ],
        'primary_key' => 'id',
    ];
    protected static string $primaryKey = 'id';
}

final class ModelTest extends TestCase
{
    protected function setUp(): void
    {
        TestUser::configure(new ArrayStorage([
            ['id' => 1, 'email' => 'ada@example.com',  'name' => 'Ada',  'active' => 1],
            ['id' => 2, 'email' => 'alan@example.com', 'name' => 'Alan', 'active' => 1],
            ['id' => 3, 'email' => 'gone@example.com', 'name' => 'Gone', 'active' => 0],
        ]));
    }

    public function testFindReturnsSubclassInstance(): void
    {
        $u = TestUser::find(1);
        self::assertInstanceOf(TestUser::class, $u);
        self::assertSame('Ada', $u->name);
        self::assertTrue($u->isPersisted());
    }

    public function testFindReturnsNullForMissing(): void
    {
        self::assertNull(TestUser::find(999));
    }

    public function testFindByEqualityCriteria(): void
    {
        $rows = TestUser::findBy(['active' => 1]);
        self::assertCount(2, $rows);
        $names = array_map(static fn ($r) => $r->name, $rows);
        self::assertContains('Ada', $names);
        self::assertContains('Alan', $names);
    }

    public function testFindByOrderAndLimit(): void
    {
        $rows = TestUser::findBy([], limit: 2, orderBy: ['name' => 'DESC']);
        self::assertCount(2, $rows);
        self::assertSame('Gone', $rows[0]->name);
    }

    public function testCount(): void
    {
        self::assertSame(3, TestUser::count());
        self::assertSame(2, TestUser::count(['active' => 1]));
    }

    public function testCreatePersistsAndAssignsId(): void
    {
        $u = TestUser::create(['email' => 'grace@example.com', 'name' => 'Grace', 'active' => 1]);
        self::assertNotNull($u->id);
        self::assertTrue($u->isPersisted());
        self::assertSame(4, TestUser::count());
    }

    public function testSaveOnLoadedInstanceUpdates(): void
    {
        $u = TestUser::find(1);
        $u->name = 'Ada Lovelace';
        $u->save();

        $reloaded = TestUser::find(1);
        self::assertSame('Ada Lovelace', $reloaded->name);
    }

    public function testDeleteRemovesAndFlipsPersistedFlag(): void
    {
        $u = TestUser::find(2);
        self::assertTrue($u->delete());
        self::assertFalse($u->isPersisted());
        self::assertNull(TestUser::find(2));
    }

    public function testToArrayRoundTrip(): void
    {
        $u = TestUser::find(1);
        self::assertSame(
            ['id' => 1, 'email' => 'ada@example.com', 'name' => 'Ada', 'active' => 1],
            $u->toArray(),
        );
    }

    public function testWhitelistRejectsUnknownAttribute(): void
    {
        $u = new TestUser();
        $this->expectException(\InvalidArgumentException::class);
        $u->bogus = 'nope';
    }

    public function testStorageUnconfiguredAndUnresolvableThrows(): void
    {
        // No explicit configure() AND no matching config entry → auto-resolve
        // fails, which we re-throw as a RuntimeException with context.
        Connection::reset();
        Config::reset();
        Config::setConfigPath(sys_get_temp_dir());      // path with no config files
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Could not auto-resolve storage for');
        UnconfiguredModel::find(1);
    }

    public function testConfigureAcceptsInlineConfigArray(): void
    {
        // Pass a config dict to configure() instead of a Storage instance.
        // Dispatches through Factory::makeFromConfig.
        TestUser::configure([
            'driver' => 'array',
            'data' => [
                ['id' => 100, 'email' => 'inline@x', 'name' => 'Inline', 'active' => 1],
            ],
            'primary_key' => 'id',
        ]);

        $u = TestUser::find(100);
        self::assertNotNull($u);
        self::assertSame('Inline', $u->name);
    }

    public function testConfigureRunsTwiceForContextSwapping(): void
    {
        // The "one class, many partitions" pattern: configure() replaces
        // the cached storage each call. The Party use case in politica-esp.
        TestUser::configure([
            'driver' => 'array',
            'data'   => [['id' => 1, 'name' => 'Region A', 'email' => '', 'active' => 1]],
            'primary_key' => 'id',
        ]);
        self::assertSame('Region A', TestUser::find(1)->name);

        TestUser::configure([
            'driver' => 'array',
            'data'   => [['id' => 1, 'name' => 'Region B', 'email' => '', 'active' => 1]],
            'primary_key' => 'id',
        ]);
        self::assertSame('Region B', TestUser::find(1)->name);
    }

    public function testInlineArrayConnectionResolvesWithoutConfig(): void
    {
        // No Cloude\Config setup at all. The model carries its own config
        // inline. find() should still work.
        \Cloude\Storage\Connection::reset();
        \Cloude\Config::reset();
        // Wipe any stale storage on the class:
        $rc = new \ReflectionClass(Model::class);
        $rp = $rc->getProperty('storages');
        $rp->setValue(null, []);

        $row = InlineConfigModel::find('a');
        self::assertNotNull($row);
        self::assertSame('Alpha', $row->name);

        self::assertSame(2, InlineConfigModel::count());
    }

    public function testStorageAutoResolvesFromConfig(): void
    {
        // Spin up a tmp config with an 'array' driver entry and confirm the
        // model picks it up on first use without any configure() call.
        $tmp = sys_get_temp_dir() . '/cloude-model-autoresolve-' . bin2hex(random_bytes(4));
        mkdir($tmp, 0755, true);
        file_put_contents($tmp . '/storage.php', "<?php return [
            'fake' => ['driver' => 'array', 'data' => [
                ['id' => 99, 'email' => 'auto@x', 'name' => 'Auto', 'active' => 1],
            ]],
        ];");

        Connection::reset();
        Connection::setConfigName('storage');
        Config::reset();
        Config::setConfigPath($tmp);

        $u = AutoResolvedModel::find(99);
        self::assertNotNull($u);
        self::assertSame('Auto', $u->name);

        @unlink($tmp . '/storage.php');
        @rmdir($tmp);
    }

    public function testStorageEscapeHatchIsPublic(): void
    {
        // Documented: User::storage() is public — used to drop down to the
        // adapter's native API (e.g. PdoStorage::pdo()).
        $s = TestUser::storage();
        self::assertInstanceOf(ArrayStorage::class, $s);
    }

    public function testQueryShortcutRequiresPdoStorage(): void
    {
        // ArrayStorage doesn't ship a query builder by design.
        $this->expectException(\LogicException::class);
        TestUser::query();
    }

    public function testQueryAndHydrateRoundTrip(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->exec('CREATE TABLE users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            email TEXT, name TEXT, active INTEGER
        )');
        $pdo->exec("INSERT INTO users (email, name, active) VALUES
            ('ada@x',   'Ada',   1),
            ('alan@x',  'Alan',  1),
            ('linus@x', 'Linus', 0)");

        TestUser::configure(new PdoStorage($pdo, 'users'));

        // Drop down to the query builder for richer predicates ...
        $rows = TestUser::query()
            ->where('active', 1)
            ->orderBy('name')
            ->get();
        self::assertCount(2, $rows);
        self::assertSame('Ada', $rows[0]['name']);

        // ... then lift rows back into Model instances via the (now public) hydrate().
        $users = array_map(static fn (array $r) => TestUser::hydrate($r), $rows);
        self::assertInstanceOf(TestUser::class, $users[0]);
        self::assertTrue($users[0]->isPersisted());
        self::assertSame('Ada', $users[0]->name);
    }

    // ── static table / field / alias helpers ─────────────────────────────

    public function testTableReturnsStaticTableName(): void
    {
        self::assertSame('users', TestUser::table());
    }

    public function testFieldQualifiesColumn(): void
    {
        self::assertSame('users.email', TestUser::field('email'));
        self::assertSame('users.*', TestUser::field('*'));
    }

    public function testAsReturnsTableRefWithAlias(): void
    {
        $ref = TestUser::as('u');
        self::assertInstanceOf(\Cloude\Storage\TableRef::class, $ref);
        self::assertSame('users', $ref->table);
        self::assertSame('u', $ref->alias);
        self::assertSame('u.email', $ref->field('email'));
        self::assertSame('`users` AS `u`', $ref->expression());
    }

    public function testRefReturnsTableRefWithoutAlias(): void
    {
        $ref = TestUser::ref();
        self::assertSame('users', $ref->table);
        self::assertNull($ref->alias);
        self::assertSame('users.email', $ref->field('email'));
    }

    public function testAliasHelperReturnsTuple(): void
    {
        self::assertSame(
            ['users.name', 'user_name'],
            TestUser::alias('name', 'user_name'),
        );
    }

    // ── $indexes / $foreignKeys emitters ─────────────────────────────────

    public function testIndexesSqlReturnsListOfStatements(): void
    {
        self::assertSame(
            [
                'CREATE UNIQUE INDEX `uq_widgets_slug` ON `widgets` (`slug`)',
                'CREATE INDEX `idx_widgets_owner_id` ON `widgets` (`owner_id`)',
            ],
            ConstrainedWidget::indexesSql(),
        );
    }

    public function testForeignKeysSqlReturnsListOfStatements(): void
    {
        self::assertSame(
            [
                'ALTER TABLE `widgets` ADD CONSTRAINT `fk_widgets_owner_id` FOREIGN KEY (`owner_id`)'
                . ' REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE',
            ],
            ConstrainedWidget::foreignKeysSql(),
        );
    }

    public function testEmptyDeclarationsReturnEmptyLists(): void
    {
        self::assertSame([], TestUser::indexesSql());
        self::assertSame([], TestUser::foreignKeysSql());
    }

    // ── auto-timestamps ──────────────────────────────────────────────────

    public function testInsertAutoStampsCreatedAtAndUpdatedAt(): void
    {
        TimestampedThing::configure(new \Cloude\Model\Storage\ArrayStorage([]));

        $now = '2026-05-18 12:00:00';
        \Cloude\DateTime::setTestNow(new \Cloude\DateTime($now));
        try {
            $t = TimestampedThing::create(['name' => 'widget']);
            self::assertSame($now, $t->created_at);
            self::assertSame($now, $t->updated_at);
        } finally {
            \Cloude\DateTime::clearTestNow();
        }
    }

    public function testUpdateOnlyTouchesUpdatedAt(): void
    {
        $store = new \Cloude\Model\Storage\ArrayStorage([]);
        TimestampedThing::configure($store);

        \Cloude\DateTime::setTestNow(new \Cloude\DateTime('2026-05-18 12:00:00'));
        $t = TimestampedThing::create(['name' => 'widget']);
        $createdAt = $t->created_at;

        \Cloude\DateTime::setTestNow(new \Cloude\DateTime('2026-05-19 09:00:00'));
        try {
            $t->name = 'widget v2';
            $t->save();
            self::assertSame($createdAt, $t->created_at);             // not touched on update
            self::assertSame('2026-05-19 09:00:00', $t->updated_at);  // moved
        } finally {
            \Cloude\DateTime::clearTestNow();
        }
    }

    public function testExplicitCreatedAtIsNotOverwritten(): void
    {
        // Useful for imports — caller supplied created_at explicitly.
        TimestampedThing::configure(new \Cloude\Model\Storage\ArrayStorage([]));
        \Cloude\DateTime::setTestNow(new \Cloude\DateTime('2026-05-18 12:00:00'));
        try {
            $t = TimestampedThing::create([
                'name'       => 'imported',
                'created_at' => '2020-01-01 00:00:00',
            ]);
            self::assertSame('2020-01-01 00:00:00', $t->created_at);
            self::assertSame('2026-05-18 12:00:00', $t->updated_at);
        } finally {
            \Cloude\DateTime::clearTestNow();
        }
    }

    public function testCustomColumnNamesAreHonoured(): void
    {
        CustomTimestamped::configure(new \Cloude\Model\Storage\ArrayStorage([]));
        \Cloude\DateTime::setTestNow(new \Cloude\DateTime('2026-05-18 12:00:00'));
        try {
            $t = CustomTimestamped::create(['name' => 'x']);
            self::assertSame('2026-05-18 12:00:00', $t->inserted_at);
            self::assertSame('2026-05-18 12:00:00', $t->modified_at);
        } finally {
            \Cloude\DateTime::clearTestNow();
        }
    }

    public function testColumnNotInPropertiesIsSilentlySkipped(): void
    {
        // TestUser declares only ['id', 'email', 'name', 'active'] —
        // no created_at / updated_at. Save() must NOT try to write
        // those fields. (No exception, no silent extra row data.)
        TestUser::configure(new \Cloude\Model\Storage\ArrayStorage([]));
        $u = TestUser::create(['email' => 'ada@x', 'name' => 'Ada', 'active' => 1]);

        // Inspect the underlying storage directly to verify nothing
        // outside the declared shape sneaked in.
        $row = TestUser::storage()->find($u->id);
        self::assertArrayNotHasKey('created_at', $row);
        self::assertArrayNotHasKey('updated_at', $row);
    }

    public function testTimestampsCanBeDisabledPerModel(): void
    {
        // Same shape as TimestampedThing but with $timestamps = false.
        NoTimestamps::configure(new \Cloude\Model\Storage\ArrayStorage([]));
        $t = NoTimestamps::create(['name' => 'no-stamps']);
        self::assertNull($t->created_at);
        self::assertNull($t->updated_at);
    }
}

final class ConstrainedWidget extends \Cloude\Model\Model
{
    protected static string $table = 'widgets';

    /** @var list<array<string,mixed>> */
    protected static array $indexes = [
        ['type' => 'unique', 'columns' => ['slug']],
        ['type' => 'index',  'columns' => ['owner_id']],
    ];

    /** @var list<array<string,mixed>> */
    protected static array $foreignKeys = [
        [
            'columns'    => ['owner_id'],
            'references' => 'users',
            'on'         => ['id'],
            'on_delete'  => 'cascade',
            'on_update'  => 'cascade',
        ],
    ];
}

/**
 * Model with created_at + updated_at columns declared in $properties.
 * Save() should auto-stamp both on insert, and only updated_at on
 * subsequent saves.
 */
final class TimestampedThing extends \Cloude\Model\Model
{
    protected static string $table = 'things';
    protected static array $properties = ['id', 'name', 'created_at', 'updated_at'];
}

/** Same with custom column names. */
final class CustomTimestamped extends \Cloude\Model\Model
{
    protected static string $table = 'things';
    protected static array $properties = ['id', 'name', 'inserted_at', 'modified_at'];
    protected static ?string $createdAt = 'inserted_at';
    protected static ?string $updatedAt = 'modified_at';
}

/** Opt-out: $timestamps = false disables the feature for the model. */
final class NoTimestamps extends \Cloude\Model\Model
{
    protected static string $table = 'things';
    protected static array $properties = ['id', 'name', 'created_at', 'updated_at'];
    protected static bool $timestamps = false;
}
