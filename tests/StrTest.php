<?php

declare(strict_types=1);

namespace Cloude\Tests;

use Cloude\Str;
use Cloude\Testing\TestCase;

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

    // ── truncateMiddle ───────────────────────────────────────────────────────

    public function testTruncateMiddleReturnsTextWhenShorterThanLimit(): void
    {
        self::assertSame('hello', Str::truncateMiddle('hello', 10));
    }

    public function testTruncateMiddleReturnsTextWhenExactlyAtLimit(): void
    {
        self::assertSame('hello', Str::truncateMiddle('hello', 5));
    }

    public function testTruncateMiddleSplitsAroundEllipsis(): void
    {
        // "Cloude framework for PHP 8.4" → length 28
        // maxLength 16, ellipsis '...' (3) → 13 chars from source, 6+7 split
        $out = Str::truncateMiddle('Cloude framework for PHP 8.4', 16);
        self::assertSame('Cloude...PHP 8.4', $out);
        self::assertSame(16, mb_strlen($out));
    }

    public function testTruncateMiddleFinalLengthNeverExceedsLimit(): void
    {
        $long = str_repeat('abcdefghij', 50);                  // 500 chars
        foreach ([5, 8, 10, 25, 100, 499] as $limit) {
            $out = Str::truncateMiddle($long, $limit);
            self::assertLessThanOrEqual($limit, mb_strlen($out), "limit $limit overflowed");
        }
    }

    public function testTruncateMiddleEndGetsExtraCharForOddBudget(): void
    {
        // maxLength 10, ellipsis '...' (3) → 7 source chars, floor(7/2)=3 start, ceil(7/2)=4 end
        $out = Str::truncateMiddle('1234567890ABCDEF', 10);
        self::assertSame('123...CDEF', $out);
        self::assertSame(10, mb_strlen($out));
    }

    public function testTruncateMiddleCustomEllipsis(): void
    {
        $out = Str::truncateMiddle('1234567890ABCDEFGHIJ', 13, '…');     // unicode 1-char
        // available = 13 - 1 = 12 → 6+6 split
        self::assertSame('123456…EFGHIJ', $out);
        self::assertSame(13, mb_strlen($out));
    }

    public function testTruncateMiddleMultibyteSafe(): void
    {
        // 13 chars (España + tildes + ñ): "Política española"
        $out = Str::truncateMiddle('Política española y sus instituciones', 18);
        self::assertSame(18, mb_strlen($out), 'multibyte char count');
        // sanity: starts with "Política" prefix
        self::assertStringStartsWith('Polít', $out);
        self::assertStringEndsWith('iones', $out);
    }

    public function testTruncateMiddleWhenEllipsisLongerThanLimitClipsEllipsis(): void
    {
        // maxLength 2, ellipsis '...' (3) → just clip the ellipsis
        self::assertSame('..', Str::truncateMiddle('hello world', 2));
    }
}
