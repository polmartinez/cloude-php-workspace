<?php

declare(strict_types=1);

namespace Cloude\Tests\Http;

use Cloude\Http\ErrorHandler;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

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
        // PHPUnit runs under PHP_SAPI === 'cli', but render() in the same
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
}
