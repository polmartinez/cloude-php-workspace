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

    public function testSubFromStart(): void
    {
        self::assertSame('Cata', Str::sub('Cataluña', 0, 4));
    }

    public function testSubFromNegativeOffset(): void
    {
        self::assertSame('uña', Str::sub('Cataluña', -3));
    }

    public function testSubMiddleSlice(): void
    {
        self::assertSame('luñ', Str::sub('Cataluña', 4, 3));
    }

    public function testSubIsMultibyteSafe(): void
    {
        // 'ñ' is one codepoint, two bytes. byte-level substr() would
        // return mojibake; Str::sub respects codepoint boundaries.
        self::assertSame('ñ', Str::sub('Cataluña', 6, 1));
    }

    public function testLenCountsCodepoints(): void
    {
        self::assertSame(5, Str::len('hello'));
        self::assertSame(8, Str::len('Cataluña'));
        self::assertSame(0, Str::len(''));
    }

    public function testTruncateRespectsLengthIncludingEllipsis(): void
    {
        // Final length must never exceed $length — ellipsis budget comes
        // OUT of $length, not on top of it.
        $out = Str::truncate('hello world', 8);
        self::assertSame('hello...', $out);
        self::assertSame(8, mb_strlen($out));
    }

    public function testTruncateWithTightBudget(): void
    {
        // 4-char budget, 3-char ellipsis → 1 char of source + '...'.
        $out = Str::truncate('hello world', 4);
        self::assertSame('h...', $out);
        self::assertSame(4, mb_strlen($out));
    }

    public function testTruncateDoesNotTrimShortText(): void
    {
        self::assertSame('hello', Str::truncate('hello', 10));
    }

    public function testTruncateAtExactLengthIsUnchanged(): void
    {
        self::assertSame('hello', Str::truncate('hello', 5));
    }

    public function testTruncateCustomEllipsis(): void
    {
        $out = Str::truncate('hello world', 8, '…');   // single-char (multibyte) ellipsis
        self::assertSame('hello w…', $out);
        self::assertSame(8, mb_strlen($out));
    }

    public function testTruncateWhenEllipsisLongerThanLimitClipsEllipsis(): void
    {
        // Ellipsis itself doesn't fit → degrade gracefully.
        self::assertSame('..', Str::truncate('hello world', 2));
        self::assertSame('',   Str::truncate('hello world', 0));
    }

    public function testTruncateIsMultibyteSafe(): void
    {
        // 'áéíóú ñ' is 7 codepoints. Budget 5 → 2 source + '...' (3) = 5.
        $out = Str::truncate('áéíóú ñ', 5);
        self::assertSame('áé...', $out);
        self::assertSame(5, mb_strlen($out));
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
