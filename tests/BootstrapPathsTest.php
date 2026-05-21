<?php

declare(strict_types=1);

namespace Cloude\Tests;

use Cloude\Testing\TestCase;

/**
 * `Bootstrap::initPaths()` defines global constants — once per process —
 * so the test must run in an isolated subprocess. Anything that re-tests
 * constants would otherwise collide with `tests/RouterTest.php` etc.
 */
final class BootstrapPathsTest extends TestCase
{
    public function testInitPathsDefinesAllThreeConstants(): void
    {
        $autoload = dirname(__DIR__) . '/vendor/autoload.php';
        $code = <<<PHP
<?php
require {$this->phpString($autoload)};
\\Cloude\\Bootstrap::initPaths(
    docroot: '/srv/myapp/www',
    apppath: '/srv/myapp/app',
);
echo DOCROOT . "\\n" . APPPATH . "\\n" . BASEPATH . "\\n";
PHP;
        $tmp = tempnam(sys_get_temp_dir(), 'bp_');
        file_put_contents($tmp, $code);
        $cmd = escapeshellcmd(PHP_BINARY) . ' ' . escapeshellarg($tmp);
        $output = trim((string) shell_exec($cmd));
        @unlink($tmp);

        $lines = explode("\n", $output);
        self::assertSame('/srv/myapp/www', $lines[0]);
        self::assertSame('/srv/myapp/app', $lines[1]);
        self::assertSame('/srv/myapp', $lines[2]);   // dirname(apppath)
    }

    public function testInitPathsStripsTrailingSlashesAndAcceptsExplicitBase(): void
    {
        $autoload = dirname(__DIR__) . '/vendor/autoload.php';
        $code = <<<PHP
<?php
require {$this->phpString($autoload)};
\\Cloude\\Bootstrap::initPaths(
    docroot:  '/srv/myapp/www/',
    apppath:  '/srv/myapp/app/',
    basepath: '/srv/myapp/',
);
echo DOCROOT . "\\n" . APPPATH . "\\n" . BASEPATH;
PHP;
        $tmp = tempnam(sys_get_temp_dir(), 'bp_');
        file_put_contents($tmp, $code);
        $cmd = escapeshellcmd(PHP_BINARY) . ' ' . escapeshellarg($tmp);
        $output = trim((string) shell_exec($cmd));
        @unlink($tmp);

        $lines = explode("\n", $output);
        self::assertSame('/srv/myapp/www', $lines[0]);
        self::assertSame('/srv/myapp/app', $lines[1]);
        self::assertSame('/srv/myapp', $lines[2]);
    }

    public function testInitPathsIsIdempotent(): void
    {
        $autoload = dirname(__DIR__) . '/vendor/autoload.php';
        // Define DOCROOT first; initPaths must NOT overwrite it.
        $code = <<<PHP
<?php
require {$this->phpString($autoload)};
define('DOCROOT', '/pinned');
\\Cloude\\Bootstrap::initPaths(
    docroot: '/wrong',
    apppath: '/srv/myapp/app',
);
echo DOCROOT . "\\n" . APPPATH;
PHP;
        $tmp = tempnam(sys_get_temp_dir(), 'bp_');
        file_put_contents($tmp, $code);
        $cmd = escapeshellcmd(PHP_BINARY) . ' ' . escapeshellarg($tmp);
        $output = trim((string) shell_exec($cmd));
        @unlink($tmp);

        $lines = explode("\n", $output);
        self::assertSame('/pinned', $lines[0]);
        self::assertSame('/srv/myapp/app', $lines[1]);
    }

    public function testRunAppliesFrameworkDefaultTimezone(): void
    {
        // Bootstrap::run() sets the PHP default timezone from Config.
        // With no app/config/app.php, the framework's bundled
        // config/app.php (ships 'timezone' => 'UTC') flows through.
        // Subprocess so the global state doesn't leak between tests.
        $autoload = dirname(__DIR__) . '/vendor/autoload.php';
        $code = <<<PHP
<?php
date_default_timezone_set('Asia/Tokyo');     // start somewhere NON-UTC
require {$this->phpString($autoload)};
\\Cloude\\Config::configure(sys_get_temp_dir());
\\Cloude\\Bootstrap::run();
echo date_default_timezone_get();
PHP;
        $tmp = tempnam(sys_get_temp_dir(), 'bp_');
        file_put_contents($tmp, $code);
        $cmd = escapeshellcmd(PHP_BINARY) . ' ' . escapeshellarg($tmp) . ' 2>&1';
        $output = trim((string) shell_exec($cmd));
        @unlink($tmp);

        self::assertStringEndsWith('UTC', $output);
    }

