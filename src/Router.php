<?php

declare(strict_types=1);

namespace Cloude;

/**
 * Minimalist router with /{param} patterns.
 *
 * Example:
 *   $router = new Router();
 *   $router->get('/users/{id}', fn($p) => echo $p['id']);
 *   $router->post('/users', fn() => ...);
 *   $router->setNotFound(fn() => View::render('404.php'));
 *   $router->dispatch();
 */
class Router
{
    /** @var array<int, array{pattern:string, handler:callable, methods:array<string>}> */
    private array $routes = [];

    private string $basePath;

    /** @var callable|null */
    private $notFound = null;

    public function __construct(string $basePath = '')
    {
        $this->basePath = rtrim($basePath, '/');
    }

    /**
     * Registers a route. $pattern may be a string or an array of patterns
     * sharing the same handler.
     *
     * $methods accepts a string ('GET'), an array (['GET','POST']) or '*' for any.
     */
    public function add(string|array $pattern, callable $handler, string|array $methods = 'GET'): void
    {
        $methods = is_array($methods) ? $methods : [$methods];
        $methods = array_map('strtoupper', $methods);

        $patterns = is_array($pattern) ? $pattern : [$pattern];
        foreach ($patterns as $p) {
            $this->routes[] = [
                'pattern' => $p,
                'handler' => $handler,
                'methods' => $methods,
            ];
        }
    }

    public function get(string|array $pattern, callable $handler): void
    {
        $this->add($pattern, $handler, 'GET');
    }

    public function post(string|array $pattern, callable $handler): void
    {
        $this->add($pattern, $handler, 'POST');
    }

    public function put(string|array $pattern, callable $handler): void
    {
        $this->add($pattern, $handler, 'PUT');
    }

    public function patch(string|array $pattern, callable $handler): void
    {
        $this->add($pattern, $handler, 'PATCH');
    }

    public function delete(string|array $pattern, callable $handler): void
    {
        $this->add($pattern, $handler, 'DELETE');
    }

    public function any(string|array $pattern, callable $handler): void
    {
        $this->add($pattern, $handler, '*');
    }

    /**
     * Handler executed when no route matches.
     */
    public function setNotFound(callable $handler): void
    {
        $this->notFound = $handler;
    }

    /**
     * Runs the router against the current request URL.
     */
    public function dispatch(): void
    {
        $uri = Input::uri();
        $method = Input::method();

        if ($this->basePath && str_starts_with($uri, $this->basePath)) {
            $uri = substr($uri, strlen($this->basePath));
        }
        $uri = '/' . trim($uri, '/');

        foreach ($this->routes as $route) {
            if (
                !in_array('*', $route['methods'], true)
                && !in_array($method, $route['methods'], true)
            ) {
                continue;
            }

            $pattern = '/' . trim($route['pattern'], '/');
            $params = $this->match($pattern, $uri);
            if ($params !== false) {
                call_user_func($route['handler'], $params);
                return;
            }
        }

        http_response_code(404);
        if ($this->notFound) {
            call_user_func($this->notFound);
        } else {
            header('Content-Type: text/plain; charset=utf-8');
            echo '404 Not Found';
        }
    }

    /**
     * Matches a URI against a pattern. Returns the named-parameter array,
     * or false if it does not match.
     */
    private function match(string $pattern, string $uri): array|false
    {
        $regex = preg_replace('/\{([a-zA-Z_][a-zA-Z0-9_]*)\}/', '(?P<$1>[^/]+)', $pattern);
        $regex = '#^' . $regex . '$#';

        if (preg_match($regex, $uri, $matches)) {
            return array_filter($matches, fn($k) => !is_int($k), ARRAY_FILTER_USE_KEY);
        }
        return false;
    }
}
