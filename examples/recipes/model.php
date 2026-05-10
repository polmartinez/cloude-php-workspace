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

// ── Richer queries: drop to PDO ──────────────────────────────────────────────
//
// `findBy` is equality-only by design. For LIKE / IN / ranges / aggregations,
// reach the connection and write SQL. This is the framework's "no DSL" line.

$recent = User::storage()->pdo()
    ->query('SELECT id, name FROM users WHERE created_at > date("now","-7 days") ORDER BY created_at DESC LIMIT 20')
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
