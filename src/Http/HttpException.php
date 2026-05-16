<?php

declare(strict_types=1);

namespace Cloude\Http;

/**
 * Base class for exceptions that carry an HTTP status code. Throw one of
 * these from anywhere in a request handler and `ErrorHandler::render()`
 * will use the carried status (and a matching default view) instead of
 * falling back to 503.
 *
 *   throw new \Cloude\Http\NotFoundException("article '$slug'");
 *   throw new \Cloude\Http\HttpException(403, 'forbidden');
 *
 * The framework ships a default view for 404 (`src/views/404.html.php`);
 * other statuses fall back to the 500 view template. Override either by
 * placing a `404.html.php` / `500.html.php` under your project's
 * `viewBase` directory (the one passed to `ErrorHandler::register`).
 */
class HttpException extends \RuntimeException
{
    public function __construct(
        public readonly int $statusCode,
        string $message = '',
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
