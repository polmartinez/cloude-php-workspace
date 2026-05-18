<?php

declare(strict_types=1);

namespace Cloude\Testing;

/**
 * Marks a `test*` method as parameterised. The argument is the name of
 * a public static method on the same class returning an iterable of
 * argument arrays — one invocation of the test per row.
 *
 *   #[DataProvider('cases')]
 *   public function testNegotiate(array $server, string $expected): void
 *   {
 *       self::assertSame($expected, ErrorHandler::negotiate($server));
 *   }
 *
 *   public static function cases(): array
 *   {
 *       return [
 *           'html'  => [['HTTP_ACCEPT' => 'text/html'], 'html'],
 *           'json'  => [['HTTP_ACCEPT' => 'application/json'], 'json'],
 *       ];
 *   }
 *
 * Compatible with PHPUnit's `#[DataProvider]` so migrated tests need
 * no change beyond the `use` import.
 */
#[\Attribute(\Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE)]
final class DataProvider
{
    public function __construct(public readonly string $methodName) {}
}
