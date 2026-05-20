<?php

declare(strict_types=1);

namespace Cloude\Tests\Testing;

use Cloude\Testing\Assert;
use Cloude\Testing\AssertionFailedException;
use Cloude\Testing\TestCase;

/**
 * Direct coverage of `Cloude\Testing\Assert` methods that don't already
 * get exercised by every other test (since most assertions run
 * thousands of times across the suite, a green run alone is good
 * proof). Focused here on the edge / failure paths and the newer
 * helpers (`equalsWithDelta`).
 */
final class AssertTest extends TestCase
{
    // ── equalsWithDelta ──────────────────────────────────────────────────

    public function testEqualsWithDeltaPassesWithinTolerance(): void
    {
        Assert::equalsWithDelta(0.3, 0.1 + 0.2, 1e-9);   // classic float-precision case
        Assert::equalsWithDelta(100, 100.0000001, 1e-6);
        Assert::equalsWithDelta(1, 1, 0);                 // zero-delta exact match
        $this->assertTrue(true);                          // bookkeep — three passes above
    }

    public function testEqualsWithDeltaFailsBeyondTolerance(): void
    {
        // Catching AssertionFailedException directly: the test Runner
        // intercepts that exception as the failure marker, so
        // expectException() can't be used on it from inside another
        // test.
        try {
            Assert::equalsWithDelta(1.0, 1.5, 0.001);
            self::fail('expected AssertionFailedException');
        } catch (AssertionFailedException $e) {
            self::assertStringContainsString('within delta 0.001', $e->getMessage());
            self::assertStringContainsString('actual diff: 0.5', $e->getMessage());
        }
    }

    public function testEqualsWithDeltaRejectsNegativeDelta(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Assert::equalsWithDelta(1, 1, -0.0001);
    }

    public function testEqualsWithDeltaRejectsNonNumericValues(): void
    {
        try {
            Assert::equalsWithDelta('foo', 1.0, 0.001);
            self::fail('expected AssertionFailedException');
        } catch (AssertionFailedException $e) {
            self::assertStringContainsString('numeric', $e->getMessage());
        }
    }

    public function testEqualsWithDeltaAcceptsNumericStrings(): void
    {
        // is_numeric() accepts numeric-string inputs — common with
        // values pulled fresh out of $_POST / Input::post().
        Assert::equalsWithDelta('1.0', 1.0, 0.0001);
        Assert::equalsWithDelta('99.99', 99.99, 0);
        $this->assertTrue(true);
    }

    // ── TestCase forwarder ──────────────────────────────────────────────

    public function testTestCaseForwarderRoutesToAssert(): void
    {
        // The wrapper on TestCase must behave identically.
        $this->assertEqualsWithDelta(0.3, 0.1 + 0.2, 1e-9);
        self::assertEqualsWithDelta(0.3, 0.1 + 0.2, 1e-9);
    }
}
