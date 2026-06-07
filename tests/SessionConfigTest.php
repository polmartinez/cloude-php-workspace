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
