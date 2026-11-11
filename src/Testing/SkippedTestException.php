<?php

declare(strict_types=1);

namespace Cloude\Testing;

/**
 * Thrown by `TestCase::markTestSkipped()`. The runner counts the test
 * as skipped (an `S` in the progress line) — neither a pass nor a
 * failure — and the run still exits 0.
 */
final class SkippedTestException extends \RuntimeException {}
