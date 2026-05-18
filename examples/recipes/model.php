<?php

declare(strict_types=1);

/**
 * Recipe: thin Active Record over MySQL / Postgres / SQLite via Cloude\Model.
 *
 * The framework's optional persistence layer for relational data. Wire a
 * Storage adapter once at boot, declare your subclass, get CRUD by primary
 * key + findBy() by equality. For richer queries drop down to the underlying
 * connection (`User::storage()->pdo()`) and write SQL.
 *
 * Mental model:
 *   - Cloude\Data\*       → file-per-document, slug-keyed (Markdown, JSON config)
 *   - Cloude\Model\*      → typed entity per row, primary-key-keyed (relational)
 *
 * They coexist; pick whichever fits each concern in your app.
 */

use Cloude\Config;
use Cloude\Model\Model;
use Cloude\Model\Storage\ArrayStorage;

// ── Bootstrap once: just point Config at your config dir ─────────────────────
//
// `app/config/storage.php` declares every connection (PDO, JSON, ...) once
// (see examples/recipes/config.php). Models auto-resolve to the right
// adapter from there.

Config::configure(__DIR__ . '/../app/config');

// ── Define an entity ─────────────────────────────────────────────────────────

enum UserStatus: string
{
    case Active  = 'active';
    case Pending = 'pending';
}

class User extends Model
{
    protected static string $table       = 'users';
    protected static string $connection  = 'default';     // → reads storage.default
    protected static string $primaryKey  = 'id';
    /** @var list<string> Whitelist guards against mass-assignment from form input. */
    protected static array  $properties  = ['id', 'email', 'name', 'active', 'created_at', 'tags', 'status'];

    /**
     * Optional. Read-side ('users.created_at' string → DateTimeImmutable, etc.)
     * and write-side coercion happen automatically; null always passes through.
     * See `Cloude\Model\Cast` for the full type catalogue.
     *
     * @var array<string,string>
     */
    protected static array $casts = [
        'id'         => 'int',
        'active'     => 'bool',
        'tags'       => 'json',
        'created_at' => 'datetime',
        'status'     => 'enum:' . UserStatus::class,
    ];

    /**
     * Override `beforeSave()` for hooks. No event bus, no observer registry —
     * just a method.
     */
    protected function beforeSave(): void
    {
        if (!$this->isPersisted()) {
            $this->created_at ??= gmdate('c');
        }
    }
}

// That's it. The first call to User::find() / User::query() / etc. asks
// Cloude\Storage\Factory to build the right adapter from the 'default'
// connection's `driver` key in storage.php — pdo, json or array, all
// behind the same Model API.
//
// For tests or one-off scripts you can still wire manually:
//
//   User::configure(new ArrayStorage([
//       ['id' => 1, 'email' => 'ada@example.com', 'name' => 'Ada'],
//   ]));
//
// or:
//
//   $pdo = new PDO('mysql:host=localhost;dbname=app', $u, $p);
//   User::configure(new PdoStorage($pdo, 'users'));
//
// Explicit configure() always wins over the config-driven auto-resolve.

// ── CRUD ─────────────────────────────────────────────────────────────────────

// CREATE
$ada = User::create([
    'email'  => 'ada@example.com',
    'name'   => 'Ada Lovelace',
    'active' => 1,
]);

// READ
$same = User::find($ada->id);

// UPDATE
$same->name = 'Augusta Ada Byron';
$same->save();

// DELETE
$same->delete();

// ── Queries (equality + ordering + paging) ───────────────────────────────────

$activeUsers = User::findBy(
    criteria: ['active' => 1],
    limit:    10,
    orderBy:  ['created_at' => 'DESC'],
);

$activeCount = User::count(['active' => 1]);
$total       = User::count();

// ── Richer queries: the Cloude\Db\Query builder ──────────────────────────────
//
// `findBy` is equality-only by design. For `>`, `<`, `LIKE`, `IN`, `BETWEEN`,
// `IS NULL`, multi-column `ORDER BY`, etc., reach for `User::query()` —
// shortcut for `User::storage()->query()`, returning a fresh `Cloude\Db\Query`
// bound to the model's table.

$rows = User::query()
    ->where('age', '>', 18)
    ->whereIn('role', ['admin', 'editor'])
    ->whereNotNull('email')
    ->orderBy('created_at', 'DESC')
    ->limit(20)
    ->get();                       // list<array> — raw rows

// Lift rows back into Model instances (User::hydrate is public for this):
$users = array_map(static fn (array $r) => User::hydrate($r), $rows);

