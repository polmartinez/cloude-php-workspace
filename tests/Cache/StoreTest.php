<?php

declare(strict_types=1);

namespace Cloude\Tests\Cache;

use Cloude\Cache\Store\ArrayStore;
use Cloude\Cache\Store\FileStore;
use Cloude\Testing\DataProvider;
use Cloude\Testing\TestCase;

/**
 * Driver-agnostic suite — every shipped store must pass these. Redis
 * and Memcached require their respective extensions plus a live server,
 * so we only run the in-process drivers here (ArrayStore + FileStore).
 * The contract enforced here is what the Cache façade and the
 * Query::cache() integration rely on.
 */
final class StoreTest extends TestCase
{
    private string $tmpFileDir;

    protected function setUp(): void
    {
        $this->tmpFileDir = sys_get_temp_dir() . '/cloude-cache-store-' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        // FileStore cleanup.
        if (is_dir($this->tmpFileDir)) {
            $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->tmpFileDir, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST,
            );
            foreach ($it as $f) {
                $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
            }
            @rmdir($this->tmpFileDir);
        }
    }

    /** @return array<string, array{0: callable(): \Cloude\Cache\Store}> */
    public static function drivers(): array
    {
        return [
            'array' => [fn () => new ArrayStore()],
            'file'  => [fn () => new FileStore(sys_get_temp_dir() . '/cloude-cache-store-' . bin2hex(random_bytes(4)))],
        ];
    }

    #[DataProvider('drivers')]
    public function testSetGetBasic(callable $factory): void
    {
        $store = $factory();
        $store->set('k', 'value');
        self::assertSame('value', $store->get('k'));
    }

    #[DataProvider('drivers')]
    public function testGetMissReturnsDefault(callable $factory): void
    {
        $store = $factory();
        self::assertNull($store->get('missing'));
        self::assertSame('fallback', $store->get('missing', 'fallback'));
    }

    #[DataProvider('drivers')]
    public function testHasReflectsPresence(callable $factory): void
    {
        $store = $factory();
        self::assertFalse($store->has('k'));
        $store->set('k', 1);
        self::assertTrue($store->has('k'));
        $store->forget('k');
        self::assertFalse($store->has('k'));
    }

    #[DataProvider('drivers')]
    public function testStoresComplexValues(callable $factory): void
    {
        $store = $factory();
        $value = ['rows' => [['id' => 1, 'name' => 'Ada'], ['id' => 2, 'name' => 'Linus']]];
        $store->set('rows', $value);
        self::assertSame($value, $store->get('rows'));
    }

    #[DataProvider('drivers')]
    public function testStoresFalseAndNull(callable $factory): void
    {
        $store = $factory();
        $store->set('false-val', false);
        $store->set('null-val', null);

        self::assertFalse($store->get('false-val'));
        self::assertNull($store->get('null-val'));
        self::assertTrue($store->has('false-val'));
        self::assertTrue($store->has('null-val'));
    }

    #[DataProvider('drivers')]
    public function testForgetIsIdempotent(callable $factory): void
    {
        $store = $factory();
        $store->forget('never-set');                 // no error
        $store->set('k', 1);
        $store->forget('k');
        $store->forget('k');
        self::assertFalse($store->has('k'));
    }

    #[DataProvider('drivers')]
    public function testFlushClearsEverything(callable $factory): void
    {
        $store = $factory();
        $store->set('a', 1);
        $store->set('b', 2);
        $store->flush();
        self::assertFalse($store->has('a'));
        self::assertFalse($store->has('b'));
    }

    public function testArrayStoreRespectsTtlExpiry(): void
    {
        // Faking time on ArrayStore is awkward (it calls time() directly),
        // so we verify behavior with a negative-style TTL by reading
        // implementation through a custom case: set with ttl=1, then
        // mutate `expires` via reflection to simulate expiry.
        $store = new ArrayStore();
        $store->set('k', 'v', 1);

        $rc = new \ReflectionClass($store);
        $prop = $rc->getProperty('entries');
        $prop->setAccessible(true);
        $entries = $prop->getValue($store);
        $entries['k']['expires'] = time() - 10;   // already expired
        $prop->setValue($store, $entries);

        self::assertNull($store->get('k'));
        self::assertFalse($store->has('k'));
    }

    public function testFileStoreRespectsTtlExpiry(): void
    {
        $store = new FileStore($this->tmpFileDir);
        $store->set('k', 'v', 1);

        // FileStore stores `expires\n<serialized>` — rewrite the expires
        // line to a past timestamp.
        $hash = sha1('k');
        $file = $this->tmpFileDir . '/' . substr($hash, 0, 2) . '/' . $hash;
        self::assertFileExists($file);
        $raw = file_get_contents($file);
        $payload = substr($raw, strpos($raw, "\n") + 1);
        file_put_contents($file, (time() - 10) . "\n" . $payload);

        self::assertNull($store->get('k'));
        self::assertFalse(is_file($file), 'expired entry should be evicted on read');
    }

    public function testFileStoreSurvivesAcrossInstances(): void
    {
        $a = new FileStore($this->tmpFileDir);
        $a->set('shared', ['x' => 1]);

        $b = new FileStore($this->tmpFileDir);
        self::assertSame(['x' => 1], $b->get('shared'));
    }
}