    public function testRunAppliesAppTimezoneOverride(): void
    {
        $autoload = dirname(__DIR__) . '/vendor/autoload.php';
        $appDir = sys_get_temp_dir() . '/cloude-tz-' . bin2hex(random_bytes(4));
        @mkdir($appDir, 0755, true);
        file_put_contents($appDir . '/app.php', "<?php return ['timezone' => 'Europe/Madrid'];");
        try {
            $code = <<<PHP
<?php
date_default_timezone_set('UTC');
require {$this->phpString($autoload)};
\\Cloude\\Config::configure({$this->phpString($appDir)});
\\Cloude\\Bootstrap::run();
echo date_default_timezone_get();
PHP;
            $tmp = tempnam(sys_get_temp_dir(), 'bp_');
            file_put_contents($tmp, $code);
            $cmd = escapeshellcmd(PHP_BINARY) . ' ' . escapeshellarg($tmp) . ' 2>&1';
            $output = trim((string) shell_exec($cmd));
            @unlink($tmp);

            self::assertStringEndsWith('Europe/Madrid', $output);
        } finally {
            @unlink($appDir . '/app.php');
            @rmdir($appDir);
        }
    }

    public function testRunRegistersConfiguredShortNameAliases(): void
    {
        // class_alias is process-global, so we drive each test through
        // a subprocess to keep them isolated.
        $autoload = dirname(__DIR__) . '/vendor/autoload.php';
        $appDir = sys_get_temp_dir() . '/cloude-aliases-' . bin2hex(random_bytes(4));
        @mkdir($appDir, 0755, true);
        file_put_contents($appDir . '/app.php', "<?php return ['aliases' => ['View', 'Str']];");
        try {
            $code = <<<PHP
<?php
require {$this->phpString($autoload)};
\\Cloude\\Config::configure({$this->phpString($appDir)});
\\Cloude\\Bootstrap::run();

// After Bootstrap::run() the bare names should resolve to Cloude\\* .
echo (int) class_exists('View',  false), "\n";
echo (int) class_exists('Str',   false), "\n";
echo (int) class_exists('Input', false), "\n";
echo Str::slug('Hello World'), "\n";
PHP;
            $tmp = tempnam(sys_get_temp_dir(), 'bp_');
            file_put_contents($tmp, $code);
            $cmd = escapeshellcmd(PHP_BINARY) . ' ' . escapeshellarg($tmp) . ' 2>&1';
            $output = trim((string) shell_exec($cmd));
            @unlink($tmp);

            $lines = explode("\n", $output);
            self::assertSame('1', $lines[0]);                // View aliased
            self::assertSame('1', $lines[1]);                // Str aliased
            self::assertSame('0', $lines[2]);                // Input NOT aliased (omitted)
            self::assertSame('hello-world', $lines[3]);      // short name actually works
        } finally {
            @unlink($appDir . '/app.php');
            @rmdir($appDir);
        }
    }

    public function testRunDoesNotRegisterAliasesByDefault(): void
    {
        $autoload = dirname(__DIR__) . '/vendor/autoload.php';
        $code = <<<PHP
<?php
require {$this->phpString($autoload)};
\\Cloude\\Config::configure(sys_get_temp_dir());     // no app.php → no app.aliases
\\Cloude\\Bootstrap::run();
echo (int) class_exists('View', false);
PHP;
        $tmp = tempnam(sys_get_temp_dir(), 'bp_');
        file_put_contents($tmp, $code);
        $cmd = escapeshellcmd(PHP_BINARY) . ' ' . escapeshellarg($tmp) . ' 2>&1';
        $output = trim((string) shell_exec($cmd));
        @unlink($tmp);

        self::assertSame('0', $output);
    }

    public function testRunSkipsAliasWhenShortNameAlreadyTaken(): void
    {
        // If the consumer app already declares a `View` class, the
        // framework must NOT stomp it.
        $autoload = dirname(__DIR__) . '/vendor/autoload.php';
        $appDir = sys_get_temp_dir() . '/cloude-aliases-conflict-' . bin2hex(random_bytes(4));
        @mkdir($appDir, 0755, true);
        file_put_contents($appDir . '/app.php', "<?php return ['aliases' => ['View']];");
        try {
            $code = <<<PHP
<?php
require {$this->phpString($autoload)};

// Pre-declare a conflicting `View` class BEFORE Bootstrap::run().
class View { public static function e(\$s): string { return 'USER:' . \$s; } }

\\Cloude\\Config::configure({$this->phpString($appDir)});
\\Cloude\\Bootstrap::run();

echo View::e('hi');     // should still resolve to the user's class
PHP;
            $tmp = tempnam(sys_get_temp_dir(), 'bp_');
            file_put_contents($tmp, $code);
            $cmd = escapeshellcmd(PHP_BINARY) . ' ' . escapeshellarg($tmp) . ' 2>&1';
            $output = trim((string) shell_exec($cmd));
            @unlink($tmp);

            self::assertStringEndsWith('USER:hi', $output);
        } finally {
            @unlink($appDir . '/app.php');
            @rmdir($appDir);
        }
    }

    private function phpString(string $s): string
    {
        return var_export($s, true);
    }
}
