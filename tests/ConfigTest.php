<?php

declare(strict_types=1);

namespace Cloude\Tests;

use Cloude\Config;
use Cloude\Testing\TestCase;

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
        // Clear every search path via reflection (the public API has no
        // "unset" — this is test scaffolding). We also short-circuit
        // corePath() to null so the framework's bundled config/ doesn't
        // count as "a path".
        $rc = new \ReflectionClass(Config::class);
        $rc->getProperty('appPath')->setValue(null, null);
        $rc->getProperty('extraPaths')->setValue(null, []);
        $coreResolved = $rc->getProperty('coreResolved');
        $corePath     = $rc->getProperty('corePath');
        $coreResolved->setValue(null, true);
        $corePath->setValue(null, null);

        try {
            $this->expectException(\RuntimeException::class);
            Config::load('app');
        } finally {
            // Restore so the next test sees the bundled config/ again.
            $coreResolved->setValue(null, false);
            $corePath->setValue(null, null);
        }
    }

    // ── typed accessors ──────────────────────────────────────────────────

    public function testBaseUrlReadsFromConfigFile(): void
    {
        file_put_contents($this->tmp . '/app.php', "<?php return [
            'base_url' => 'https://example.test',
        ];");
        Config::reset();
        Config::setConfigPath($this->tmp);
        Config::setEnvironment('dev');

        self::assertSame('https://example.test', Config::baseUrl());
    }

    public function testBaseUrlStripsTrailingSlash(): void
    {
        file_put_contents($this->tmp . '/app.php', "<?php return [
            'base_url' => 'https://example.test/',
        ];");
        Config::reset();
        Config::setConfigPath($this->tmp);

        self::assertSame('https://example.test', Config::baseUrl());
    }

    public function testBaseUrlAutoDetectWithAllowlist(): void
    {
        file_put_contents($this->tmp . '/app.php', '<?php return [];');
        Config::reset();
        Config::setConfigPath($this->tmp);
        unset($_ENV['BASE_URL'], $_SERVER['BASE_URL']);
        putenv('BASE_URL');
        $_SERVER['HTTP_HOST'] = 'evil.test';

        self::assertSame('http://localhost', Config::baseUrl(['example.com']));
    }

    public function testDebugReadsFromConfig(): void
    {
        file_put_contents($this->tmp . '/app.php', "<?php return ['debug' => true];");
        Config::reset();
        Config::setConfigPath($this->tmp);

        self::assertTrue(Config::debug());
    }

    public function testDebugDefaultsToFalse(): void
    {
        file_put_contents($this->tmp . '/app.php', '<?php return [];');
        Config::reset();
        Config::setConfigPath($this->tmp);
        unset($_ENV['DEBUG'], $_SERVER['DEBUG']);
        putenv('DEBUG');

        self::assertFalse(Config::debug());
    }

    public function testPathReadsAppPaths(): void
    {
        file_put_contents($this->tmp . '/app.php', "<?php return [
            'paths' => ['data' => '/srv/data', 'views' => '/srv/views'],
        ];");
        Config::reset();
        Config::setConfigPath($this->tmp);

        self::assertSame('/srv/data', Config::path('data'));
        self::assertSame('/srv/views', Config::path('views'));
    }

    public function testPathFallsBackToDefault(): void
    {
        file_put_contents($this->tmp . '/app.php', "<?php return ['paths' => []];");
        Config::reset();
        Config::setConfigPath($this->tmp);

        self::assertSame('/fallback', Config::path('cache', '/fallback'));
        self::assertNull(Config::path('cache'));
    }

    // ── multi-path search (framework defaults + app overrides) ───────────

    public function testFrameworkBundledConfigIsAutoRegistered(): void
    {
        Config::reset();
        Config::setConfigPath($this->tmp);

        // Framework ships config/email.php — should be in the search list.
        $paths = Config::paths();
        self::assertGreaterThan(1, count($paths), 'core path should auto-prepend');
        self::assertStringEndsWith('/config', $paths[0]);
    }

    public function testAppConfigDeepMergesOverCoreDefaults(): void
    {
        // App declares only 'transport' + 'host' + 'from'; the framework's
        // bundled config/email.php contributes 'port', 'tls', 'timeout'.
        file_put_contents($this->tmp . '/email.php', "<?php return [
            'transport' => 'smtp',
            'host'      => 'smtp.app.test',
            'from'      => 'app@example.com',
        ];");
        Config::reset();
        Config::setConfigPath($this->tmp);

        $merged = Config::get('email');
        // App wins.
        self::assertSame('smtp', $merged['transport']);
        self::assertSame('smtp.app.test', $merged['host']);
        // Core defaults flow through.
        self::assertSame(587, $merged['port']);
        self::assertTrue($merged['tls']);
        self::assertSame(30, $merged['timeout']);
    }

    public function testTimezoneReadsFromAppConfig(): void
    {
        file_put_contents($this->tmp . '/app.php', "<?php return ['timezone' => 'Europe/Madrid'];");
        Config::reset();
        Config::setConfigPath($this->tmp);

        self::assertSame('Europe/Madrid', Config::timezone());
    }

    public function testTimezoneInheritsFrameworkDefault(): void
    {
        // No app/config/app.php → the framework's bundled config/app.php
        // (ships with 'timezone' => 'UTC') flows through unchanged.
        Config::reset();
        Config::setConfigPath($this->tmp);

        self::assertSame('UTC', Config::timezone());
    }

    public function testTimezoneFallsBackToDefaultArg(): void
    {
        // Even without ANY config path, the explicit default kicks in.
        Config::reset();
        $rc = new \ReflectionClass(Config::class);
        $rc->getProperty('appPath')->setValue(null, null);
        $rc->getProperty('extraPaths')->setValue(null, []);
        $coreResolved = $rc->getProperty('coreResolved');
        $corePath     = $rc->getProperty('corePath');
        $coreResolved->setValue(null, true);
        $corePath->setValue(null, null);
        try {
            self::assertSame('America/Mexico_City', Config::timezone('America/Mexico_City'));
        } finally {
            $coreResolved->setValue(null, false);
            $corePath->setValue(null, null);
        }
    }

    public function testAddPathAppendsAdditionalSearchLocation(): void
    {
        $extra = sys_get_temp_dir() . '/cloude-extra-' . bin2hex(random_bytes(4));
        mkdir($extra, 0755, true);
        file_put_contents($extra . '/widget.php', "<?php return ['color' => 'red'];");

        Config::reset();
        Config::setConfigPath($this->tmp);
        Config::addPath($extra);
        try {
            self::assertSame('red', Config::get('widget.color'));
        } finally {
            @unlink($extra . '/widget.php');
            @rmdir($extra);
        }
    }
}
