<?php

declare(strict_types=1);

namespace Cloude\Tests\Storage;

use Cloude\Storage\Query;
use Cloude\Testing\TestCase;

/**
 * Runs against a real MySQL / MariaDB server, and only when one is named:
 *
 *   CLOUDE_TEST_MYSQL_DSN='mysql:host=127.0.0.1;port=3306;dbname=cloude_test' \
 *   CLOUDE_TEST_MYSQL_USER=root CLOUDE_TEST_MYSQL_PASS=secret composer test
 *
 * Without the DSN every test here is reported as skipped. Covers what
 * SQLite cannot stand in for: the `ON DUPLICATE KEY UPDATE` upsert.
 * The table it creates is prefixed `cloude_it_` and dropped afterwards.
 */
final class MysqlIntegrationTest extends TestCase
{
    private \PDO $pdo;

    protected function setUp(): void
    {
        $dsn = getenv('CLOUDE_TEST_MYSQL_DSN');
        if (!is_string($dsn) || $dsn === '') {
            $this->markTestSkipped('CLOUDE_TEST_MYSQL_DSN is not set');
        }
        $this->pdo = new \PDO(
            $dsn,
            getenv('CLOUDE_TEST_MYSQL_USER') ?: null,
            getenv('CLOUDE_TEST_MYSQL_PASS') ?: null,
            [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION],
        );
        $this->pdo->exec('DROP TABLE IF EXISTS cloude_it_targets');
        $this->pdo->exec('CREATE TABLE cloude_it_targets (
            id      INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            plan_id INT UNSIGNED NOT NULL,
            week_no INT UNSIGNED NOT NULL,
            value   INT NULL,
            note    VARCHAR(32) NULL,
            UNIQUE KEY uq_cloude_it_targets (plan_id, week_no)
        ) ENGINE=InnoDB');
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo)) {
            $this->pdo->exec('DROP TABLE IF EXISTS cloude_it_targets');
        }
    }

    private function q(): Query
    {
        return new Query($this->pdo, 'cloude_it_targets');
    }

    public function testUpsertInsertsThenUpdatesTheSameRow(): void
    {
        $this->q()->upsert(['plan_id' => 1, 'week_no' => 2, 'value' => 5, 'note' => 'first'], ['plan_id', 'week_no']);
        $this->q()->upsert(['plan_id' => 1, 'week_no' => 2, 'value' => 9, 'note' => 'second'], ['plan_id', 'week_no']);

        self::assertSame(1, $this->q()->count());
        self::assertEquals(['value' => 9, 'note' => 'second'], $this->q()->select('value', 'note')->first());
    }

    public function testUpsertUpdateListAndDoNothing(): void
    {
        $this->q()->upsert(['plan_id' => 1, 'week_no' => 2, 'value' => 5, 'note' => 'keep'], ['plan_id', 'week_no']);
        $this->q()->upsert(['plan_id' => 1, 'week_no' => 2, 'value' => 9, 'note' => 'ignored'], ['plan_id', 'week_no'], ['value']);
        self::assertEquals(['value' => 9, 'note' => 'keep'], $this->q()->select('value', 'note')->first());

        $this->q()->upsert(['plan_id' => 1, 'week_no' => 2, 'value' => 1, 'note' => 'x'], ['plan_id', 'week_no'], []);
        self::assertEquals(['value' => 9, 'note' => 'keep'], $this->q()->select('value', 'note')->first());
        self::assertSame(1, $this->q()->count());
    }

    public function testInsertStillReturnsTheGeneratedKey(): void
    {
        self::assertSame(1, $this->q()->insert(['plan_id' => 1, 'week_no' => 1]));
        self::assertSame(2, $this->q()->insert(['plan_id' => 1, 'week_no' => 2]));
        self::assertSame(1, $this->q()->where('plan_id', '>', 0)->orderBy('id')->limit(1)->delete());
    }
}
