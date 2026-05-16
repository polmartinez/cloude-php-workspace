# PATTERNS.md — design patterns on `cloude/framework`

> The framework is **deliberately unopinionated** about how you arrange
> the code in your app. The primitives it ships (`Router`, `View`,
> `Model`, `JsonFile`, `Markdown`, `Mcp\Server`, …) compose into many
> shapes — Transaction Script, MVC + Repository, DDD, or anything in
> between. This file is the **decision guide** for picking one.

Three reference examples in this repo cover the typical scale points,
and the table below maps "what your app looks like" to "which one to
copy from".

## Quick decision

| Your app… | Pattern | Reference |
|---|---|---|
| ≤ 10 routes, no shared logic, throwaway script or admin tool | **Transaction Script** | [`examples/basic/`](examples/basic/) |
| 10–50 routes, mostly CRUD, modest business rules | **MVC + Repository** | [`examples/contacts/`](examples/contacts/) |
| Rich domain with invariants you want enforced everywhere | **DDD Layered** | [`examples/library/`](examples/library/) |
| Public JSON-RPC for AI tools / agents | **MCP server** | [`examples/mcp/`](examples/mcp/) (orthogonal — combine with any above) |

Pick the **smallest** pattern that holds your code. Migrating up later
is cheap; over-engineering early is the actual risk.

---

## Tier 1 · Transaction Script

> One function per route. Read input, do work, render or return.
> No persistence layer, no domain layer, no service layer.

**When to use**

- Fewer than ~10 routes
- Each route is independent, no shared state or business rules
- Throwaway scripts, internal admin pages, CLI front-ends
- Prototyping while you discover the real shape of the problem

**File layout**

```
my-app/
├── www/index.php          ← bootstrap + dispatch
├── app/
│   ├── config.php
│   └── routes.php         ← all route handlers inline (or close to it)
└── views/
    └── *.html.php
```

**Skeleton**

```php
// app/routes.php
$router->get('/users/{id:\d+}', function (array $p): void {
    $user = \Cloude\JsonFile::read(DATA_DIR . "/users/{$p['id']}.json");
    if ($user === null) {
        \Cloude\Http\Response::notFound();
        return;
    }
    \Cloude\View::render('user.html.php', ['user' => $user]);
});
```

**Reference**: [`examples/basic/`](examples/basic/) — four routes,
two views, ~50 lines of PHP across the whole app.

**Smells (time to graduate)**

- Two routes need the same data-fetch helper → extract a Repository
  (move to Tier 2)
- A validation rule shows up in three places → centralise it (Tier 2
  or 3)
- Route handlers are >50 lines each → extract Controllers

---

## Tier 2 · MVC + Repository

> Controllers receive requests, repositories hide persistence, views
> render HTML. Data passes through as plain arrays (or as
> `Cloude\Model` instances when you want types).

**When to use**

- 10–50 routes
- Mostly CRUD with simple rules ("required field", "unique slug")
- Content sites, internal tools, dashboards, member-area apps
- Most webapps land here and never need more

**File layout**

```
my-app/
├── www/index.php
├── app/
│   ├── config.php
│   ├── Routes.php                       ← maps routes to controller methods
│   ├── Controller/                      ← thin HTTP handlers
│   │   └── ContactsController.php
│   └── Repository/                      ← persistence boundary
│       └── ContactsRepo.php             ← extends Cloude\Data\JsonRepository
└── views/                               ← .html.php templates
    └── *.html.php
```

**Two flavours** of this pattern, pick by data shape:

| Variant | Data is… | Repository | Demo |
|---|---|---|---|
| **A. Document repository** | slug-keyed JSON / Markdown files (content sites) | `extends Cloude\Data\JsonRepository` (or `MarkdownRepository`) | `examples/contacts/` |
| **B. Active Record models** | relational rows or pk-keyed flat dicts | `extends Cloude\Model\Model` + a `Storage` adapter | (politica-esp uses this for sondeos, parties, etc.) |

Both share the same Controller/View shape; only persistence differs.

**Skeleton — Variant A (document)**

