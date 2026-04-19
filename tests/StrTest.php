<?php

declare(strict_types=1);

namespace Cloude\Tests;

use Cloude\Str;
use PHPUnit\Framework\TestCase;

final class StrTest extends TestCase
{
    public function testUpToReturnsTextBeforeChar(): void
    {
        self::assertSame('hello', Str::upTo('hello world', ' '));
    }

    public function testUpToReturnsFullStringWhenCharMissing(): void
    {
        self::assertSame('hello', Str::upTo('hello', '.'));
    }

    public function testTruncateRespectsLength(): void
    {
        self::assertSame('hell...', Str::truncate('hello world', 4));
    }

    public function testTruncateDoesNotTrimShortText(): void
    {
        self::assertSame('hello', Str::truncate('hello', 10));
    }

    public function testSlugFallbackProducesUrlSafeOutput(): void
    {
        $slug = Str::slug('Hello World');
        self::assertMatchesRegularExpression('/^[a-z0-9\-]+$/', $slug);
        self::assertSame('hello-world', $slug);
    }
}
