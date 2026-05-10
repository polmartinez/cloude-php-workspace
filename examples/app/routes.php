<?php

declare(strict_types=1);

namespace App;

use Cloude\Input;
use Cloude\Router;
use Cloude\View;

class Routes
{
    public static function register(Router $router): void
    {
        // Home.
        $router->get('/', function (): void {
            View::render('layout.php', [
                'title'   => 'Cloude Framework - Example',
                'content' => 'home.php',
            ]);
        });

        // Route with a dynamic parameter.
        $router->get('/hello/{name}', function (array $params): void {
            View::render('layout.php', [
                'title'   => 'Hello ' . $params['name'],
                'content' => 'hello.php',
                'name'    => $params['name'],
            ]);
        });

        // JSON endpoint with POST input.
        $router->post('/api/echo', function (): void {
            header('Content-Type: application/json');
            echo json_encode([
                'method' => Input::method(),
                'query'  => Input::get(),
                'body'   => Input::json(),
                'ip'     => Input::ip(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        });

        // Static page.
        $router->get('/about', function (): void {
            View::render('layout.php', [
                'title'   => 'About this example',
                'content' => 'about.php',
            ]);
        });
    }
}
