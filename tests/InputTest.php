<?php

declare(strict_types=1);

namespace Cloude\Tests;

use Cloude\Input;
use Cloude\Testing\TestCase;

/**
 * Input is a thin wrapper over superglobals — these tests stomp on
 * $_SERVER / $_GET / $_POST in setUp and restore them in tearDown.
 */
final class InputTest extends TestCase
{
    /** @var array<string,mixed> */
    private array $serverBackup;
    /** @var array<string,mixed> */
    private array $getBackup;
    /** @var array<string,mixed> */
    private array $postBackup;

    protected function setUp(): void
    {
        $this->serverBackup = $_SERVER;
        $this->getBackup    = $_GET;
        $this->postBackup   = $_POST;
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->serverBackup;
        $_GET    = $this->getBackup;
        $_POST   = $this->postBackup;
    }

    public function testServerWithKeyReturnsValue(): void
    {
        $_SERVER['REMOTE_ADDR'] = '10.0.0.5';
        self::assertSame('10.0.0.5', Input::server('REMOTE_ADDR'));
    }

    public function testServerMissingKeyReturnsDefault(): void
    {
        unset($_SERVER['HTTP_NONEXISTENT']);
        self::assertNull(Input::server('HTTP_NONEXISTENT'));
        self::assertSame('fallback', Input::server('HTTP_NONEXISTENT', 'fallback'));
    }

    public function testServerWithoutKeyReturnsFullArray(): void
    {
        $_SERVER = ['REMOTE_ADDR' => '1.2.3.4', 'REQUEST_METHOD' => 'GET'];
        self::assertSame($_SERVER, Input::server());
    }

    public function testServerKeyIsCaseSensitive(): void
    {
        // PHP normalises $_SERVER keys to upper-snake — Input::server()
        // doesn't normalise, callers must use the canonical name.
        $_SERVER['HTTP_X_REQUEST_ID'] = 'abc-123';
        self::assertSame('abc-123', Input::server('HTTP_X_REQUEST_ID'));
        self::assertNull(Input::server('http_x_request_id'));
    }

    public function testMethodReadsServerRequestMethod(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'post';
        self::assertSame('POST', Input::method());

        unset($_SERVER['REQUEST_METHOD']);
        self::assertSame('GET', Input::method());
    }

    public function testUriNormalizesValidPath(): void
    {
        $_SERVER['REQUEST_URI'] = '/foo/bar?x=1';
        self::assertSame('/foo/bar', Input::uri());

        $_SERVER['REQUEST_URI'] = '/';
        self::assertSame('/', Input::uri());

        // Internal `//` between segments → collapsed.
        $_SERVER['REQUEST_URI'] = '/foo//bar/';
        self::assertSame('/foo/bar/', Input::uri());
    }

    public function testUriHandlesMissingRequestUri(): void
    {
        unset($_SERVER['REQUEST_URI']);
        self::assertSame('/', Input::uri());
    }

    /**
     * Regression: parse_url() returns `false` (not null) for malformed
     * URIs that bot scanners send. The old `?? '/'` only caught null,
     * so ltrim(false, ...) blew up under strict_types and turned every
     * garbage request into a 500.
     */
    public function testUriSurvivesMalformedRequestUri(): void
    {
        $garbage = [
            'http://:80',
            '//foo:bar:baz',
            '://',
            'http:///',
            ':',
            '',
        ];
        foreach ($garbage as $bad) {
            $_SERVER['REQUEST_URI'] = $bad;
            // Must return SOMETHING starting with '/' and not throw.
            $path = Input::uri();
            self::assertStringStartsWith('/', $path, "broke on REQUEST_URI=" . var_export($bad, true));
        }
    }

    public function testGetAndPostShortcuts(): void
    {
        $_GET  = ['q' => 'hello'];
        $_POST = ['name' => 'Ada'];

        self::assertSame('hello', Input::get('q'));
        self::assertSame('default', Input::get('missing', 'default'));
        self::assertSame(['q' => 'hello'], Input::get());

        self::assertSame('Ada', Input::post('name'));
        self::assertSame(['name' => 'Ada'], Input::post());
    }
}
