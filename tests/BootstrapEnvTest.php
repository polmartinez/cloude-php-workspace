<?php

declare(strict_types=1);

namespace Cloude\Tests;

use Cloude\Bootstrap;
use Cloude\Config;
use Cloude\Testing\TestCase;

/**
 * Bootstrap::$env / setEnv() / env() — drives the per-environment
 * config overlay. We verify that:
 *
 *  - setEnv() validates the name and propagates to Config.
 *  - env() resolves Bootstrap → APP_ENV → Config default.
 *  - The per-env config directory (e.g. config/production/app.php)
 *    actually deep-merges over the base when Bootstrap::$env is set.
 */
final class BootstrapEnvTest extends TestCase
{
    private string $tmpConfig;

    protected function setUp(): void
    {
        $this->tmpConfig = sys_get_temp_dir() . '/cloude-env-cfg-' . bin2hex(random_bytes(4));
        mkdir($this->tmpConfig, 0700, true);
        mkdir($this->tmpConfig . '/production', 0700, true);
        mkdir($this->tmpConfig . '/staging', 0700, true);

        // Base config:
        file_put_contents($this->tmpConfig . '/app.php', '<?php return ' . var_export([
            'name'  => 'cloude-test',
            'debug' => true,
            'tier'  => 'base',
        ], true) . ';');

        // Production overlay — only 'debug' and 'tier' change.
        file_put_contents($this->tmpConfig . '/production/app.php', '<?php return ' . var_export([
            'debug' => false,
            'tier'  => 'production',
        ], true) . ';');

        // Staging overlay — only 'tier'.
        file_put_contents($this->tmpConfig . '/staging/app.php', '<?php return ' . var_export([
            'tier' => 'staging',
        ], true) . ';');

        Bootstrap::$env = null;
        Config::setEnvironment('dev');
        Config::reset();
    }

    protected function tearDown(): void
    {
        Bootstrap::$env = null;
        Config::setEnvironment('dev');
        Config::reset();
        foreach (glob($this->tmpConfig . '/{,*/}*.php', GLOB_BRACE) ?: [] as $f) {
            @unlink($f);
        }
        foreach (glob($this->tmpConfig . '/*', GLOB_ONLYDIR) ?: [] as $d) {
            @rmdir($d);
        }
        @rmdir($this->tmpConfig);
    }

    public function testEnvDefaultsToConfigDefaultWhenUnset(): void
    {
        // No Bootstrap::$env, no APP_ENV → falls back to Config's 'dev'.
        self::assertSame('dev', Bootstrap::env());
    }

    public function testSetEnvValidatesAndPropagatesToConfig(): void
    {
        Bootstrap::setEnv('production');

        self::assertSame('production', Bootstrap::$env);
        self::assertSame('production', Bootstrap::env());
        self::assertSame('production', Config::environment());
    }

    public function testSetEnvRejectsInvalidName(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Bootstrap::setEnv('prod/uction');   // slashes would break path joining
    }

    public function testProductionOverlayMergesOverBase(): void
    {
        // Order: setEnv FIRST, then configure() — common pattern.
        Bootstrap::setEnv('production');
        Config::configure($this->tmpConfig);

        self::assertFalse(Config::get('app.debug'),  'production should flip debug off');
        self::assertSame('production', Config::get('app.tier'));
        self::assertSame('cloude-test', Config::get('app.name'), 'unchanged keys keep base value');
    }

    public function testStagingOverlayMergesOverBase(): void
    {
        Bootstrap::setEnv('staging');
        Config::configure($this->tmpConfig);

        self::assertSame('staging', Config::get('app.tier'));
        self::assertTrue(Config::get('app.debug'), 'staging overlay does not touch debug');
        self::assertSame('cloude-test', Config::get('app.name'));
    }

    public function testDirectPropertyAssignmentAfterConfigureStillTakesEffect(): void
    {
        // Configure first (loads base + whatever env Config defaulted to).
        Config::configure($this->tmpConfig);
        self::assertSame('base', Config::get('app.tier'), 'sanity: base is in effect');

        // Now flip env via direct assignment + Bootstrap::env() resolution.
        Bootstrap::$env = 'production';
        // run() syncs the env to Config — call setEnv to make the test
        // not depend on run(), which would also write headers / register
        // the global error handler.
        Config::setEnvironment(Bootstrap::$env);

        self::assertSame('production', Config::get('app.tier'));
    }

    public function testEnvFallsBackToAppEnvVarWhenBootstrapNotSet(): void
    {
        $previous = getenv('APP_ENV');
        putenv('APP_ENV=staging');
        $_ENV['APP_ENV'] = 'staging';
        try {
            self::assertSame('staging', Bootstrap::env());
        } finally {
            if ($previous === false) {
                putenv('APP_ENV');
                unset($_ENV['APP_ENV']);
            } else {
                putenv('APP_ENV=' . $previous);
                $_ENV['APP_ENV'] = $previous;
            }
        }
    }
}
