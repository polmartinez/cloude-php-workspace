<?php

declare(strict_types=1);

namespace App\Domain\Shared;

/**
 * Marker for any rule violated inside the domain layer.
 *
 * Domain code throws this; presentation / application layers catch it and
 * translate into HTTP 4xx responses or user-visible flash messages.
 * Infrastructure errors (disk I/O, network, …) use other exception types.
 */
class DomainException extends \DomainException {}