// More builder shapes:
$count    = User::query()->where('active', 1)->count();
$first    = User::query()->where('email', 'a@b.com')->first();
$emails   = User::query()->where('active', 1)->pluck('email');           // list<string>
$nameById = User::query()->select('id', 'name')->pluck('name', 'id');    // [id => name]

// Mutations (apply current WHERE):
$updated  = User::query()->where('active', 0)->update(['role' => 'guest']);   // affected rows
$deleted  = User::query()->where('created_at', '<', '2020-01-01')->delete();  // affected rows

// Debug — see the SQL with values inlined, ready to paste into a SQL client.
// **Debug only**: never feed this back through PDO::exec() / query(); use
// the builder for actual execution so prepared statements stay in play.
echo User::query()->where('age', '>', 18)->orderBy('name')->limit(5)->compile();
// → SELECT * FROM `users` WHERE `age` > 18 ORDER BY `name` ASC LIMIT 5

// ── Nested AND/OR predicates ────────────────────────────────────────────────
//
// When a filter mixes AND with OR you need explicit parentheses. Pass a
// closure to whereGroup() / orWhereGroup(); the inner query's WHEREs are
// spliced into the parent's clause list as one parenthesised block.

$admins_or_juniors = User::query()
    ->where('active', 1)
    ->whereGroup(fn ($g) =>
        $g->where('role', 'admin')->orWhere('age', '<=', 18))
    ->get();
// → WHERE active = 1 AND (role = 'admin' OR age <= 18)

// ── JOINs (and aliases) ──────────────────────────────────────────────────────
//
// The builder ships INNER / LEFT / RIGHT / CROSS joins. Columns may be
// qualified strings ('users.id') — the builder quotes them automatically.

$paid = User::query()
    ->select('users.name', 'orders.total')
    ->join('orders', 'orders.user_id', '=', 'users.id')
    ->where('orders.status', 'paid')
    ->orderBy('users.name')
    ->get();

// For aliased joins, build TableRefs with Model::as() and re-anchor FROM:

class Order extends Model
{
    protected static string $table       = 'orders';
    protected static string $connection  = 'default';
}

$u = User::as('u');
$o = Order::as('o');

$rows = User::query()->from($u)
    ->select($u->field('name'), $o->field('total'))
    ->leftJoin($o, $o->field('user_id'), '=', $u->field('id'))
    ->where($o->field('status'), 'paid')
    ->orderBy($u->field('name'))
    ->get();

// User::table() / User::field('email') return plain dotted strings, so they
// also work outside the builder — handy for raw SQL with no string literals:

$sql = 'SELECT * FROM ' . User::table() . ' WHERE ' . User::field('active') . ' = 1';

// Column aliases. The preferred form is a [column, alias] tuple — both
// Model::alias() and TableRef::alias() emit exactly that shape, so you
// can drop them into select() as-is.

$rows = User::query()
    ->select('id', ['name', 'user_name'])              // raw tuple
    ->select('id', User::alias('name', 'user_name'))   // model-qualified tuple
    ->get();

$u = User::as('u');
$rows = User::query()->from($u)
    ->select($u->alias('name', 'who'), $u->alias('email', 'addr'))
    ->get();

// The legacy 'name AS user_name' string form still works — useful when
// you're hand-writing or migrating older code.

// ── Beyond the builder: drop to PDO ──────────────────────────────────────────
//
// The builder covers SELECT/INSERT/UPDATE/DELETE + WHERE + JOIN + ORDER/LIMIT.
// For UNIONs, subqueries, window functions, CTEs or aggregations beyond
// count(), reach the raw connection.

$top = User::storage()->pdo()
    ->query('SELECT u.id, u.name, COUNT(o.id) AS n
             FROM users u LEFT JOIN orders o ON o.user_id = u.id
             GROUP BY u.id ORDER BY n DESC LIMIT 5')
    ->fetchAll(PDO::FETCH_ASSOC);

// ── Same model, swapped adapter — useful in tests ────────────────────────────
//
// ArrayStorage: in-memory, no I/O. Configure once in your test's setUp() and
// every method on User behaves identically — you're testing your model logic,
// not your DB.

User::configure(new ArrayStorage([
    ['id' => 1, 'email' => 'ada@example.com', 'name' => 'Ada', 'active' => 1],
]));
$u = User::find(1);              // same API
$u->name = 'Ada Lovelace';
$u->save();                      // mutates the in-memory store
