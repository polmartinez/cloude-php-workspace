<?php

declare(strict_types=1);

namespace Cloude\Tests\Cache;

use Cloude\Cache\Cache;
use Cloude\Cache\Store\RedisStore;
use Cloude\Config;
use Cloude\Session;
use Cloude\Testing\TestCase;

/**
 * Runs against a real Redis server, and only when one is named and
 * ext-redis is loaded:
 *
 *   CLOUDE_TEST_REDIS_HOST=127.0.0.1 CLOUDE_TEST_REDIS_PORT=6379 composer test
 *
 * Otherwise every test here is reported as skipped. Each test works
 * under its own random key prefix and removes what it wrote; nothing
 * outside that prefix is touched (no FLUSHDB).
 */
final class RedisIntegrationTest extends TestCase
{
    private string $host;
    private int $port;
    private string $prefix;
    private \Redis $raw;
    private ?string $tmpConfig = null;

    protected function setUp(): void
    {
        $host = getenv('CLOUDE_TEST_REDIS_HOST');
        if (!is_string($host) || $host === '') {
            $this->markTestSkipped('CLOUDE_TEST_REDIS_HOST is not set');
        }
        if (!extension_loaded('redis')) {
            $this->markTestSkipped('ext-redis is not loaded');
        }
        $this->host   = $host;
        $this->port   = (int) (getenv('CLOUDE_TEST_REDIS_PORT') ?: 6379);
        $this->prefix = 'cloude_it_' . bin2hex(random_bytes(4)) . ':';

        $this->raw = new \Redis();
        $this->raw->connect($this->host, $this->port, 2.0);
    }

    protected function tearDown(): void
    {
        if (!isset($this->raw)) {
            return;
        }
        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION = [];
            @session_destroy();
        }
        ini_restore('session.save_handler');
        ini_restore('session.save_path');

        foreach ($this->raw->keys($this->prefix . '*') ?: [] as $key) {
            $this->raw->del($key);
        }
        $this->raw->close();

