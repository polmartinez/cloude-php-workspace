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

    private function phpString(string $s): string
    {
        return var_export($s, true);
    }
}