```php
// app/Repository/ContactsRepo.php
class ContactsRepo extends \Cloude\Data\JsonRepository {
    protected function transform(array $data, string $slug): array {
        return ['slug' => $slug] + $data;
    }
    public function search(string $q): \Cloude\Collection { /* ... */ }
}

// app/Controller/ContactsController.php
public function show(array $params): void {
    $contact = $this->repo->findOne($params['slug']);
    if ($contact === null) { /* 404 */ return; }
    \Cloude\View::render('layout.html.php', [
        'title' => $contact['name'],
        'content' => 'detail.html.php',
        'contact' => $contact,
    ]);
}
```

**Skeleton — Variant B (Active Record model)**

```php
// app/Party.php
class Party extends \Cloude\Model\Model {
    protected static string $table = 'parties';
    protected static string|array $connection = 'default';   // → storage.php
    protected static string $primaryKey = 'slug';
}

// In a controller:
$psoe = Party::find('psoe');             // typed instance
Party::findBy(['family' => 'left']);
Party::query()->where('founded', '>', 2000)->get();
```

**References**

- Variant A: [`examples/contacts/`](examples/contacts/) — form +
  JsonSchema validation + accent-insensitive search + JS-driven JSON
  endpoint.
- Variant B: [`examples/recipes/model.php`](examples/recipes/model.php)
  — Active Record patterns + the query builder.

**Smells (time to graduate)**

- Business rules require touching MULTIPLE entities atomically (e.g.
  "borrowing a book consumes a copy AND creates a loan") → Tier 3
- Validation rules live in the data and you keep re-implementing them
  in different controllers → invariants belong on entities (Tier 3)
- You want value objects (Email, Isbn, Money) to enforce their own
  validity → Tier 3

**Don't graduate just because**

- The app is "important" (importance ≠ complexity)
- Someone mentioned "clean architecture" on Twitter
- You're going to "have time" later (you won't)

---

## Tier 3 · DDD Layered

> Domain logic lives in a layer that knows nothing about the framework
> or HTTP. Application use cases orchestrate. Infrastructure adapts
> the domain's repository interfaces to disk / SQL. Presentation
> translates HTTP ↔ use cases.

**When to use**

- The domain has rules you want to enforce in ONE place, no matter
  who's calling (HTTP, CLI, MCP, queue worker)
