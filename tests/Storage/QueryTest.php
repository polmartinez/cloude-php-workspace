<?php

declare(strict_types=1);

namespace Cloude\Tests\Storage;

use Cloude\Storage\Query;
use PHPUnit\Framework\TestCase;

final class QueryTest extends TestCase
{
    private \PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = new \PDO('sqlite::memory:');
        $this->pdo->exec('CREATE TABLE users (
            id     INTEGER PRIMARY KEY AUTOINCREMENT,
            email  TEXT,
            name   TEXT,
            age    INTEGER,
            role   TEXT,
            active INTEGER DEFAULT 1
        )');

        $rows = [
            ['ada@x',   'Ada',   30, 'admin',  1],
            ['alan@x',  'Alan',  18, 'user',   1],
            ['grace@x', 'Grace', 65, 'admin',  1],
            ['linus@x', 'Linus', 12, 'user',   0],   // inactive minor
            ['none@x',  'None',  null, 'user', 1],   // age null
        ];
        $stmt = $this->pdo->prepare('INSERT INTO users (email, name, age, role, active) VALUES (?, ?, ?, ?, ?)');
        foreach ($rows as $r) {
            $stmt->execute($r);
        }
    }

    private function q(): Query
    {
        return new Query($this->pdo, 'users');
    }

    // ── SELECT / WHERE basics ─────────────────────────────────────────────

    public function testGetReturnsAllRowsWithoutWhere(): void
    {
        self::assertCount(5, $this->q()->get());
    }

    public function testWhereEqualityShorthand(): void
    {
        $rows = $this->q()->where('role', 'admin')->get();
        self::assertCount(2, $rows);
        self::assertSame(['Ada', 'Grace'], array_column($rows, 'name'));
    }

    public function testWhereExplicitOperator(): void
    {
        $rows = $this->q()->where('age', '>', 18)->get();
        $names = array_column($rows, 'name');
        sort($names);
        self::assertSame(['Ada', 'Grace'], $names);
    }

    public function testWhereChainingIsAndByDefault(): void
    {
        $rows = $this->q()
            ->where('role', 'admin')
            ->where('active', 1)
            ->get();
        self::assertCount(2, $rows);
    }

    public function testOrWhere(): void
    {
        $rows = $this->q()
            ->where('role', 'admin')
            ->orWhere('age', '<', 18)
            ->orderBy('name')
            ->get();
        $names = array_column($rows, 'name');
        self::assertSame(['Ada', 'Grace', 'Linus'], $names);
    }

    public function testWhereInOperator(): void
    {
        $rows = $this->q()->whereIn('name', ['Ada', 'Linus', 'Bogus'])->orderBy('name')->get();
        self::assertCount(2, $rows);
    }

    public function testWhereInEmptyMatchesNothing(): void
    {
        self::assertCount(0, $this->q()->whereIn('id', [])->get());
    }

    public function testWhereNotInEmptyMatchesEverything(): void
    {
        self::assertCount(5, $this->q()->whereNotIn('id', [])->get());
    }

    public function testWhereNullAndNotNull(): void
    {
        self::assertCount(1, $this->q()->whereNull('age')->get());
        self::assertCount(4, $this->q()->whereNotNull('age')->get());
    }

    public function testWhereEqualityWithNullAutoTranslatesToIsNull(): void
    {
        $rows = $this->q()->where('age', '=', null)->get();
        self::assertCount(1, $rows);
        self::assertSame('None', $rows[0]['name']);
    }

    public function testWhereNotEqualWithNullAutoTranslatesToIsNotNull(): void
    {
        $rows = $this->q()->where('age', '!=', null)->get();
        self::assertCount(4, $rows);
    }

    public function testWhereBetween(): void
    {
        $rows = $this->q()->whereBetween('age', 18, 30)->orderBy('age')->get();
        self::assertSame(['Alan', 'Ada'], array_column($rows, 'name'));
    }

    public function testWhereLike(): void
    {
        $rows = $this->q()->where('email', 'LIKE', '%@x')->get();
        self::assertCount(5, $rows);
    }

    public function testUnsupportedOperatorThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->q()->where('age', 'EXISTS', 1);
    }

    // ── ORDER BY / LIMIT / OFFSET ─────────────────────────────────────────

    public function testOrderByAscending(): void
    {
        $names = array_column($this->q()->orderBy('name')->get(), 'name');
        self::assertSame(['Ada', 'Alan', 'Grace', 'Linus', 'None'], $names);
    }

    public function testOrderByDescending(): void
    {
        $names = array_column($this->q()->orderBy('name', 'DESC')->get(), 'name');
        self::assertSame(['None', 'Linus', 'Grace', 'Alan', 'Ada'], $names);
    }

    public function testOrderByMultipleColumns(): void
    {
        $rows = $this->q()
            ->orderBy('role', 'ASC')
            ->orderBy('name', 'DESC')
            ->get();
        $names = array_column($rows, 'name');
        // admin, name DESC: Grace, Ada — then user, name DESC: None, Linus, Alan
        self::assertSame(['Grace', 'Ada', 'None', 'Linus', 'Alan'], $names);
    }

    public function testLimitAndOffset(): void
    {
        $rows = $this->q()->orderBy('id')->limit(2)->offset(1)->get();
        self::assertSame(['Alan', 'Grace'], array_column($rows, 'name'));
    }

    // ── result helpers ────────────────────────────────────────────────────

    public function testFirstReturnsRow(): void
    {
        $row = $this->q()->where('name', 'Ada')->first();
        self::assertNotNull($row);
        self::assertSame('admin', $row['role']);
    }

    public function testFirstReturnsNullWhenNoMatch(): void
    {
        self::assertNull($this->q()->where('name', 'Nobody')->first());
    }

    public function testCount(): void
    {
        self::assertSame(5, $this->q()->count());
        self::assertSame(2, $this->q()->where('role', 'admin')->count());
    }

    public function testValueReturnsSingleScalar(): void
    {
        $email = $this->q()->where('name', 'Ada')->value('email');
        self::assertSame('ada@x', $email);
    }

    public function testPluckList(): void
    {
        $names = $this->q()->orderBy('name')->pluck('name');
        self::assertSame(['Ada', 'Alan', 'Grace', 'Linus', 'None'], $names);
    }

    public function testPluckKeyed(): void
    {
        $byId = $this->q()->orderBy('id')->limit(2)->pluck('name', 'id');
        self::assertSame([1 => 'Ada', 2 => 'Alan'], $byId);
    }

    // ── INSERT / UPDATE / DELETE ──────────────────────────────────────────

    public function testInsertReturnsAutoIncrementId(): void
    {
        $id = $this->q()->insert(['email' => 'new@x', 'name' => 'New', 'age' => 20, 'role' => 'user']);
        self::assertSame(6, $id);
        self::assertSame(6, $this->q()->count());
    }

    public function testUpdateAffectsMatchingRows(): void
    {
        $affected = $this->q()->where('age', '<', 18)->update(['active' => 0]);
        self::assertSame(1, $affected);
        $linus = $this->q()->where('name', 'Linus')->first();
        self::assertSame(0, $linus['active']);
    }

    public function testUpdateWithoutWhereTouchesEverything(): void
    {
        $affected = $this->q()->update(['active' => 0]);
        self::assertSame(5, $affected);
        self::assertSame(0, $this->q()->where('active', 1)->count());
    }

    public function testDeleteAffectsMatchingRows(): void
    {
        $affected = $this->q()->where('active', 0)->delete();
        self::assertSame(1, $affected);
        self::assertSame(4, $this->q()->count());
    }

    public function testDeleteWithoutWhereEmptiesTable(): void
    {
        $affected = $this->q()->delete();
        self::assertSame(5, $affected);
        self::assertSame(0, $this->q()->count());
    }

    // ── debug ────────────────────────────────────────────────────────────

    public function testCompileInlinesNumericValues(): void
    {
        $sql = $this->q()->where('age', '>', 18)->orderBy('name')->limit(5)->compile();
        self::assertSame(
            'SELECT * FROM `users` WHERE `age` > 18 ORDER BY `name` ASC LIMIT 5',
            $sql,
        );
    }

    public function testCompileEscapesStringLiterals(): void
    {
        $sql = $this->q()->where('name', "O'Brien")->compile();
        self::assertSame(
            "SELECT * FROM `users` WHERE `name` = 'O''Brien'",
            $sql,
        );
    }

    public function testCompileRendersNullAndBool(): void
    {
        $sql = $this->q()->where('age', '=', null)->compile();
        self::assertSame('SELECT * FROM `users` WHERE `age` IS NULL', $sql);

        $sql2 = $this->q()->where('active', true)->compile();
        self::assertSame('SELECT * FROM `users` WHERE `active` = 1', $sql2);
    }

    public function testCompileInWithMultipleValues(): void
    {
        $sql = $this->q()->whereIn('id', [1, 2, 3])->compile();
        self::assertSame(
            'SELECT * FROM `users` WHERE `id` IN (1, 2, 3)',
            $sql,
        );
    }

    // ── identifier safety ────────────────────────────────────────────────

    public function testRejectsBadColumnIdentifier(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->q()->where('name; DROP TABLE users', 'x')->get();
    }

    public function testRejectsBadTableIdentifier(): void
    {
        $bad = new Query($this->pdo, 'users; DROP TABLE users');
        $this->expectException(\InvalidArgumentException::class);
        $bad->get();
    }

    // ── nested where groups ───────────────────────────────────────────────

    public function testWhereGroupCombinesPredicatesWithParens(): void
    {
        // active = 1 AND (role = 'admin' OR age <= 18) → Ada, Grace, Alan
        $rows = $this->q()
            ->where('active', 1)
            ->whereGroup(static fn ($g) =>
                $g->where('role', 'admin')->orWhere('age', '<=', 18))
            ->orderBy('name')
            ->get();
        self::assertSame(['Ada', 'Alan', 'Grace'], array_column($rows, 'name'));
    }

    public function testOrWhereGroup(): void
    {
        // role = 'admin' OR (age >= 18 AND active = 1) → Ada, Alan, Grace
        $rows = $this->q()
            ->where('role', 'admin')
            ->orWhereGroup(static fn ($g) =>
                $g->where('age', '>=', 18)->where('active', 1))
            ->orderBy('name')
            ->get();
        self::assertContains('Ada', array_column($rows, 'name'));
        self::assertContains('Alan', array_column($rows, 'name'));
        self::assertContains('Grace', array_column($rows, 'name'));
    }

    public function testWhereGroupCompilesWithParentheses(): void
    {
        $sql = $this->q()
            ->where('active', 1)
            ->whereGroup(static fn ($g) => $g->where('role', 'admin')->orWhere('age', '<=', 18))
            ->compile();
        self::assertStringContainsString('(`role` = \'admin\' OR `age` <= 18)', $sql);
    }

    public function testEmptyWhereGroupIsNoOp(): void
    {
        $rows = $this->q()->where('active', 1)->whereGroup(static fn ($g) => $g)->get();
        self::assertCount(4, $rows);  // same as plain where('active', 1)
    }

    // ── joins ─────────────────────────────────────────────────────────────

    private function seedOrders(): void
    {
        $this->pdo->exec('CREATE TABLE orders (
            id      INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER,
            total   INTEGER,
            status  TEXT
        )');
        $stmt = $this->pdo->prepare('INSERT INTO orders (user_id, total, status) VALUES (?, ?, ?)');
        $stmt->execute([1, 100, 'paid']);    // Ada
        $stmt->execute([1,  50, 'pending']); // Ada
        $stmt->execute([3, 200, 'paid']);    // Grace
    }

    public function testInnerJoinUsesDottedColumns(): void
    {
        $this->seedOrders();
        $rows = $this->q()
            ->select('users.name', 'orders.total')
            ->join('orders', 'orders.user_id', '=', 'users.id')
            ->where('orders.status', 'paid')
            ->orderBy('users.name')
            ->get();
        self::assertSame([
            ['name' => 'Ada',   'total' => 100],
            ['name' => 'Grace', 'total' => 200],
        ], $rows);
    }

    public function testLeftJoinIncludesUsersWithoutOrders(): void
    {
        $this->seedOrders();
        $rows = $this->q()
            ->select('users.name', 'orders.total')
            ->leftJoin('orders', 'orders.user_id', '=', 'users.id')
            ->orderBy('users.name')
            ->get();
        self::assertCount(6, $rows);   // 3 orders + 3 users w/ no orders
    }

    public function testJoinWithTableRefAlias(): void
    {
        $this->seedOrders();
        $u = new \Cloude\Storage\TableRef('users', 'u');
        $o = new \Cloude\Storage\TableRef('orders', 'o');

        $rows = (new Query($this->pdo, $u))
            ->select($u->field('name'), $o->field('total'))
            ->join($o, $o->field('user_id'), '=', $u->field('id'))
            ->where($o->field('status'), 'paid')
            ->orderBy($u->field('name'))
            ->get();
        self::assertSame([
            ['name' => 'Ada',   'total' => 100],
            ['name' => 'Grace', 'total' => 200],
        ], $rows);
    }

    public function testJoinCompileQuotesAliasedColumns(): void
    {
        $u = new \Cloude\Storage\TableRef('users', 'u');
        $o = new \Cloude\Storage\TableRef('orders', 'o');
        $sql = (new Query($this->pdo, $u))
            ->select($u->field('email'))
            ->join($o, $o->field('user_id'), '=', $u->field('id'))
            ->compile();
        self::assertStringContainsString('FROM `users` AS `u`', $sql);
        self::assertStringContainsString('JOIN `orders` AS `o` ON `o`.`user_id` = `u`.`id`', $sql);
        self::assertStringContainsString('`u`.`email`', $sql);
    }

    public function testCrossJoinHasNoOnClause(): void
    {
        $this->seedOrders();
        $sql = $this->q()->crossJoin('orders')->compile();
        self::assertStringContainsString('CROSS JOIN `orders`', $sql);
        self::assertStringNotContainsString(' ON ', $sql);
    }

    public function testJoinRejectsInvalidOperator(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->q()->join('orders', 'orders.user_id', 'INVALID', 'users.id');
    }

    public function testFromReanchorsAlias(): void
    {
        $u = new \Cloude\Storage\TableRef('users', 'u');
        $sql = $this->q()->from($u)->where($u->field('id'), 1)->compile();
        self::assertStringContainsString('FROM `users` AS `u`', $sql);
        self::assertStringContainsString('`u`.`id`', $sql);
    }

    // ── pluck/value with qualified names ─────────────────────────────────

    public function testPluckHandlesQualifiedColumn(): void
    {
        $names = $this->q()->orderBy('id')->pluck('users.name');
        self::assertSame(['Ada', 'Alan', 'Grace', 'Linus', 'None'], $names);
    }
}
