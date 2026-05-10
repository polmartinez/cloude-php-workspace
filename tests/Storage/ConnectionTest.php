<?php

declare(strict_types=1);

namespace Cloude\Tests\Storage;

use Cloude\Config;
use Cloude\Storage\Connection;
use PHPUnit\Framework\TestCase;

final class ConnectionTest extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/cloude-conn-' . bin2hex(random_bytes(4));
        mkdir($this->tmp, 0755, true);

        file_put_contents($this->tmp . '/db.php', "<?php return [
            'default'   => ['dsn' => 'sqlite::memory:'],
            'analytics' => ['dsn' => 'sqlite::memory:'],
        ];");

        Connection::reset();
        Config::reset();
        Config::setConfigPath($this->tmp);
        Config::setEnvironment('dev');
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tmp . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->tmp);
        Connection::reset();
        Config::reset();
    }

    public function testPdoReturnsInstanceFromConfig(): void
    {
        $pdo = Connection::pdo('default');
        self::assertInstanceOf(\PDO::class, $pdo);
    }

    public function testPdoIsCachedByName(): void
    {
        self::assertSame(Connection::pdo('default'), Connection::pdo('default'));
    }

    public function testDifferentNamesGiveDifferentInstances(): void
    {
        self::assertNotSame(Connection::pdo('default'), Connection::pdo('analytics'));
    }

    public function testThrowsForUnknownName(): void
    {
        $this->expectException(\RuntimeException::class);
        Connection::pdo('nope');
    }

    public function testSetAllowsTestInjection(): void
    {
        $injected = new \PDO('sqlite::memory:');
        Connection::set('default', $injected);
        self::assertSame($injected, Connection::pdo('default'));
    }

    public function testResetByNameDropsOnlyThatEntry(): void
    {
        $a = Connection::pdo('default');
        $b = Connection::pdo('analytics');
        Connection::reset('default');
        self::assertNotSame($a, Connection::pdo('default'));   // re-created
        self::assertSame($b, Connection::pdo('analytics'));    // still cached
    }

    public function testResetAllEmptiesPool(): void
    {
        Connection::pdo('default');
        Connection::pdo('analytics');
        Connection::reset();
        self::assertSame([], Connection::pool());
    }

    public function testDefaultsErrModeToException(): void
    {
        $pdo = Connection::pdo('default');
        self::assertSame(\PDO::ERRMODE_EXCEPTION, $pdo->getAttribute(\PDO::ATTR_ERRMODE));
    }

    public function testMissingDsnThrows(): void
    {
        file_put_contents($this->tmp . '/db.php', "<?php return ['broken' => ['user' => 'x']];");
        Config::reset();
        $this->expectException(\RuntimeException::class);
        Connection::pdo('broken');
    }
}