- You can name aggregates and invariants ("a Loan can't outlive its
  Book's stock", "an Order's total is the sum of its items + tax")
- You're going to maintain this for years and want the domain layer
  to outlive the database choice / web stack
- Multiple input channels — web + CLI + AI-tools-via-MCP — all
  enforcing the same rules

**File layout**

```
my-app/
├── www/index.php
├── app/
│   ├── config.php
│   ├── Routes.php
│   ├── Domain/                          ← PURE PHP, zero framework imports
│   │   ├── Shared/DomainException.php
│   │   ├── Book/
│   │   │   ├── Isbn.php                 ← value object
│   │   │   ├── Book.php                 ← aggregate root + invariants
│   │   │   └── BookRepository.php       ← interface
│   │   ├── Loan/{LoanId,LoanPeriod,Loan,LoanRepository}.php
│   │   └── Service/BorrowingService.php ← coordinates aggregates
│   ├── Application/                     ← use cases (thin)
│   │   ├── BorrowBook.php
│   │   └── ReturnBook.php
│   ├── Infrastructure/                  ← Cloude-backed repositories
│   │   ├── JsonBookRepository.php
│   │   └── JsonLoanRepository.php
│   └── Presentation/Controller/
│       └── BookController.php
└── views/*.html.php
```

**Dependency rule**

```
Presentation  ─→  Application  ─→  Domain
                                      ↑
Infrastructure ─────────────────────┘
```

Domain is the centre. Everything else points inwards. Nothing in
`Domain/` imports `Cloude\…`; the moment it does, you've leaked.

**Reference**: [`examples/library/`](examples/library/) — book
lending app with value objects (`Isbn`, `LoanPeriod`), aggregates
(`Book`, `Loan`), domain service (`BorrowingService` enforces the
cross-aggregate "no copies left → no loan" invariant), and JSON-backed
adapters that implement domain interfaces.

**Don't use this when**

- The domain is "rows in tables" with no rules beyond CRUD
- You can't articulate a single aggregate boundary
- The team is < 3 people and the codebase is < 5k lines

DDD pays back when the domain rules are richer than the data
movement. For most webapps, that bar isn't met. Use Tier 2.

---

## MCP as an orthogonal channel

`Cloude\Mcp\Server` is **not a pattern** — it's a TRANSPORT. You can
mount an MCP endpoint on any of the three tiers, exposing whatever
public API your app already has.

```php
// Same Application use case, two input channels:
$borrow = new BorrowBook($borrowingService);

$router->post('/books/{isbn}/borrow', fn(array $p) => $borrow($p['isbn'], Input::post('member')));

$mcp->tool('borrow_book', $schema, fn(array $a) => $borrow($a['isbn'], $a['member']));
```

This is the most natural pairing with Tier 3 (DDD), because use cases
are already the unit of action. Tier 2 works too — MCP handlers call
the same Controller method or Repository.

Reference: [`examples/mcp/`](examples/mcp/) for the minimal shape;
[`examples/recipes/mcp.php`](examples/recipes/mcp.php) for the dense
version with resources, structured errors, and multiple tools.

---

## Mixing patterns in one app

You don't have to commit to ONE tier across the whole app. A site can
be **mostly Tier 2** (CRUD admin, content listings) with **one Tier 3
sub-module** (the booking engine, the billing flow). Concrete sketch:

```
app/
├── Controller/                  ← Tier 2 controllers for everything else
├── Repository/                  ← Tier 2 repositories
├── Domain/Booking/              ← Tier 3 island for the complex part
├── Application/Booking/         ← use cases that compose Booking + payment
└── Infrastructure/Payment/      ← adapter for the booking sub-module
```

Tier 3 absorbs the complexity where it lives. Tier 2 keeps the
boring parts boring. This is the pragmatic default.

---

## Anti-patterns to avoid (regardless of tier)

| Don't | Why |
|---|---|
| Reach for a DI container | Framework has none on purpose; manual wiring in `Routes.php` is the seam. |
| Service locator (global getters returning services) | Static state, untestable, hidden dependencies. |
| Anaemic models with all logic in services | Loses the point of Tier 3. If you're going to do this, stay on Tier 2 (Repository + plain arrays). |
| HTML inside controllers (echoing tags) | Always go through `Cloude\View::render()`. Templates have escaping. |
| SQL inside views | Always go through a Repository / Model. Views are dumb. |
| Inheritance for "code reuse" between entities | Aggregates are unique; reuse via composition or domain services. |
| One mega-controller for many resources | Split per resource. `BookController`, `LoanController`. Easy to navigate. |
| `?DateTime $date = null` everywhere | If a date is required, type it required. If it's optional, model the optionality (`LoanPeriod::returnedAt`). |

---

## Migration paths

**Tier 1 → Tier 2**: extract route handlers into controller methods
in a new `app/Controller/` directory; promote the inline JSON reads
into a Repository subclass. Tests on routes still pass.

**Tier 2 → Tier 3**: identify ONE aggregate with the richest rules
(usually the noun that has invariants — `Loan`, `Order`, `Invoice`).
Pull it out into `app/Domain/X/`. Define an interface for its
repository in `app/Domain/X/`. Keep the OLD Repository class but make
it implement the new interface (now in `app/Infrastructure/`). One
aggregate at a time; the rest of the app stays Tier 2.

**Tier 3 → Tier 2** (downgrade): rare but legitimate. If a once-rich
sub-module shrinks to CRUD over time, collapse Domain + Application +
Infrastructure back into Repository + Controller. Sunset cost of DDD
is real; don't keep the layering as a museum.

---

## Which pattern do the example apps demonstrate?

| Example | Pattern | Why this pattern | What it shows |
|---|---|---|---|
| [`examples/basic/`](examples/basic/) | Transaction Script | Smallest possible app | Routes + views + JSON echo |
| [`examples/contacts/`](examples/contacts/) | MVC + Repository (Variant A — document) | CRUD + JS-driven search | Controllers, Repository over `JsonRepository`, JsonSchema validation, JS `fetch()` consuming a JSON endpoint |
| [`examples/library/`](examples/library/) | DDD Layered | Cross-aggregate invariant (borrow consumes a copy) | Value objects, aggregate roots, domain service, repository interfaces, JSON-backed adapters |
| [`examples/mcp/`](examples/mcp/) | (transport, not a tier) | Public AI-tool API | `Mcp\Server`, tools with JSON-Schema-validated inputs, manifest, resources |
| [`examples/recipes/`](examples/recipes/) | (snippets, not a tier) | Reusable patterns | Sitemap, JSON-LD, MCP, CLI tasks, repos, model, mail, markdown |
