<?php

declare(strict_types=1);

namespace Cloude\Tests;

use Cloude\DateTime;
use Cloude\Testing\TestCase;

final class DateTimeTest extends TestCase
{
    public function testIsImmutableSubclass(): void
    {
        $d = DateTime::parse('2026-05-18');
        self::assertInstanceOf(\DateTimeImmutable::class, $d);
        self::assertInstanceOf(\DateTimeInterface::class, $d);
    }

    public function testStaticConstructors(): void
    {
        $now    = DateTime::now();
        $today  = DateTime::today();
        $parsed = DateTime::parse('2026-05-18 14:30:00');
        $fromTs = DateTime::fromTimestamp(1716000000);

        self::assertSame($today->toDateString(), $now->toDateString());
        self::assertSame('00:00:00', $today->toTimeString());
        self::assertSame('2026-05-18 14:30:00', $parsed->toDateTimeString());
        self::assertSame(1716000000, $fromTs->getTimestamp());
    }

    public function testParseThrowsInvalidArgument(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        DateTime::parse('not a date');
    }

    public function testFormatShortcuts(): void
    {
        $d = DateTime::parse('2026-05-18T14:30:00+00:00');
        self::assertSame('2026-05-18', $d->toDateString());
        self::assertSame('14:30:00', $d->toTimeString());
        self::assertSame('2026-05-18 14:30:00', $d->toDateTimeString());
        self::assertSame('2026-05-18T14:30:00+00:00', $d->toIsoString());
    }

    public function testStringCastEmitsYmdHisFormat(): void
    {
        $d = DateTime::parse('2026-05-18 14:30:45');

        // Explicit cast
        self::assertSame('2026-05-18 14:30:45', (string) $d);
        // String interpolation
        self::assertSame('at 2026-05-18 14:30:45', "at $d");
        // Concatenation
        self::assertSame('row-2026-05-18 14:30:45', 'row-' . $d);
        // Mirrors toDateTimeString() — same MySQL-shaped output
        self::assertSame($d->toDateTimeString(), (string) $d);
    }

    public function testStringCastDropsTimezoneForMysqlCompat(): void
    {
        // String cast intentionally strips the offset; for ISO output
        // (with offset) callers go through toIsoString() explicitly.
        $d = DateTime::parse('2026-05-18 14:30:00', new \DateTimeZone('+05:30'));
        self::assertSame('2026-05-18 14:30:00', (string) $d);
        self::assertStringContainsString('+05:30', $d->toIsoString());
    }

    public function testStringCastIsImplementsStringable(): void
    {
        $d = DateTime::now();
        self::assertInstanceOf(\Stringable::class, $d);
    }

    public function testAdditionAndSubtractionReturnNewInstance(): void
    {
        $d = DateTime::parse('2026-05-18 12:00:00');

        self::assertSame('2026-05-19 12:00:00', $d->addDays(1)->toDateTimeString());
        self::assertSame('2026-05-17 12:00:00', $d->subDays(1)->toDateTimeString());
        self::assertSame('2026-05-25 12:00:00', $d->addWeeks(1)->toDateTimeString());
        self::assertSame('2026-06-18 12:00:00', $d->addMonths(1)->toDateTimeString());
        self::assertSame('2027-05-18 12:00:00', $d->addYears(1)->toDateTimeString());
        self::assertSame('2026-05-18 14:30:00', $d->addHours(2)->addMinutes(30)->toDateTimeString());
        self::assertSame('2026-05-18 11:59:30', $d->subMinutes(0)->subSeconds(30)->toDateTimeString());

        // Original is untouched (immutability).
        self::assertSame('2026-05-18 12:00:00', $d->toDateTimeString());
    }

    public function testBoundaries(): void
    {
        $d = DateTime::parse('2026-05-18 14:30:45');
        self::assertSame('2026-05-18 00:00:00', $d->startOfDay()->toDateTimeString());
        self::assertSame('2026-05-18 23:59:59', $d->endOfDay()->toDateTimeString());
        self::assertSame('2026-05-01 00:00:00', $d->startOfMonth()->toDateTimeString());
        self::assertSame('2026-05-31 23:59:59', $d->endOfMonth()->toDateTimeString());
    }

