<?php

declare(strict_types=1);

namespace Cloude\Tests\Cache;

use Cloude\Cache\Cache;
use Cloude\Cache\Store\ArrayStore;
use Cloude\Cache\Store\FileStore;
use Cloude\Config;
use Cloude\Testing\TestCase;

/**
 * Façade tests — driver-specific behavior lives in StoreTest. Here we
 * verify the static API: default-store resolution from config, override
 * via set(), and the remember() compute-once semantics.
 */
final class CacheTest extends TestCase
{
    private string $tmpConfig;

    protected function setUp(): void
    {
        // Set up an isolated config dir with just a cache.php so the
        // framework's bundled config (with redis/memcached defaults
        // that may not be available) doesn't leak in.
        $this->tmpConfig = sys_get_temp_dir() . '/cloude-cache-cfg-' . bin2hex(random_bytes(4));
        mkdir($this->tmpConfig, 0700, true);
        file_put_contents($this->tmpConfig . '/cache.php', '<?php return ' . var_export([
            'default' => 'array',
            'array'   => ['driver' => 'array'],
        ], true) . ';');

        Config::reset();
        Cache::reset();
        Config::configure($this->tmpConfig);
    }

    protected function tearDown(): void
    {
        Cache::reset();
        Config::reset();
        foreach (glob($this->tmpConfig . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->tmpConfig);
    }

    public function testDefaultStoreResolvesFromConfig(): void
    {
        self::assertInstanceOf(ArrayStore::class, Cache::store());
    }

    public function testSetGetForgetRoundtrip(): void
    {
        Cache::put('foo', 'bar', ttl: 60);
        self::assertSame('bar', Cache::get('foo'));
        self::assertTrue(Cache::has('foo'));

        Cache::forget('foo');
        self::assertFalse(Cache::has('foo'));
        self::assertNull(Cache::get('foo'));
        self::assertSame('default', Cache::get('foo', 'default'));
    }

    public function testRememberComputesOnceAndCachesResult(): void
    {
        $calls = 0;
        $producer = function () use (&$calls): string {
            $calls++;
            return 'expensive';
        };

        self::assertSame('expensive', Cache::remember('k', 60, $producer));
        self::assertSame('expensive', Cache::remember('k', 60, $producer));
        self::assertSame('expensive', Cache::remember('k', 60, $producer));

        self::assertSame(1, $calls);
    }

    public function testRememberCachesNullValuesToo(): void
    {
        $calls = 0;
        $producer = function () use (&$calls): mixed {
            $calls++;
            return null;
        };

        self::assertNull(Cache::remember('nullable', 60, $producer));
        self::assertNull(Cache::remember('nullable', 60, $producer));
        self::assertSame(1, $calls, 'remember() must not treat null as a miss');
    }

    public function testInjectStoreOverridesDefault(): void
    {
        $custom = new ArrayStore();
        $custom->set('seeded', 'yes');

        Cache::set('default', $custom);

        self::assertSame('yes', Cache::get('seeded'));
    }

    public function testFlushClearsStore(): void
    {
        Cache::put('a', 1);
        Cache::put('b', 2);
        Cache::flush();
        self::assertFalse(Cache::has('a'));
        self::assertFalse(Cache::has('b'));
    }

    public function testStoreReachesNamedStore(): void
    {
        // Inject a second named store and verify Cache::store('other') returns it.
        $other = new ArrayStore();
        Cache::set('other', $other);

        $other->set('only-in-other', 1);
        self::assertSame(1, Cache::store('other')->get('only-in-other'));
        self::assertNull(Cache::get('only-in-other'));   // default store is untouched
    }

    public function testUnknownStoreThrows(): void
    {
        $this->expectException(\RuntimeException::class);
        Cache::store('does-not-exist');
    }

    public function testDefaultFalseReturnsNullStore(): void
    {
        // Reconfigure with caching off.
        file_put_contents($this->tmpConfig . '/cache.php', '<?php return ' . var_export([
            'default' => false,
            'array'   => ['driver' => 'array'],
        ], true) . ';');
        Config::reset();
        Cache::reset();
        Config::configure($this->tmpConfig);

        self::assertInstanceOf(\Cloude\Cache\Store\NullStore::class, Cache::store());

        // Writes silently drop, reads always miss.
        Cache::put('k', 'v', 60);
        self::assertNull(Cache::get('k'));
        self::assertFalse(Cache::has('k'));

        // Named stores still work — only the *default* is off.
        self::assertInstanceOf(ArrayStore::class, Cache::store('array'));
    }

    public function testRememberStillRunsProducerWhenCachingDisabled(): void
    {
        file_put_contents($this->tmpConfig . '/cache.php', '<?php return ' . var_export([
            'default' => false,
            'array'   => ['driver' => 'array'],
        ], true) . ';');
        Config::reset();
        Cache::reset();
        Config::configure($this->tmpConfig);

        $calls = 0;
        $producer = function () use (&$calls): string {
            $calls++;
            return 'fresh';
        };

        self::assertSame('fresh', Cache::remember('k', 60, $producer));
        self::assertSame('fresh', Cache::remember('k', 60, $producer));
        // NullStore never holds anything, so the producer runs every time.
        self::assertSame(2, $calls);
    }

    public function testInjectStoreOverridesDefaultEvenWhenCachingDisabled(): void
    {
        file_put_contents($this->tmpConfig . '/cache.php', '<?php return ' . var_export([
            'default' => false,
            'array'   => ['driver' => 'array'],
        ], true) . ';');
        Config::reset();
        Cache::reset();
        Config::configure($this->tmpConfig);

        // Test pattern: even when caching is "off" in config, tests can
        // still swap in an ArrayStore so they get deterministic behavior.
        Cache::set('default', new ArrayStore());

        Cache::put('k', 'v', 60);
        self::assertSame('v', Cache::get('k'));
    }

    public function testFileStoreFromConfig(): void
    {
        $dir = sys_get_temp_dir() . '/cloude-cache-file-' . bin2hex(random_bytes(4));
        file_put_contents($this->tmpConfig . '/cache.php', '<?php return ' . var_export([
            'default' => 'file',
            'file'    => ['driver' => 'file', 'path' => $dir],
        ], true) . ';');
        Config::reset();
        Cache::reset();
        Config::configure($this->tmpConfig);

        self::assertInstanceOf(FileStore::class, Cache::store());
        Cache::put('hello', ['world' => 1], 30);
        self::assertSame(['world' => 1], Cache::get('hello'));

        // Cleanup.
        Cache::flush();
        if (is_dir($dir)) {
            @rmdir($dir);
        }
    }
}
