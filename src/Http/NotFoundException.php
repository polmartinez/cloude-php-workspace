<?php

declare(strict_types=1);

namespace Cloude\Http;

/**
 * Thrown when the requested resource doesn't exist. `ErrorHandler` renders
 * it as a 404 (HTML / JSON / plain-text depending on the request), using
 * the bundled `404.html.php` view — or your project's override if present
 * under `viewBase`.
 *
 *   $book = $repo->find($isbn) ?? throw new NotFoundException("book $isbn");
 */
class NotFoundException extends HttpException
{
    public function __construct(string $message = 'Not found', ?\Throwable $previous = null)
    {
        parent::__construct(404, $message, $previous);
    }
}
