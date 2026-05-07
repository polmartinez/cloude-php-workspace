<?php

declare(strict_types=1);

namespace Cloude;

/**
 * Minimalist router with /{param} patterns.
 *
 * Pattern syntax:
 *
 *   /{name}            captures any non-slash segment as $params['name']
 *   /{name?}           same, but the segment (and its leading slash) is optional
 *   /{name:[a-z]+}     constrains the capture to a regex
 *   /{name?:\d+}       optional + constrained
 *
 * Helpers:
 *
 *   $router->get('/users/{id:\d+}', fn ($p) => echo $p['id']);
 *   $router->post('/users', fn () => ...);
 *   $router->setNotFound(fn () => View::render('404.php'));
 *
 *   $router->group('/api/v1', function (Router $r) {
 *       $r->get('/users', $list);
 *       $r->get('/users/{id:\d+}', $show);
 *       $r->group('/admin', function (Router $r) {
 *           $r->get('/stats', $stats);  // → /api/v1/admin/stats
 *       });
 *   });
 *
 *   $router->dispatch();
 */
class Router
{
    /** @var array<int, array{pattern:string, handler:callable, methods:array<string>}> */
    private array $routes = [];

    private string $basePath;

    /** @var callable|null */
    private $notFound = null;

    /** @var array<int,string> */
    private array $groupStack = [];

    public function __construct(string $basePath = '')
    {
        $this->basePath = rtrim($basePath, '/');
    }

    /**
     * Registers a route. $pattern may be a string or an array of patterns
     * sharing the same handler.
     *
     * $methods accepts a string ('GET'), an array (['GET','POST']) or '*' for any.
     *
     * @param string|array<int,string>     $pattern
     * @param string|array<int,string>     $methods
     */
    public function add(string|array $pattern, callable $handler, string|array $methods = 'GET'): void
    {
        $methods = is_array($methods) ? $methods : [$methods];
        $methods = array_map('strtoupper', $methods);

        $patterns = is_array($pattern) ? $pattern : [$pattern];
        foreach ($patterns as $p) {
            $this->routes[] = [
                'pattern' => $this->fullPattern($p),
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
     * Groups routes under a shared URL prefix. Groups are nestable.
     *
     *   $router->group('/api/v1', function (Router $r) {
     *       $r->get('/users', $list);  // → /api/v1/users
     *   });
     *
     * The router instance is passed to the callback for clarity, though
     * any reference to the same `$router` works (it's the same object).
     */
    public function group(string $prefix, callable $callback): void
    {
        $this->groupStack[] = '/' . trim($prefix, '/');
        try {
            $callback($this);
        } finally {
            array_pop($this->groupStack);
        }
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

            $params = $this->match($route['pattern'], $uri);
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
     * Builds the full pattern by stacking the active group prefixes
     * onto $pattern. A bare "/" inside a group resolves to the prefix
     * itself, which is the natural expectation.
     */
    private function fullPattern(string $pattern): string
    {
        $pattern = '/' . ltrim($pattern, '/');
        if ($this->groupStack === []) {
            return $pattern;
        }
        $prefix = implode('', $this->groupStack);
        return $pattern === '/' ? $prefix : $prefix . $pattern;
    }

    /**
     * Matches a URI against a compiled pattern. Returns the named-parameter
     * array, or false if it does not match.
     *
     * @return array<string,string>|false
     */
    private function match(string $pattern, string $uri): array|false
    {
        $regex = self::compile($pattern);
        if (preg_match($regex, $uri, $matches)) {
            return array_filter($matches, fn ($k) => !is_int($k), ARRAY_FILTER_USE_KEY);
        }
        return false;
    }

    /**
     * Compiles a route pattern to an anchored regex.
     *
     * Recognised tokens:
     *   {name}           → (?P<name>[^/]+)
     *   {name?}          → optional, including the leading '/'
     *   {name:regex}     → constrains the capture to `regex`
     *   {name?:regex}    → both (optional + constrained)
     */
    private static function compile(string $pattern): string
    {
        $regex = preg_replace_callback(
            '#(/)?\{([a-zA-Z_][a-zA-Z0-9_]*)(\?)?(?::([^}]+))?\}#',
            static function (array $m): string {
                $slash      = $m[1] ?? '';
                $name       = $m[2];
                $optional   = ($m[3] ?? '') === '?';
                $constraint = ($m[4] ?? '') !== '' ? $m[4] : '[^/]+';
                $captured   = "(?P<{$name}>{$constraint})";
                if ($optional) {
                    return $slash !== '' ? "(?:{$slash}{$captured})?" : "{$captured}?";
                }
                return $slash . $captured;
            },
            $pattern,
        );
        return '#^' . $regex . '$#';
    }
}
