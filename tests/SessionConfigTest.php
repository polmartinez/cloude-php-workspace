<?php

declare(strict_types=1);

namespace Cloude\Tests;

use Cloude\Config;
use Cloude\Session;
use Cloude\Testing\TestCase;

/**
 * Verifies that `config/session.php` actually drives `Session::start()` —
 * cookie params end up on session_get_cookie_params(), and
 * gc_maxlifetime ends up on the ini setting.
 *
 * Like SessionTest, every test calls Session::destroy() in tearDown so
 * sub-tests in the same process don't bleed state.
 */
final class SessionConfigTest extends TestCase
{
    private string $tmpConfigDir;
    private string $tmpSavePath;

    protected function setUp(): void
    {
        $this->tmpConfigDir = sys_get_temp_dir() . '/cloude-sess-cfg-' . bin2hex(random_bytes(4));
        $this->tmpSavePath  = sys_get_temp_dir() . '/cloude-sess-data-' . bin2hex(random_bytes(4));
        mkdir($this->tmpConfigDir, 0700, true);
        mkdir($this->tmpSavePath, 0700, true);

        Config::reset();
    }

    protected function tearDown(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION = [];
            @session_destroy();
        }
        Config::reset();
        foreach (glob($this->tmpConfigDir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->tmpConfigDir);
        foreach (glob($this->tmpSavePath . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->tmpSavePath);
    }

    private function writeConfig(array $sessionConfig): void
    {
        file_put_contents(
            $this->tmpConfigDir . '/session.php',
            '<?php return ' . var_export($sessionConfig, true) . ';',
        );
        Config::configure($this->tmpConfigDir);
    }

    public function testCookieLifetimeFromConfigAppliesToSession(): void
    {
        $this->writeConfig([
            'cookie_lifetime' => 86400,        // 1 day
            'cookie_samesite' => 'Strict',
        ]);

        Session::start(['save_path' => $this->tmpSavePath, 'name' => 'CLOUDE_CFG1']);

        $params = session_get_cookie_params();
        self::assertSame(86400, $params['lifetime']);
        self::assertSame('Strict', $params['samesite']);
    }

    public function testGcMaxlifetimeFromConfigAppliesToIni(): void
    {
        $this->writeConfig([
            'gc_maxlifetime' => 7200,
        ]);

        Session::start(['save_path' => $this->tmpSavePath, 'name' => 'CLOUDE_CFG2']);

        self::assertSame('7200', ini_get('session.gc_maxlifetime'));
    }

    public function testExplicitCookieParamsOverrideConfig(): void
    {
        $this->writeConfig([
            'cookie_samesite' => 'Lax',
            'cookie_lifetime' => 3600,
        ]);

        Session::start(
            ['save_path' => $this->tmpSavePath, 'name' => 'CLOUDE_CFG3'],
            cookieParams: ['samesite' => 'Strict', 'lifetime' => 60],
        );

        $params = session_get_cookie_params();
        self::assertSame('Strict', $params['samesite']);
        self::assertSame(60, $params['lifetime']);
    }

    public function testExplicitSessionStartOptionsOverrideConfig(): void
    {
        $this->writeConfig([
            'name' => 'CONFIG_NAME',
        ]);

        Session::start([
            'save_path' => $this->tmpSavePath,
            'name'      => 'EXPLICIT_NAME',
        ]);

        self::assertSame('EXPLICIT_NAME', session_name());
    }

    public function testHardenedDefaultsSurviveWhenConfigOmitsKeys(): void
    {
        // Config touches lifetime only — httponly/samesite should still
        // be the framework's hardened defaults.
        $this->writeConfig(['cookie_lifetime' => 1800]);

        Session::start(['save_path' => $this->tmpSavePath, 'name' => 'CLOUDE_CFG4']);

        $params = session_get_cookie_params();
        self::assertSame(1800, $params['lifetime']);
        self::assertTrue($params['httponly']);
        self::assertSame('Lax', $params['samesite']);
    }

    public function testRedisHandlerBuildsCorrectSavePathUri(): void
    {
        if (!extension_loaded('redis')) {
            // No ext-redis on this box → exercise the unit-level URI
            // builder via reflection so the test still adds value.
            $rc = new \ReflectionClass(Session::class);
            $m  = $rc->getMethod('redisSavePath');
            $m->setAccessible(true);
            $uri = $m->invoke(null, [
                'host'     => 'redis.example.com',
                'port'     => 6380,
                'database' => 2,
                'prefix'   => 'app:sess:',
                'password' => 'sup3r',
                'timeout'  => 1.5,
            ]);
            self::assertStringStartsWith('tcp://redis.example.com:6380?', $uri);
            self::assertStringContainsString('database=2', $uri);
            self::assertStringContainsString('prefix=app%3Asess%3A', $uri);
            self::assertStringContainsString('auth=sup3r', $uri);
            self::assertStringContainsString('timeout=1.5', $uri);
            return;
        }

        // ext-redis present → check the handler ends up wired in.
        $this->writeConfig([
            'handler' => 'redis',
            'redis'   => [
                'host'     => '127.0.0.1',
                'port'     => 6379,
                'database' => 0,
                'prefix'   => 'cloude_test:',
            ],
        ]);

        // We *don't* call Session::start() here because we don't want to
        // require a live Redis. Validate via reflection that applyHandler
        // produced the right ini value + save_path.
        $rc = new \ReflectionClass(Session::class);
        $m  = $rc->getMethod('applyHandler');
        $m->setAccessible(true);
        $opts = [];
        $m->invokeArgs(null, ['redis', Config::get('session'), &$opts]);

        self::assertSame('redis', ini_get('session.save_handler'));
        self::assertStringStartsWith('tcp://127.0.0.1:6379?', $opts['save_path']);
        self::assertStringContainsString('prefix=cloude_test', $opts['save_path']);

        // Reset save_handler so we don't poison sibling tests.
        ini_restore('session.save_handler');
    }

    public function testMemcachedHandlerBuildsCommaSeparatedServerList(): void
    {
        // URI builder is unit-testable without the extension.
        $rc = new \ReflectionClass(Session::class);
        $m  = $rc->getMethod('memcachedSavePath');
        $m->setAccessible(true);

        // Single-tuple shape (the common case).
        $path = $m->invoke(null, ['servers' => ['10.0.0.1', 11211]]);
        self::assertSame('10.0.0.1:11211', $path);

        // List-of-tuples shape (multi-node).
        $path = $m->invoke(null, ['servers' => [['10.0.0.1', 11211], ['10.0.0.2', 11212]]]);
        self::assertSame('10.0.0.1:11211,10.0.0.2:11212', $path);

        // Defaults when servers omitted.
        $path = $m->invoke(null, []);
        self::assertSame('127.0.0.1:11211', $path);
    }

    public function testUnsupportedHandlerThrows(): void
    {
        $this->expectException(\RuntimeException::class);

        $rc = new \ReflectionClass(Session::class);
        $m  = $rc->getMethod('applyHandler');
        $m->setAccessible(true);
        $opts = [];
        $m->invokeArgs(null, ['rabbitmq', [], &$opts]);
    }

    public function testRedisHandlerWithoutExtensionThrowsClearly(): void
    {
        if (extension_loaded('redis')) {
            self::assertTrue(true, 'skipped: ext-redis is loaded — cannot test the missing-extension branch');
            return;
        }
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('ext-redis');

        $rc = new \ReflectionClass(Session::class);
        $m  = $rc->getMethod('applyHandler');
        $m->setAccessible(true);
        $opts = [];
        $m->invokeArgs(null, ['redis', ['redis' => []], &$opts]);
    }

    public function testWorksWithoutAnyConfigFile(): void
    {
        // No config dir configured at all — Session must still start
        // with the hardcoded defaults.
        Config::reset();

        Session::start(['save_path' => $this->tmpSavePath, 'name' => 'CLOUDE_CFG5']);

        $params = session_get_cookie_params();
        self::assertSame(0, $params['lifetime']);
        self::assertTrue($params['httponly']);
        self::assertSame('Lax', $params['samesite']);
    }
}
