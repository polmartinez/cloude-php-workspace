<?php

declare(strict_types=1);

namespace Cloude\Tests;

use Cloude\Config;
use PHPUnit\Framework\TestCase;

final class ConfigTest extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/cloude-config-' . bin2hex(random_bytes(4));
        mkdir($this->tmp, 0755, true);
        mkdir($this->tmp . '/dev', 0755, true);
        mkdir($this->tmp . '/prod', 0755, true);

        // Base configs
        file_put_contents($this->tmp . '/app.php', "<?php return [
            'name'  => 'Demo',
            'cache' => ['driver' => 'file', 'ttl' => 3600],
        ];");
        file_put_contents($this->tmp . '/db.php', "<?php return [
            'default'  => ['dsn' => 'sqlite::memory:'],
            'readonly' => ['dsn' => 'sqlite::memory:'],
        ];");

        // Per-environment overrides
        file_put_contents($this->tmp . '/dev/app.php', "<?php return [
            'cache' => ['ttl' => 60],
        ];");
        file_put_contents($this->tmp . '/prod/app.php', "<?php return [
            'cache' => ['driver' => 'redis', 'ttl' => 86400],
        ];");
        file_put_contents($this->tmp . '/prod/db.php', "<?php return [
            'default' => ['dsn' => 'mysql:host=prod;dbname=app'],
        ];");

        Config::reset();
        Config::setConfigPath($this->tmp);
        Config::setEnvironment('dev');
    }

    protected function tearDown(): void
    {
        // Recursive rmdir
        $rm = function (string $dir) use (&$rm): void {
            foreach (glob($dir . '/*') ?: [] as $f) {
                is_dir($f) ? $rm($f) : @unlink($f);
            }
            @rmdir($dir);
        };
        $rm($this->tmp);
        Config::reset();
    }

    public function testLoadReturnsBaseWhenNoOverride(): void
    {
        Config::setEnvironment('dev');     // dev only overrides cache.ttl
        $app = Config::load('app');
        self::assertSame('Demo', $app['name']);
    }

    public function testLoadMergesEnvOverrideOverBase(): void
    {
        Config::setEnvironment('dev');
        self::assertSame(60, Config::get('app.cache.ttl'));
        self::assertSame('file', Config::get('app.cache.driver'));   // base survives

        Config::setEnvironment('prod');
        self::assertSame(86400, Config::get('app.cache.ttl'));
        self::assertSame('redis', Config::get('app.cache.driver'));  // overridden
    }

    public function testGetWithDotPath(): void
    {
        self::assertSame('sqlite::memory:', Config::get('db.default.dsn'));
    }

    public function testGetReturnsDefaultForMissingKey(): void
    {
        self::assertSame('fallback', Config::get('app.does.not.exist', 'fallback'));
    }

    public function testGetReturnsDefaultForMissingFile(): void
    {
        self::assertSame('fallback', Config::get('nope.anything', 'fallback'));
    }

    public function testLoadIsCachedPerName(): void
    {
        $first = Config::load('app');
        // Mutate the file on disk — load() should still return the cached version.
        file_put_contents($this->tmp . '/app.php', "<?php return ['name' => 'Changed'];");
        $second = Config::load('app');
        self::assertSame($first, $second);
    }

    public function testResetClearsCache(): void
    {
        Config::load('app');
        file_put_contents($this->tmp . '/app.php', "<?php return ['name' => 'Changed'];");
        Config::reset();
        self::assertSame('Changed', Config::get('app.name'));
    }

    public function testEnvironmentSwitchInvalidatesCache(): void
    {
        Config::setEnvironment('dev');
        self::assertSame(60, Config::get('app.cache.ttl'));
        Config::setEnvironment('prod');
        self::assertSame(86400, Config::get('app.cache.ttl'));
    }

    public function testSetEnvironmentRejectsInvalidChars(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Config::setEnvironment('dev/../etc/passwd');
    }

    public function testLoadRejectsInvalidName(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Config::load('../etc/passwd');
    }

    public function testLoadThrowsWhenFileReturnsNonArray(): void
    {
        file_put_contents($this->tmp . '/bad.php', "<?php return 'oops';");
        $this->expectException(\RuntimeException::class);
        Config::load('bad');
    }

    public function testConfigureAutoDetectsFromAppEnv(): void
    {
        putenv('APP_ENV=prod');
        Config::configure($this->tmp);
        self::assertSame('prod', Config::environment());
        self::assertSame(86400, Config::get('app.cache.ttl'));
        putenv('APP_ENV');
    }

    public function testConfigureWithExplicitEnvironmentWins(): void
    {
        putenv('APP_ENV=prod');
        Config::configure($this->tmp, 'dev');
        self::assertSame('dev', Config::environment());
        putenv('APP_ENV');
    }

    public function testLoadWithoutConfigPathThrows(): void
    {
        Config::reset();
        // Reset configPath via reflection (the API doesn't expose unset).
        $rc = new \ReflectionClass(Config::class);
        $rp = $rc->getProperty('configPath');
        $rp->setAccessible(true);
        $rp->setValue(null, null);

        $this->expectException(\RuntimeException::class);
        Config::load('app');
    }
}
