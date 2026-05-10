<?php

declare(strict_types=1);

// ============================================================
// ENTRY POINT
// ============================================================

require_once dirname(__DIR__) . '/app/config.php';

if (defined('DEBUG') && DEBUG) {
    ini_set('display_errors', '1');
    ini_set('display_startup_errors', '1');
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', '0');
    error_reporting(0);
}

// Composer autoload, or fall back to the framework's src/ directory when
// running the example from the parent repository without `composer install`.
$autoload = dirname(__DIR__) . '/vendor/autoload.php';
if (file_exists($autoload)) {
    require_once $autoload;
} else {
    $frameworkSrc = dirname(__DIR__, 2) . '/src';
    if (is_dir($frameworkSrc)) {
        spl_autoload_register(function (string $class) use ($frameworkSrc): void {
            if (!str_starts_with($class, 'Cloude\\')) {
                return;
            }
            $relative = substr($class, strlen('Cloude\\'));
            $file = $frameworkSrc . '/' . str_replace('\\', '/', $relative) . '.php';
            if (is_file($file)) {
                require_once $file;
            }
        });
    }
}

use Cloude\Router;
use Cloude\View;

View::setBasePath(dirname(__DIR__) . '/views');

$router = new Router();

require_once dirname(__DIR__) . '/app/routes.php';
App\Routes::register($router);

$router->setNotFound(function (): void {
    View::render('layout.php', [
        'title'   => '404 - Not Found',
        'content' => '404.php',
    ]);
});

$router->dispatch();
