<?php

declare(strict_types=1);

namespace Cloude;

/**
 * Tiny immutable date-time helper extending `\DateTimeImmutable`. Adds
 * the shortcuts you reach for every day without dragging a Carbon-sized
 * dependency in.
 *
 * Stays a `\DateTimeImmutable` subclass on purpose: hand it to any
 * function that already takes `\DateTimeInterface` and it just works.
 *
 * Static constructors:
 *
 *   DateTime::now();                    // current moment in default TZ
 *   DateTime::today();                  // today at 00:00:00
 *   DateTime::parse('2026-05-18');      // any strtotime-compatible string
 *   DateTime::fromTimestamp(1716000000);
 *
 * Format shortcuts (the ones we type every week):
 *
 *   $d->toDateString();        // '2026-05-18'
 *   $d->toTimeString();        // '14:30:00'
 *   $d->toDateTimeString();    // '2026-05-18 14:30:00'
 *   $d->toIsoString();         // '2026-05-18T14:30:00+02:00'
 *
 * Arithmetic returning a new instance:
 *
 *   $d->addDays(7)->subHours(2)
 *     ->addMinutes(15)->addMonths(1);
 *
 * Boundaries:
 *
 *   $d->startOfDay();          // 00:00:00
 *   $d->endOfDay();            // 23:59:59
 *   $d->startOfMonth();
 *   $d->endOfMonth();
 *
 * Comparisons:
 *
 *   $d->isPast(); $d->isFuture(); $d->isToday();
 *   $d->isBefore($other); $d->isAfter($other); $d->isSameDay($other);
 *
 * Diff:
 *
 *   $d->diffInDays($other);    // signed int — negative if $d is later
 *   $d->diffInMinutes(...);    // … and `Hours`, `Seconds`
 *   $d->diffForHumans();       // "in 3 days", "2 hours ago" — English
 *
 * Cast integration: declaring `'col' => 'datetime'` in `Model::$types`
 * yields instances of this class, so the helpers are available without
 * any extra wiring.
 */
final class DateTime extends \DateTimeImmutable
{
    // ── test-only time freezing (Carbon-style) ────────────────────────────

    private static ?\DateTimeImmutable $testNow = null;

    /**
     * Freeze `now()` to a specific moment. Test-only — production code
     * must never call this. Pass `null` to release the freeze.
     *
     *   DateTime::setTestNow(new DateTime('2026-05-18 12:00:00'));
     *   DateTime::now()->toDateTimeString();   // '2026-05-18 12:00:00'
     *   DateTime::clearTestNow();
     *
     * `Cloude\Testing\TestCase::setUp()` clears the freeze between tests
     * so leaks between cases are caught by the very next test that calls
     * `now()`.
     */
    public static function setTestNow(?\DateTimeInterface $when): void
    {
        if ($when === null) {
            self::$testNow = null;
            return;
        }
        // Snapshot to a plain DateTimeImmutable so the frozen moment
        // can never be mutated externally.
        self::$testNow = new \DateTimeImmutable(
            $when->format('Y-m-d H:i:s.u'),
            $when->getTimezone(),
        );
    }

    public static function clearTestNow(): void
    {
        self::$testNow = null;
    }

    public static function hasTestNow(): bool
    {
        return self::$testNow !== null;
    }

    // ── static constructors ───────────────────────────────────────────────

    public static function now(?\DateTimeZone $tz = null): static
    {
        if (self::$testNow !== null) {
            return new static(
                self::$testNow->format('Y-m-d H:i:s.u'),
                $tz ?? self::$testNow->getTimezone(),
            );
        }
        return new static('now', $tz);
    }

    /** Today at 00:00:00 in the given (or default) timezone. */
    public static function today(?\DateTimeZone $tz = null): static
    {
        return static::now($tz)->startOfDay();
    }

    /**
     * Build from a parseable string. Throws `\InvalidArgumentException`
     * (not the cryptic `\Exception` PHP normally raises) when invalid.
     */
    public static function parse(string $value, ?\DateTimeZone $tz = null): static
    {
        try {
            return new static($value, $tz);
        } catch (\Exception $e) {
            throw new \InvalidArgumentException("Cannot parse date '$value': " . $e->getMessage(), 0, $e);
        }
    }

    public static function fromTimestamp(int $timestamp, ?\DateTimeZone $tz = null): static
    {
        $d = (new static('@' . $timestamp));
        return $tz !== null ? $d->setTimezone($tz) : $d;
    }

    // ── format shortcuts ──────────────────────────────────────────────────

    /** `'Y-m-d'` — e.g. `'2026-05-18'`. */
    public function toDateString(): string
    {
        return $this->format('Y-m-d');
    }

    /** `'H:i:s'` — e.g. `'14:30:00'`. */
    public function toTimeString(): string
    {
        return $this->format('H:i:s');
    }

    /** `'Y-m-d H:i:s'` — MySQL DATETIME-compatible. */
    public function toDateTimeString(): string
    {
        return $this->format('Y-m-d H:i:s');
    }

    /** ISO-8601 / RFC 3339 — `'c'` format. */
    public function toIsoString(): string
    {
        return $this->format('c');
    }

