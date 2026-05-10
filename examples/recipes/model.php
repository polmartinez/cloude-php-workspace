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

use Cloude\Model\Model;
use Cloude\Model\Storage\ArrayStorage;
use Cloude\Model\Storage\PdoStorage;

// ── Define an entity ─────────────────────────────────────────────────────────

class User extends Model
{
    protected static string $table       = 'users';
    protected static string $primaryKey  = 'id';
    /** @var list<string> Whitelist guards against mass-assignment from form input. */
    protected static array  $properties  = ['id', 'email', 'name', 'active', 'created_at'];

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

// ── Wire it to MySQL (production) ────────────────────────────────────────────

$pdo = new PDO(
    'mysql:host=localhost;dbname=app;charset=utf8mb4',
    $user ?? 'app',
    $pass ?? 'secret',
    [
        PDO::ATTR_ERRMODE          => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ],
);

User::configure(new PdoStorage($pdo, 'users'));

// SQLite, Postgres or any PDO driver: same call, different DSN.
//   new PdoStorage(new PDO('sqlite:/path/to/app.sqlite'), 'users');
//   new PdoStorage(new PDO('pgsql:host=...;dbname=app'), 'users');   ← auto-detects double-quote identifiers

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

// Debug what the builder is about to issue:
echo User::query()->where('age', '>', 18)->orderBy('name')->limit(5)->toSql();
// → SELECT * FROM `users` WHERE `age` > ? ORDER BY `name` ASC LIMIT 5

// ── Beyond the builder: drop to PDO ──────────────────────────────────────────
//
// The builder is intentionally narrow — no joins, no unions, no subqueries.
// For anything outside that scope, reach the raw connection.

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
