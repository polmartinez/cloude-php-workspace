<?php

declare(strict_types=1);

namespace Cloude\Tests\Model;

use Cloude\Model\Cast;
use Cloude\Model\Model;
use Cloude\Model\Storage\ArrayStorage;
use Cloude\Testing\TestCase;

enum Status: string
{
    case Active = 'active';
    case Banned = 'banned';
}

final class NoCastUser extends Model
{
    protected static string $table = 'users';
}

final class TypedUser extends Model
{
    protected static string $table = 'users';
    /** @var list<string> */
    protected static array $properties = ['id', 'email', 'age', 'score', 'price', 'verified', 'tags', 'created_at', 'status'];
    /** @var array<string,string> */
    protected static array $types = [
        'id'         => 'int',
        'age'        => 'int',
        'score'      => 'float',
        'price'      => 'decimal:2',
        'verified'   => 'bool',
        'tags'       => 'json',
        'created_at' => 'datetime',
        'status'     => 'enum:' . Status::class,
    ];
}

final class CastTest extends TestCase
{
    // ── unit: Cast::read / Cast::write ────────────────────────────────────

    public function testNullPassesThroughOnReadAndWrite(): void
    {
        self::assertNull(Cast::read(null, 'int'));
        self::assertNull(Cast::read(null, 'datetime'));
        self::assertNull(Cast::write(null, 'json'));
        self::assertNull(Cast::write(null, 'enum:' . Status::class));
    }

    public function testIntReadAndWrite(): void
    {
        self::assertSame(42, Cast::read('42', 'int'));
        self::assertSame(0, Cast::read('not-a-number', 'int'));
        self::assertSame(42, Cast::write('42', 'integer'));
    }

    public function testFloatReadAndWrite(): void
    {
        self::assertSame(3.14, Cast::read('3.14', 'float'));
        self::assertSame(3.14, Cast::write('3.14', 'double'));
    }

    public function testBoolReadCoercesTruthyStrings(): void
    {
        self::assertTrue(Cast::read('1', 'bool'));
        self::assertTrue(Cast::read('true', 'bool'));
        self::assertTrue(Cast::read('yes', 'bool'));
        self::assertTrue(Cast::read(1, 'bool'));
        self::assertFalse(Cast::read('0', 'bool'));
        self::assertFalse(Cast::read('false', 'bool'));
        self::assertFalse(Cast::read(0, 'bool'));
    }

    public function testBoolWriteEmitsIntFlag(): void
    {
        self::assertSame(1, Cast::write(true, 'bool'));
        self::assertSame(0, Cast::write(false, 'bool'));
    }

    public function testDecimalNormalisesPrecision(): void
    {
        self::assertSame('12.50', Cast::read(12.5, 'decimal:2'));
        self::assertSame('12.500', Cast::read('12.5', 'decimal:3'));
        self::assertSame('12.50', Cast::read('12.5', 'decimal'));       // default 2
        self::assertSame('12.50', Cast::write(12.5, 'decimal:2'));
    }

    public function testJsonRoundTrip(): void
    {
        self::assertSame(['a' => 1], Cast::read('{"a":1}', 'json'));
        self::assertSame('{"a":1}', Cast::write(['a' => 1], 'json'));
        self::assertSame(['x'], Cast::read(['x'], 'array'));            // already decoded
    }

    public function testDatetimeReadAndWrite(): void
    {
        $dt = Cast::read('2026-05-18 10:00:00', 'datetime');
        self::assertInstanceOf(\DateTimeImmutable::class, $dt);
        self::assertSame('2026-05-18 10:00:00', $dt->format('Y-m-d H:i:s'));

        $out = Cast::write($dt, 'datetime');
        self::assertSame('2026-05-18 10:00:00', $out);

        // Raw string in, normalised string out.
        self::assertSame('2026-05-18 10:00:00', Cast::write('2026-05-18T10:00:00', 'datetime'));
    }