    // ── arithmetic (always returns a new instance) ────────────────────────

    public function addSeconds(int $n): static
    {
        return $this->modify(($n >= 0 ? '+' : '') . $n . ' seconds');
    }

    public function subSeconds(int $n): static
    {
        return $this->addSeconds(-$n);
    }

    public function addMinutes(int $n): static
    {
        return $this->modify(($n >= 0 ? '+' : '') . $n . ' minutes');
    }

    public function subMinutes(int $n): static
    {
        return $this->addMinutes(-$n);
    }

    public function addHours(int $n): static
    {
        return $this->modify(($n >= 0 ? '+' : '') . $n . ' hours');
    }

    public function subHours(int $n): static
    {
        return $this->addHours(-$n);
    }

    public function addDays(int $n): static
    {
        return $this->modify(($n >= 0 ? '+' : '') . $n . ' days');
    }

    public function subDays(int $n): static
    {
        return $this->addDays(-$n);
    }

    public function addWeeks(int $n): static
    {
        return $this->addDays($n * 7);
    }

    public function subWeeks(int $n): static
    {
        return $this->addWeeks(-$n);
    }

    public function addMonths(int $n): static
    {
        return $this->modify(($n >= 0 ? '+' : '') . $n . ' months');
    }

    public function subMonths(int $n): static
    {
        return $this->addMonths(-$n);
    }

    public function addYears(int $n): static
    {
        return $this->modify(($n >= 0 ? '+' : '') . $n . ' years');
    }

    public function subYears(int $n): static
    {
        return $this->addYears(-$n);
    }

    // ── boundaries ────────────────────────────────────────────────────────

    public function startOfDay(): static
    {
        return $this->setTime(0, 0, 0);
    }

    public function endOfDay(): static
    {
        return $this->setTime(23, 59, 59);
    }

    public function startOfMonth(): static
    {
        return $this->modify('first day of this month')->startOfDay();
    }

    public function endOfMonth(): static
    {
        return $this->modify('last day of this month')->endOfDay();
    }

    // ── comparisons ───────────────────────────────────────────────────────

    public function isPast(): bool
    {
        return $this < static::now();
    }

    public function isFuture(): bool
    {
        return $this > static::now();
    }

    public function isToday(): bool
    {
        return $this->toDateString() === static::now()->toDateString();
    }

    public function isYesterday(): bool
    {
        return $this->toDateString() === static::now()->subDays(1)->toDateString();
    }

    public function isTomorrow(): bool
    {
        return $this->toDateString() === static::now()->addDays(1)->toDateString();
    }

    public function isBefore(\DateTimeInterface $other): bool
    {
        return $this < $other;
    }

    public function isAfter(\DateTimeInterface $other): bool
    {
        return $this > $other;
    }

    public function isSameDay(\DateTimeInterface $other): bool
    {
        return $this->format('Y-m-d') === $other->format('Y-m-d');
    }

    // ── diff helpers ──────────────────────────────────────────────────────

    /**
     * Signed difference in days (positive when $other is later than $this,
     * negative otherwise — matches the arithmetic intuition $other - $this).
     */
    public function diffInDays(\DateTimeInterface $other): int
    {
        return (int) round(($other->getTimestamp() - $this->getTimestamp()) / 86400);
    }

    public function diffInHours(\DateTimeInterface $other): int
    {
        return (int) round(($other->getTimestamp() - $this->getTimestamp()) / 3600);
    }

    public function diffInMinutes(\DateTimeInterface $other): int
    {
        return (int) round(($other->getTimestamp() - $this->getTimestamp()) / 60);
    }

    public function diffInSeconds(\DateTimeInterface $other): int
    {
        return $other->getTimestamp() - $this->getTimestamp();
    }

    /**
     * English "ago / in" rendering. Compares against `now()` by default;
     * pass an explicit reference time for testable code.
     *
     *   DateTime::now()->subMinutes(5)->diffForHumans();      // '5 minutes ago'
     *   DateTime::now()->addDays(3)->diffForHumans();         // 'in 3 days'
     *
     * No localisation by design — for multi-language apps render dates
     * with `IntlDateFormatter::formatRelative` (PHP 8.4+) instead.
     */
    public function diffForHumans(?\DateTimeInterface $now = null): string
    {
        $now ??= static::now();
        $seconds = $now->getTimestamp() - $this->getTimestamp();
        $past    = $seconds >= 0;
        $abs     = abs($seconds);

        [$value, $unit] = match (true) {
            $abs < 60                   => [$abs,            'second'],
            $abs < 3600                 => [intdiv($abs, 60), 'minute'],
            $abs < 86400                => [intdiv($abs, 3600), 'hour'],
            $abs < 86400 * 30           => [intdiv($abs, 86400), 'day'],
            $abs < 86400 * 365          => [intdiv($abs, 86400 * 30), 'month'],
            default                     => [intdiv($abs, 86400 * 365), 'year'],
        };

        $label = $value === 1 ? $unit : $unit . 's';
        return $past
            ? "$value $label ago"
            : "in $value $label";
    }
}