        Cache::reset();
        if ($this->tmpConfig !== null) {
            Config::reset();
            @unlink($this->tmpConfig . '/cache.php');
            @unlink($this->tmpConfig . '/session.php');
            @rmdir($this->tmpConfig);
        }
    }

    private function store(array $extra = []): RedisStore
    {
        return new RedisStore(['host' => $this->host, 'port' => $this->port, 'prefix' => $this->prefix] + $extra);
    }

    private function writeConfig(string $file, array $config): void
    {
        if ($this->tmpConfig === null) {
            $this->tmpConfig = sys_get_temp_dir() . '/cloude-redis-cfg-' . bin2hex(random_bytes(4));
            mkdir($this->tmpConfig, 0700, true);
        }
        file_put_contents($this->tmpConfig . '/' . $file, '<?php return ' . var_export($config, true) . ';');
        Config::reset();
        Config::configure($this->tmpConfig);
    }

    public function testRoundTripsValuesOfEveryShape(): void
    {
        $store = $this->store();
        $values = [
            'string' => 'hola',
            'int'    => 42,
            'float'  => 1.5,
            'true'   => true,
            'false'  => false,
            'null'   => null,
            'array'  => ['rows' => [['id' => 1, 'name' => 'Ada'], ['id' => 2, 'name' => 'Ñu']]],
        ];
        foreach ($values as $key => $value) {
            $store->set($key, $value);
        }
        foreach ($values as $key => $value) {
            self::assertTrue($store->has($key), "has($key)");
            self::assertSame($value, $store->get($key, 'MISS'), "get($key)");
        }
    }

    public function testMissReturnsTheDefault(): void
    {
        $store = $this->store();
        self::assertFalse($store->has('nope'));
        self::assertNull($store->get('nope'));
        self::assertSame('fallback', $store->get('nope', 'fallback'));
    }

    public function testForgetRemovesOneKey(): void
    {
        $store = $this->store();
        $store->set('a', 1);
        $store->set('b', 2);
        $store->forget('a');
        $store->forget('never-set');

        self::assertFalse($store->has('a'));
        self::assertSame(2, $store->get('b'));
    }

    public function testTtlIsSetOnTheServerAndZeroMeansNoExpiry(): void
    {
        $store = $this->store();
        $store->set('short', 'v', 120);
        $store->set('forever', 'v');

        $ttl = $this->raw->ttl($this->prefix . 'short');
        self::assertGreaterThan(0, $ttl);
        self::assertLessThanOrEqual(120, $ttl);
        self::assertSame(-1, $this->raw->ttl($this->prefix . 'forever'), '-1 = key without expiry');

        // Expired on the server → a miss here.
        $this->raw->pExpire($this->prefix . 'short', 1);
        usleep(20_000);
        self::assertFalse($store->has('short'));
        self::assertSame('gone', $store->get('short', 'gone'));
    }

    public function testFlushRemovesOnlyThisStoresPrefix(): void
    {
        $store = $this->store();
        for ($i = 0; $i < 1200; $i++) {                 // more than one SCAN page
            $store->set('k' . $i, $i);
        }
        $neighbour = new RedisStore(['host' => $this->host, 'port' => $this->port, 'prefix' => $this->prefix . 'other:']);
        // A different app on the same server: same server, another prefix.
        $outside = 'cloude_it_outside_' . bin2hex(random_bytes(4));
        $this->raw->set($outside, 'keep');

        try {
            $store->flush();

            self::assertSame([], $this->raw->keys($this->prefix . 'k*'));
            self::assertSame('keep', $this->raw->get($outside));
        } finally {
            $this->raw->del($outside);
        }
        unset($neighbour);
    }

    public function testStoresWithDifferentPrefixesDoNotSeeEachOther(): void
    {
        $a = $this->store();
        $b = new RedisStore(['host' => $this->host, 'port' => $this->port, 'prefix' => $this->prefix . 'b:']);

        $a->set('k', 'from-a');
        self::assertNull($b->get('k'));
        $b->set('k', 'from-b');
        self::assertSame('from-a', $a->get('k'));
    }

    public function testDatabaseOptionSelectsThatDatabase(): void
    {
        $store = $this->store(['database' => 1]);
        $store->set('k', 'in-db-1');

        self::assertFalse($this->raw->exists($this->prefix . 'k') > 0, 'not in database 0');
        $this->raw->select(1);
        try {
            self::assertTrue($this->raw->exists($this->prefix . 'k') > 0);
        } finally {
            $this->raw->del($this->prefix . 'k');
            $this->raw->select(0);
        }
    }

    public function testUnreachableServerFailsWithAClearError(): void
    {
        $store = new RedisStore(['host' => '127.0.0.1', 'port' => 1, 'timeout' => 0.5]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Failed to connect to Redis');
        $store->get('k');
    }

    public function testRejectedPasswordFailsWithTheSameError(): void
    {
        // The test server has no password: sending one is refused.
        $store = $this->store(['password' => 'not-the-password']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Failed to connect to Redis');
        $store->get('k');
    }

    public function testCacheFacadeUsesRedisFromConfig(): void
    {
        $this->writeConfig('cache.php', [
            'default' => 'redis',
            'redis'   => ['driver' => 'redis', 'host' => $this->host, 'port' => $this->port, 'prefix' => $this->prefix],
        ]);
        Cache::reset();

        self::assertInstanceOf(RedisStore::class, Cache::store());

        $calls = 0;
        $producer = function () use (&$calls): array {
            $calls++;
            return ['n' => 1];
        };
        self::assertSame(['n' => 1], Cache::remember('report', 60, $producer));
        self::assertSame(['n' => 1], Cache::remember('report', 60, $producer));
        self::assertSame(1, $calls);
        self::assertTrue($this->raw->exists($this->prefix . 'report') > 0);

        // null is a value, not a miss.
        $nulls = 0;
        Cache::remember('nothing', 60, function () use (&$nulls) {
            $nulls++;
            return null;
        });
        Cache::remember('nothing', 60, function () use (&$nulls) {
            $nulls++;
            return null;
        });
        self::assertSame(1, $nulls);
    }

    public function testSessionHandlerStoresTheSessionInRedis(): void
    {
        $this->writeConfig('session.php', [
            'handler' => 'redis',
            'name'    => 'CLOUDE_IT_SID',
            'redis'   => ['host' => $this->host, 'port' => $this->port, 'database' => 0, 'prefix' => $this->prefix . 'sess:'],
        ]);

        Session::set('user_id', 42);
        $id = Session::id();
        self::assertSame('redis', ini_get('session.save_handler'));
        session_write_close();

        $stored = $this->raw->get($this->prefix . 'sess:' . $id);
        self::assertIsString($stored);
        self::assertStringContainsString('user_id', $stored);

        // A later request with the same id reads it back.
        session_id($id);
        self::assertSame(42, Session::get('user_id'));
    }
}
