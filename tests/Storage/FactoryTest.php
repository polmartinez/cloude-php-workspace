<?php

declare(strict_types=1);

namespace Cloude\Tests\Storage;

use Cloude\Config;
use Cloude\Model\Storage\ArrayStorage;
use Cloude\Model\Storage\JsonCollectionStorage;
use Cloude\Model\Storage\JsonStorage;
use Cloude\Model\Storage\MarkdownStorage;
use Cloude\Model\Storage\PdoStorage;
use Cloude\Storage\Connection;
use Cloude\Storage\Factory;
use Cloude\Testing\TestCase;

final class FactoryTest extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/cloude-factory-' . bin2hex(random_bytes(4));
        mkdir($this->tmp, 0755, true);

        file_put_contents($this->tmp . '/storage.php', "<?php return [
            'default'    => ['driver' => 'pdo', 'dsn' => 'sqlite::memory:'],
            'content'    => ['driver' => 'json', 'path' => '" . $this->tmp . "/data', 'auto_increment' => true],
            'lookup'     => ['driver' => 'json_collection', 'path' => '" . $this->tmp . "/data', 'primary_key' => 'slug'],
            'articles'   => ['driver' => 'markdown', 'path' => '" . $this->tmp . "/content', 'primary_key' => 'slug'],
            'fake'       => ['driver' => 'array', 'data' => [['id' => 1, 'name' => 'Ada']]],
            'weirdpk'    => ['driver' => 'pdo', 'dsn' => 'sqlite::memory:', 'primary_key' => 'uuid'],
            'broken'     => ['driver' => 'mongo'],
            'no_path'    => ['driver' => 'json'],
            'no_path_c'  => ['driver' => 'json_collection'],
            'no_path_md' => ['driver' => 'markdown'],
        ];");

        Connection::reset();
        Connection::setConfigName('storage');
        Config::reset();
        Config::setConfigPath($this->tmp);
        Config::setEnvironment('dev');
    }

    protected function tearDown(): void
    {
        $rm = function (string $dir) use (&$rm): void {
            foreach (glob($dir . '/*') ?: [] as $f) {
                is_dir($f) ? $rm($f) : @unlink($f);
            }
            @rmdir($dir);
        };
        $rm($this->tmp);
        Connection::reset();
        Config::reset();
    }

    public function testPdoDriverYieldsPdoStorage(): void
    {
        $storage = Factory::make('default', 'users');
        self::assertInstanceOf(PdoStorage::class, $storage);
    }

    public function testJsonDriverYieldsJsonStorage(): void
    {
        $storage = Factory::make('content', 'articles');
        self::assertInstanceOf(JsonStorage::class, $storage);
        // Sanity: the directory should be path + '/' + table
        $storage->insert(['name' => 'X']);
        self::assertDirectoryExists($this->tmp . '/data/articles');
    }

    public function testArrayDriverYieldsArrayStorageAndSeedsData(): void
    {
        $storage = Factory::make('fake', 'users');
        self::assertInstanceOf(ArrayStorage::class, $storage);
        $row = $storage->find(1);
        self::assertSame('Ada', $row['name']);
    }

    public function testPrimaryKeyOverridePassesThroughToPdoStorage(): void
    {
        // Just verifying the factory accepts the option; semantic test of
        // PdoStorage's PK handling lives in PdoStorageTest.
        $storage = Factory::make('weirdpk', 'sessions');
        self::assertInstanceOf(PdoStorage::class, $storage);
    }

    public function testUnknownConnectionThrows(): void
    {
        $this->expectException(\RuntimeException::class);
        Factory::make('does_not_exist', 'foo');
    }

    public function testUnknownDriverThrows(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("Unknown storage driver 'mongo'");
        Factory::make('broken', 'foo');
    }

    public function testJsonDriverRequiresPath(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("'json' driver requires a 'path' key");
        Factory::make('no_path', 'foo');
    }

    public function testJsonCollectionDriverYieldsJsonCollectionStorage(): void
    {
        $storage = Factory::make('lookup', 'parties');
        self::assertInstanceOf(JsonCollectionStorage::class, $storage);
        // Round-trip: insert + find through the factory-built adapter.
        $storage->insert(['slug' => 'psoe', 'name' => 'PSOE']);
        $row = $storage->find('psoe');
        self::assertSame('PSOE', $row['name']);
        self::assertSame('psoe', $row['slug']);
        self::assertFileExists($this->tmp . '/data/parties.json');
    }

    public function testJsonCollectionDriverRequiresPath(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("'json_collection' driver requires a 'path' key");
        Factory::make('no_path_c', 'foo');
    }

    public function testMakeFromConfigBuildsWithoutConfigLookup(): void
    {
        $storage = Factory::makeFromConfig(
            ['driver' => 'array', 'data' => [['id' => 1, 'name' => 'Ada']]],
            'users',
        );
        self::assertInstanceOf(ArrayStorage::class, $storage);
        self::assertSame('Ada', $storage->find(1)['name']);
    }

    public function testMarkdownDriverYieldsMarkdownStorage(): void
    {
        $storage = Factory::make('articles', 'posts');
        self::assertInstanceOf(MarkdownStorage::class, $storage);

        // Round-trip via the Factory-built adapter — Markdown\File::write
        // stores as .md.gz, so check via the framework's existence helper
        // (transparent .md/.md.gz) instead of file_exists on the plain .md.
        $storage->insert([
            'slug' => 'hello',
            'meta' => ['title' => 'Hello'],
            'raw'  => 'Body text.',
        ]);
        self::assertTrue(\Cloude\Markdown\File::exists($this->tmp . '/content/posts/hello.md'));
        $row = $storage->find('hello');
        self::assertSame('Hello', $row['meta']['title']);
    }

    public function testMarkdownDriverRequiresPath(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("'markdown' driver requires a 'path' key");
        Factory::make('no_path_md', 'foo');
    }

    public function testMakeFromConfigRejectsInlinePdo(): void
    {
        // PDO connections must be named AND present in storage config so
        // that Connection::pdo() can pool them. Inline configs without
        // Config involvement only support file-based drivers.
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("'pdo' driver requires a named connection");
        Factory::makeFromConfig(['driver' => 'pdo', 'dsn' => 'sqlite::memory:'], 'users');
    }
}
