<?php

declare(strict_types=1);

namespace Cloude\Tests\Domain;

use Cloude\Domain\DomainException;
use Cloude\Domain\ValueObject;
use PHPUnit\Framework\TestCase;

final class Money extends ValueObject
{
    public function __construct(
        public readonly int $amount,        // in cents
        public readonly string $currency,
    ) {
        if ($amount < 0) {
            throw new DomainException('Money cannot be negative');
        }
    }

    public function __toString(): string
    {
        return number_format($this->amount / 100, 2) . ' ' . $this->currency;
    }
}

final class EmailAddress extends ValueObject
{
    public function __construct(public readonly string $value)
    {
        if (!filter_var($value, FILTER_VALIDATE_EMAIL)) {
            throw new DomainException("Invalid email: '$value'");
        }
    }

    public function __toString(): string
    {
        return $this->value;
    }
}

final class ValueObjectTest extends TestCase
{
    public function testEqualsByStructure(): void
    {
        $a = new Money(1250, 'EUR');
        $b = new Money(1250, 'EUR');
        $c = new Money(1250, 'USD');
        $d = new Money(2500, 'EUR');

        self::assertTrue($a->equals($b));
        self::assertFalse($a->equals($c));     // different currency
        self::assertFalse($a->equals($d));     // different amount
    }

    public function testEqualsRejectsDifferentClasses(): void
    {
        $money = new Money(100, 'EUR');
        $email = new EmailAddress('a@b.test');

        self::assertFalse($money->equals($email));
    }

    public function testStringableRenders(): void
    {
        self::assertSame('12.50 EUR', (string) new Money(1250, 'EUR'));
        self::assertSame('ada@x.test', (string) new EmailAddress('ada@x.test'));
    }

    public function testConstructorEnforcesInvariants(): void
    {
        $this->expectException(DomainException::class);
        new Money(-1, 'EUR');
    }

    public function testEmailInvariantThrows(): void
    {
        $this->expectException(DomainException::class);
        new EmailAddress('not-an-email');
    }

    public function testDomainExceptionExtendsSplDomain(): void
    {
        self::assertInstanceOf(\DomainException::class, new DomainException('x'));
    }
}
