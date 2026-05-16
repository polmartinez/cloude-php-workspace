<?php

declare(strict_types=1);

// Autoload — composer if installed, otherwise minimal PSR-4 fallback for both
// the framework (Cloude\) and the demo app (App\). Same pattern as the other
// examples in this repo.

$autoload = dirname(__DIR__, 3) . '/vendor/autoload.php';
if (file_exists($autoload)) {
    require_once $autoload;
}

$frameworkSrc = dirname(__DIR__, 3) . '/src';
$appSrc       = dirname(__DIR__) . '/app';

spl_autoload_register(static function (string $class) use ($frameworkSrc, $appSrc): void {
    foreach ([
        'Cloude\\' => $frameworkSrc,
        'App\\'    => $appSrc,
    ] as $prefix => $base) {
        if (str_starts_with($class, $prefix)) {
            $relative = substr($class, strlen($prefix));
            $file = $base . '/' . str_replace('\\', '/', $relative) . '.php';
            if (is_file($file)) {
                require_once $file;
                return;
            }
        }
    }
});

require_once dirname(__DIR__) . '/app/config.php';

if (\Cloude\Bootstrap::serveStaticIfExists(__DIR__)) {
    return false;
}

\Cloude\Bootstrap::run(
    debug:    DEBUG,
    viewBase: dirname(__DIR__) . '/views',
);

$router = new \Cloude\Router();

\App\Routes::register($router);

$router->setNotFound(static function (): void {
    \Cloude\View::render('layout.html.php', [
        'title'   => '404 - Not found',
        'content' => '404.html.php',
    ]);
});

$router->dispatch();
