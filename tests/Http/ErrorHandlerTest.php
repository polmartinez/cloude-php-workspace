<?php

declare(strict_types=1);

namespace Cloude\Tests\Http;

use Cloude\Http\ErrorHandler;
use Cloude\Testing\DataProvider;
use Cloude\Testing\TestCase;

class ErrorHandlerTest extends TestCase
{
    /**
     * @param array<string, mixed> $server
     */
    #[DataProvider('negotiationCases')]
    public function testNegotiate(array $server, string $expected): void
    {
        $this->assertSame($expected, ErrorHandler::negotiate($server));
    }

    /** @return array<string, array{0: array<string, mixed>, 1: string}> */
    public static function negotiationCases(): array
    {
        return [
            'plain browser → html' => [
                ['HTTP_ACCEPT' => 'text/html,*/*', 'REQUEST_URI' => '/some/page'],
                'html',
            ],
            'empty server → html' => [[], 'html'],
            'accept json → json' => [
                ['HTTP_ACCEPT' => 'application/json'],
                'json',
            ],
            'content-type json (AJAX POST) → json' => [
                ['CONTENT_TYPE' => 'application/json; charset=utf-8'],
                'json',
            ],
            'X-Requested-With XMLHttpRequest → json' => [
                ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest'],
                'json',
            ],
            'X-Requested-With case-insensitive → json' => [
                ['HTTP_X_REQUESTED_WITH' => 'xmlhttprequest'],
                'json',
            ],
            'URL ends .json → json' => [
                ['REQUEST_URI' => '/api/users.json?x=1'],
                'json',
            ],
            'URL ends .md → md' => [
                ['REQUEST_URI' => '/docs/intro.md'],
                'md',
            ],
            'JSON wins over .md suffix' => [
                ['HTTP_ACCEPT' => 'application/json', 'REQUEST_URI' => '/x.md'],
                'json',
            ],
        ];
    }

    public function testCliRenderWritesPlainTextNotHtmlInSubprocess(): void
    {
        // cloude-test runs under PHP_SAPI === 'cli', but render() in the same
        // process would write to STDERR and pollute the test output. Spawn a
        // PHP subprocess so we can capture stderr cleanly.
        $autoload = dirname(__DIR__, 2) . '/vendor/autoload.php';
        $code = <<<PHP
<?php
require {$this->phpString($autoload)};
\\Cloude\\Http\\ErrorHandler::register(debug: false);
throw new \\RuntimeException('boom');
PHP;
        $tmp = tempnam(sys_get_temp_dir(), 'eh_');
        file_put_contents($tmp, $code);
        $cmd = escapeshellcmd(PHP_BINARY) . ' ' . escapeshellarg($tmp) . ' 2>&1';
        $output = (string) shell_exec($cmd);
        @unlink($tmp);

        $this->assertStringNotContainsString('<html', $output);
        $this->assertStringNotContainsString('<!doctype', strtolower($output));
        $this->assertStringContainsString('service temporarily unavailable', $output);
    }

    private function phpString(string $s): string
    {
        return var_export($s, true);
    }

    public function testNotFoundExceptionRendersAs404InSubprocess(): void
    {
        $autoload = dirname(__DIR__, 2) . '/vendor/autoload.php';
        // Spawn a PHP built-in server briefly is overkill; use `php -r`
        // with $_SERVER pre-populated so render() takes the HTTP branch.
        // PHP_SAPI for `php -r` is still 'cli' — so test the renderer
        // path directly by calling it on a non-cli SAPI is not feasible.
        // Instead exercise the HTML branch through `cli-server` style:
        // we just verify the chosen status + template by running a tiny
        // script that sets PHP_SAPI-affecting state via the built-in
        // server is also heavy. Pragmatic approach: directly invoke
        // ErrorHandler::render() under the CLI branch and assert the
        // CLI-mode plain-text output respects the 404 wording.
        $code = <<<PHP
<?php
require {$this->phpString($autoload)};
\\Cloude\\Http\\ErrorHandler::register(debug: false);
throw new \\Cloude\\Http\\NotFoundException('book 42');
PHP;
        $tmp = tempnam(sys_get_temp_dir(), 'eh_');
        file_put_contents($tmp, $code);
        $cmd = escapeshellcmd(PHP_BINARY) . ' ' . escapeshellarg($tmp) . ' 2>&1';
        $output = (string) shell_exec($cmd);
        @unlink($tmp);

        $this->assertStringContainsString('Not found', $output);
        $this->assertStringNotContainsString('service temporarily unavailable', $output);
    }

    public function testHttpExceptionCarriesStatusCode(): void
    {
        $e = new \Cloude\Http\HttpException(403, 'nope');
        $this->assertSame(403, $e->statusCode);
        $this->assertSame('nope', $e->getMessage());

        $nf = new \Cloude\Http\NotFoundException('gone');
        $this->assertSame(404, $nf->statusCode);
        $this->assertInstanceOf(\Cloude\Http\HttpException::class, $nf);
    }
}