    public function testDateUsesDateFormatByDefault(): void
    {
        $dt = Cast::read('2026-05-18', 'date');
        self::assertSame('2026-05-18', Cast::write($dt, 'date'));
    }

    public function testEnumReadAndWrite(): void
    {
        $s = Cast::read('active', 'enum:' . Status::class);
        self::assertSame(Status::Active, $s);
        self::assertSame('active', Cast::write(Status::Active, 'enum:' . Status::class));
        // Already-enum instance on read passes through.
        self::assertSame(Status::Banned, Cast::read(Status::Banned, 'enum:' . Status::class));
    }

    public function testUnknownCastThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Cast::read('x', 'banana');
    }

    public function testEnumWithoutClassThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Cast::read('x', 'enum:');
    }

    // ── integration: Model hydrate / save / refresh ──────────────────────

    public function testHydrateAppliesReadCasts(): void
    {
        $u = TypedUser::hydrate([
            'id'         => '7',
            'age'        => '30',
            'score'      => '95.5',
            'price'      => '19.9',
            'verified'   => '1',
            'tags'       => '["a","b"]',
            'created_at' => '2026-05-18 12:00:00',
            'status'     => 'active',
        ]);

        self::assertSame(7, $u->id);
        self::assertSame(30, $u->age);
        self::assertSame(95.5, $u->score);
        self::assertSame('19.90', $u->price);
        self::assertTrue($u->verified);
        self::assertSame(['a', 'b'], $u->tags);
        self::assertInstanceOf(\DateTimeImmutable::class, $u->created_at);
        self::assertSame(Status::Active, $u->status);
    }

    public function testNullableColumnsStayNullThroughCasts(): void
    {
        $u = TypedUser::hydrate([
            'id' => '7', 'age' => null, 'tags' => null, 'created_at' => null, 'status' => null,
        ]);
        self::assertSame(7, $u->id);
        self::assertNull($u->age);
        self::assertNull($u->tags);
        self::assertNull($u->created_at);
        self::assertNull($u->status);
    }

    public function testSaveAppliesWriteCastsToStoragePayload(): void
    {
        $storage = new ArrayStorage([]);
        TypedUser::configure($storage);

        $u = new TypedUser();
        $u->age        = 30;
        $u->verified   = true;
        $u->tags       = ['foo', 'bar'];
        $u->status     = Status::Active;
        $u->created_at = new \DateTimeImmutable('2026-05-18 12:00:00');
        $u->price      = 19.9;
        $u->save();

        // The ArrayStorage row should hold scalars produced by Cast::write.
        $stored = $storage->find($u->id);
        self::assertSame(1, $stored['verified']);
        self::assertSame('["foo","bar"]', $stored['tags']);
        self::assertSame('active', $stored['status']);
        self::assertSame('2026-05-18 12:00:00', $stored['created_at']);
        self::assertSame('19.90', $stored['price']);
    }

    public function testToArraySerialiseFlagAppliesWriteCasts(): void
    {
        $u = TypedUser::hydrate([
            'id' => '1', 'status' => 'banned', 'tags' => '["x"]',
            'created_at' => '2026-05-18 00:00:00',
        ]);

        // Default toArray() returns PHP-typed values.
        $raw = $u->toArray();
        self::assertSame(Status::Banned, $raw['status']);
        self::assertInstanceOf(\DateTimeImmutable::class, $raw['created_at']);

        // toArray(serialize: true) gives JSON-friendly scalars.
        $out = $u->toArray(serialize: true);
        self::assertSame('banned', $out['status']);
        self::assertSame('["x"]', $out['tags']);
        self::assertSame('2026-05-18 00:00:00', $out['created_at']);
    }

    public function testCastsAreOptional(): void
    {
        // A model without $types behaves exactly as before — no coercion.
        $u = NoCastUser::hydrate(['id' => '7', 'name' => 'Ada']);
        self::assertSame('7', $u->id);   // stays string
        self::assertSame('Ada', $u->name);
    }
}
