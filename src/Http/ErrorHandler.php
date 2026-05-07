<?php

declare(strict_types=1);

namespace Cloude\Http;

/**
 * Global error / exception handler that turns unhandled errors into a 503
 * (treating them as temporary unavailability so crawlers retry instead of
 * deindexing the URL) and renders an appropriate response based on the
 * Accept header / URL extension:
 *
 *   - JSON  →  application/json
 *   - .md   →  text/plain
 *   - HTML  →  500.html.php (debug: 500-debug.html.php with stack trace)
 *
 * Usage in www/index.php, BEFORE any output:
 *
 *   ob_start();
 *   \Cloude\Http\ErrorHandler::register(
 *       debug:    DEBUG,
 *       viewBase: dirname(__DIR__) . '/app/views',
 *   );
 *
 * The viewBase is searched first for `500.html.php` / `500-debug.html.php`;
 * if not found, the framework's bundled views (under src/views/) are used.
 */
class ErrorHandler
{
    private static bool $debug = false;
    private static ?string $viewBase = null;

    /**
     * @param string|null $viewBase Directory containing optional 500.html.php /
     *                              500-debug.html.php overrides. If null or the
     *                              file is missing, framework defaults are used.
     */
    public static function register(bool $debug = false, ?string $viewBase = null): void
    {
        self::$debug = $debug;
        self::$viewBase = $viewBase !== null ? rtrim($viewBase, '/') : null;

        if ($debug) {
            ini_set('display_errors', '1');
            ini_set('display_startup_errors', '1');
            error_reporting(E_ALL);

            set_error_handler(static function (int $errno, string $errstr, string $errfile, int $errline): bool {
                if (!(error_reporting() & $errno)) {
                    return false;
                }
                throw new \ErrorException($errstr, 0, $errno, $errfile, $errline);
            });
        } else {
            ini_set('display_errors', '0');
            ini_set('display_startup_errors', '0');
            error_reporting(0);
        }

        set_exception_handler(static function (\Throwable $e): void {
            self::render($e);
        });

        register_shutdown_function(static function (): void {
            $err = error_get_last();
            if (!$err) {
                return;
            }
            if (!in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) {
                return;
            }
            if (headers_sent()) {
                return;
            }
            self::render(new \ErrorException($err['message'], 0, $err['type'], $err['file'], $err['line']));
        });
    }

    /**
     * Renders the error response (status 503) negotiating HTML / JSON / .md
     * based on the Accept header and the URL extension.
     */
    public static function render(\Throwable $e): void
    {
        $alreadySent = headers_sent();

        if (!$alreadySent) {
            http_response_code(503);
            header('Retry-After: 600');
            header('Cache-Control: no-store');
            while (ob_get_level() > 0) {
                ob_end_clean();
            }
        }

        $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
        $path   = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '';
        $wantsJson = str_contains($accept, 'application/json') || str_ends_with($path, '.json');
        $wantsMd   = str_ends_with($path, '.md');

        if ($wantsJson) {
            self::renderJson($e, $alreadySent);
            return;
        }
        if ($wantsMd) {
            self::renderMarkdown($e, $alreadySent);
            return;
        }
        self::renderHtml($e, $alreadySent);
    }

    private static function renderJson(\Throwable $e, bool $alreadySent): void
    {
        if (!$alreadySent) {
            header('Content-Type: application/json; charset=utf-8');
        }
        if (self::$debug) {
            echo json_encode([
                'error'   => get_class($e),
                'message' => $e->getMessage(),
                'file'    => $e->getFile(),
                'line'    => $e->getLine(),
                'trace'   => array_map(
                    static fn ($f) => ($f['file'] ?? '?') . ':' . ($f['line'] ?? '?')
                        . ' ' . ($f['class'] ?? '') . ($f['type'] ?? '') . ($f['function'] ?? ''),
                    $e->getTrace(),
                ),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            return;
        }
        echo '{"error":"internal_error","status":500}';
    }

    private static function renderMarkdown(\Throwable $e, bool $alreadySent): void
    {
        if (!$alreadySent) {
            header('Content-Type: text/plain; charset=utf-8');
        }
        if (self::$debug) {
            echo '# ' . get_class($e) . "\n\n" . $e->getMessage()
                . "\n\nat " . $e->getFile() . ':' . $e->getLine()
                . "\n\n" . $e->getTraceAsString();
            return;
        }
        echo "# 503\n\nService temporarily unavailable.";
    }

    private static function renderHtml(\Throwable $e, bool $alreadySent): void
    {
        if (!$alreadySent) {
            header('Content-Type: text/html; charset=utf-8');
        }

        $template = self::$debug ? '500-debug.html.php' : '500.html.php';
        $vars = self::$debug ? self::buildDebugVars($e) : [];

        $override = self::$viewBase !== null ? self::$viewBase . '/' . $template : null;
        $path = ($override !== null && is_file($override))
            ? $override
            : __DIR__ . '/../views/' . $template;

        // Render with isolated scope so view variables are scoped locally.
        (static function (string $__path, array $__vars): void {
            extract($__vars);
            require $__path;
        })($path, $vars);
    }

    /**
     * @return array{
     *   exceptionClass:string,
     *   message:string,
     *   file:string,
     *   line:int,
     *   reqInfo:string,
     *   snippet:string,
     *   traceRows:string,
     *   prev:string
     * }
     */
    private static function buildDebugVars(\Throwable $e): array
    {
        $h = static fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');

        $file = $e->getFile();
        $line = $e->getLine();
        $reqInfo = ($_SERVER['REQUEST_METHOD'] ?? 'GET') . ' ' . ($_SERVER['REQUEST_URI'] ?? '/');

        $snippet = '';
        if ($file && is_readable($file)) {
            $lines = @file($file, FILE_IGNORE_NEW_LINES) ?: [];
            $from  = max(0, $line - 8);
            $to    = min(count($lines) - 1, $line + 6);
            $rows  = [];
            for ($i = $from; $i <= $to; $i++) {
                $n = $i + 1;
                $cls = $n === $line ? ' class="hl"' : '';
                $rows[] = '<tr' . $cls . '><td class="ln">' . $n
                    . '</td><td><pre>' . $h($lines[$i] ?? '') . '</pre></td></tr>';
            }
            $snippet = '<table class="src"><tbody>' . implode('', $rows) . '</tbody></table>';
        }

        $traceRows = '';
        foreach ($e->getTrace() as $i => $f) {
            $loc  = ($f['file'] ?? '<internal>') . (isset($f['line']) ? ':' . $f['line'] : '');
            $call = ($f['class'] ?? '') . ($f['type'] ?? '') . ($f['function'] ?? '');
            $traceRows .= '<tr><td class="ln">#' . $i
                . '</td><td><code>' . $h($call) . '()</code>'
                . '<div class="loc">' . $h($loc) . '</div></td></tr>';
        }

        $prev = '';
        $p = $e->getPrevious();
        while ($p) {
            $prev .= '<div class="prev"><h3>Previous: ' . $h(get_class($p)) . '</h3>'
                . '<p>' . $h($p->getMessage()) . '</p>'
                . '<div class="loc">' . $h($p->getFile() . ':' . $p->getLine()) . '</div></div>';
            $p = $p->getPrevious();
        }

        return [
            'exceptionClass' => get_class($e),
            'message'        => $e->getMessage(),
            'file'           => $file,
            'line'           => $line,
            'reqInfo'        => $reqInfo,
            'snippet'        => $snippet,
            'traceRows'      => $traceRows,
            'prev'           => $prev,
        ];
    }
}