    public function testIsBeforeAfterSameDay(): void
    {
        $a = DateTime::parse('2026-05-18 12:00:00');
        $b = DateTime::parse('2026-05-19 09:00:00');
        $c = DateTime::parse('2026-05-18 23:59:59');

        self::assertTrue($a->isBefore($b));
        self::assertTrue($b->isAfter($a));
        self::assertTrue($a->isSameDay($c));
        self::assertFalse($a->isSameDay($b));
    }

    public function testIsPastFutureToday(): void
    {
        self::assertTrue(DateTime::now()->subMinutes(10)->isPast());
        self::assertTrue(DateTime::now()->addMinutes(10)->isFuture());
        self::assertTrue(DateTime::now()->isToday());
        self::assertTrue(DateTime::now()->subDays(1)->isYesterday());
        self::assertTrue(DateTime::now()->addDays(1)->isTomorrow());
        self::assertFalse(DateTime::now()->subDays(3)->isToday());
    }

    public function testDiffHelpers(): void
    {
        $a = DateTime::parse('2026-05-18 12:00:00');
        $b = DateTime::parse('2026-05-20 18:00:00');   // +2 days + 6 hours

        self::assertSame(2, $a->diffInDays($b));
        self::assertSame(54, $a->diffInHours($b));     // 48 + 6
        self::assertSame(54 * 60, $a->diffInMinutes($b));
        self::assertSame(54 * 3600, $a->diffInSeconds($b));

        // Negative when $other is earlier than $this.
        self::assertSame(-2, $b->diffInDays($a));
    }

    public function testDiffForHumansPast(): void
    {
        $now = DateTime::parse('2026-05-18 12:00:00');
        self::assertSame('30 seconds ago', $now->subSeconds(30)->diffForHumans($now));
        self::assertSame('1 minute ago', $now->subMinutes(1)->diffForHumans($now));
        self::assertSame('5 minutes ago', $now->subMinutes(5)->diffForHumans($now));
        self::assertSame('2 hours ago', $now->subHours(2)->diffForHumans($now));
        self::assertSame('3 days ago', $now->subDays(3)->diffForHumans($now));
        self::assertSame('2 months ago', $now->subDays(70)->diffForHumans($now));
        self::assertSame('1 year ago', $now->subDays(400)->diffForHumans($now));
    }

    public function testDiffForHumansFuture(): void
    {
        $now = DateTime::parse('2026-05-18 12:00:00');
        self::assertSame('in 5 minutes', $now->addMinutes(5)->diffForHumans($now));
        self::assertSame('in 3 days', $now->addDays(3)->diffForHumans($now));
    }

    public function testStringComparisonOperatorsWorkUnchanged(): void
    {
        // Subclass plays well with PDO-style usage: `< > ==` are inherited.
        $a = DateTime::parse('2026-05-18');
        $b = DateTime::parse('2026-05-19');
        self::assertTrue($a < $b);
        self::assertTrue($b > $a);
    }

    public function testCastDatetimeReturnsClouddDateTime(): void
    {
        $value = \Cloude\Model\Cast::read('2026-05-18 12:00:00', 'datetime');
        self::assertInstanceOf(DateTime::class, $value);
        self::assertInstanceOf(\DateTimeImmutable::class, $value);
    }

    public function testSetTestNowFreezesNow(): void
    {
        DateTime::setTestNow(new DateTime('2026-05-18 12:00:00'));
        try {
            self::assertSame('2026-05-18 12:00:00', DateTime::now()->toDateTimeString());
            usleep(1000);
            self::assertSame('2026-05-18 12:00:00', DateTime::now()->toDateTimeString());
            self::assertTrue(DateTime::hasTestNow());
        } finally {
            DateTime::clearTestNow();
        }
        self::assertFalse(DateTime::hasTestNow());
    }

    public function testTodayUsesFrozenNow(): void
    {
        DateTime::setTestNow(new DateTime('2026-05-18 23:00:00'));
        try {
            self::assertSame('2026-05-18', DateTime::today()->toDateString());
            self::assertSame('00:00:00', DateTime::today()->toTimeString());
        } finally {
            DateTime::clearTestNow();
        }
    }
}
