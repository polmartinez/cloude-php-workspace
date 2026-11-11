<?php

declare(strict_types=1);

namespace Cloude\Tests;

use Cloude\Router;
use Cloude\Testing\TestCase;

final class RouterTest extends TestCase
{
    protected function setUp(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
    }

    public function testDispatchesStaticRoute(): void
    {
        $_SERVER['REQUEST_URI'] = '/foo';

        $called = false;
        $router = new Router();
        $router->get('/foo', function () use (&$called): void {
            $called = true;
        });

        ob_start();
        $router->dispatch();
        ob_end_clean();

        self::assertTrue($called);
    }

    public function testExtractsNamedParameters(): void
    {
        $_SERVER['REQUEST_URI'] = '/users/42';

        $captured = null;
        $router = new Router();
        $router->get('/users/{id}', function (array $p) use (&$captured): void {
            $captured = $p['id'];
        });

        ob_start();
        $router->dispatch();
        ob_end_clean();

        self::assertSame('42', $captured);
    }

    public function testRespectsHttpMethod(): void
    {
        $_SERVER['REQUEST_URI'] = '/api/resource';
        $_SERVER['REQUEST_METHOD'] = 'POST';

        $getCalled = false;
        $postCalled = false;

        $router = new Router();
        $router->get('/api/resource', function () use (&$getCalled): void {
            $getCalled = true;
        });
        $router->post('/api/resource', function () use (&$postCalled): void {
            $postCalled = true;
        });

        ob_start();
        $router->dispatch();
        ob_end_clean();

        self::assertFalse($getCalled);
        self::assertTrue($postCalled);
    }

    public function testRunsCustomNotFoundHandler(): void
    {
        $_SERVER['REQUEST_URI'] = '/does-not-exist';

        $triggered = false;
        $router = new Router();
        $router->setNotFound(function () use (&$triggered): void {
            $triggered = true;
        });

        ob_start();
        $router->dispatch();
        ob_end_clean();

        self::assertTrue($triggered);
    }

    public function testHeadFallsBackToTheGetRoute(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'HEAD';
        $_SERVER['REQUEST_URI'] = '/foo';

        $hit = null;
        $router = new Router();
        $router->post('/foo', function () use (&$hit): void {
            $hit = 'post';
        });
        $router->get('/foo', function () use (&$hit): void {
            $hit = 'get';
        });

        ob_start();
        $router->dispatch();
        ob_end_clean();

        self::assertSame('get', $hit);
    }

    public function testExplicitHeadRouteWinsOverGet(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'HEAD';
        $_SERVER['REQUEST_URI'] = '/foo';

        $hit = null;
        $router = new Router();
        $router->get('/foo', function () use (&$hit): void {
            $hit = 'get';
        });
        $router->add('/foo', function () use (&$hit): void {
            $hit = 'head';
        }, 'HEAD');

        ob_start();
        $router->dispatch();
        ob_end_clean();

        self::assertSame('head', $hit);
    }
}
