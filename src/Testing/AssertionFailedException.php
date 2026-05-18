<?php

declare(strict_types=1);

namespace Cloude\Testing;

/**
 * Thrown by {@see Assert} methods on failure. The {@see Runner} catches
 * it to mark the running test as a failure (vs. an unexpected error).
 */
final class AssertionFailedException extends \RuntimeException {}
