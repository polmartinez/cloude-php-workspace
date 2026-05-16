# Library demo — DDD layering on `cloude/framework`

> **Pattern**: DDD Layered — value objects, aggregate roots, a domain
> service that owns a cross-aggregate invariant (borrow consumes a
> copy AND creates a loan, atomically from the domain's view). See
> [`../../PATTERNS.md`](../../PATTERNS.md) for when this complexity is
> warranted (and when it isn't).

A book-lending mini-app structured as a four-layer DDD project:

```
Presentation  ──────────────►  HTTP routes, controllers, views
      │                        (Cloude\Router, Cloude\View, ...)
      ▼
Application   ──────────────►  Use cases / commands / queries
      │                        (RegisterBook, BorrowBook, ReturnBook)
      ▼
Domain        ──────────────►  Entities, value objects, aggregates,
      │                        domain services, repository interfaces
      │                        (pure PHP — zero framework imports)
      ▲
Infrastructure ─────────────►  Concrete repository adapters
                               (JsonBookRepository on top of Cloude\JsonFile)
```

Dependencies point **inwards**: Infrastructure and Presentation know
about Domain; Domain doesn't know about either.

## Run

From the repository root:

```bash
php -S localhost:8002 -t examples/library/www
```

Open <http://localhost:8002>. No `composer install` needed.

For Docker / `docker compose`, see [`../../DEPLOYMENT.md`](../../DEPLOYMENT.md).

## What you'll see

- **`/`** — catalogue, sorted by title. Each book shows availability
  (`X of Y available`); available books expose an inline borrow form.
- **`/loans`** — every active loan with the member's name, due date,
  and an "overdue" badge when applicable. Each row has a "Mark returned"
  button.
- **`/books/new`** — registration form. Invalid input
  (bad ISBN, blank title, copies < 1) raises a `DomainException` from
  the model and re-renders the form with the message.
- **`/api/books`** — JSON projection of the catalogue.

## Layout

```
library/
├── www/
│   ├── index.php                 ← front controller, autoload, manual wiring
│   └── assets/app.css
├── app/
│   ├── config.php                ← BASE_URL, DATA_DIR, DEBUG
│   ├── Routes.php                ← route table + manual object-graph wiring
│   ├── Domain/                   ← PURE PHP — no Cloude imports allowed here
│   │   ├── Shared/DomainException.php
│   │   ├── Book/
│   │   │   ├── Isbn.php          ← value object (self-validating)
│   │   │   ├── Book.php          ← aggregate root + invariants
│   │   │   └── BookRepository.php← interface
│   │   ├── Loan/
│   │   │   ├── LoanId.php        ← value object (UUID)
│   │   │   ├── LoanPeriod.php    ← value object (borrowed/due/returned)
│   │   │   ├── Loan.php          ← aggregate root
│   │   │   └── LoanRepository.php
│   │   └── Service/
│   │       └── BorrowingService.php   ← coordinates Book + Loan
│   ├── Application/
│   │   ├── RegisterBook.php      ← use case (callable via __invoke)
│   │   ├── BorrowBook.php
│   │   └── ReturnBook.php
│   ├── Infrastructure/
│   │   ├── JsonBookRepository.php← Cloude-backed adapter
│   │   └── JsonLoanRepository.php
│   └── Presentation/
│       └── Controller/
│           ├── BookController.php
│           └── LoanController.php
├── views/
│   ├── layout.html.php
│   ├── books.html.php
│   ├── book_form.html.php
│   ├── loans.html.php
│   └── 404.html.php
└── data/
    ├── books/{isbn}.json         ← seeded with three classics
    └── loans/{uuid}.json         ← created at runtime
```

## Routes

| Method | Path                          | Layer touched first         |
|--------|-------------------------------|-----------------------------|
| GET    | `/`                           | `BookController::index`     |
| GET    | `/api/books`                  | `BookController::apiList`   |
| GET    | `/books/new`                  | `BookController::newForm`   |
| POST   | `/books`                      | `BookController::create`    |
| POST   | `/books/{isbn}/borrow`        | `BookController::borrow`    |
| GET    | `/loans`                      | `LoanController::index`     |
| POST   | `/loans/{id}/return`          | `LoanController::return`    |

## Try the invariants

```bash
# List the catalogue (JSON)
curl -s http://localhost:8002/api/books | jq '.books[] | {title, copies_available}'

# Borrow Clean Code (3 copies). Repeat until empty:
for i in 1 2 3 4; do
  curl -s -i -X POST http://localhost:8002/books/9780132350884/borrow \
       --data "member=Reader $i" | grep -E '^(HTTP|Location)'
done
# → the 4th request redirects to /?error=No%20copies%20available...
```

That last redirect is the domain invariant doing its job:
`Book::borrowOne()` throws `DomainException` when `copiesAvailable === 0`.
Nothing in the application or presentation layer needs to know the rule.

## Why DDD with this framework?

Cloude is intentionally minimal — no DI container, no ORM, no service
locator. The DDD layers fit naturally:

- **Domain** is just PHP classes; the framework is invisible.
- **Application** depends on domain interfaces.
- **Infrastructure** wraps `Cloude\JsonFile` to satisfy those
  interfaces. Swap to MySQL / Postgres / Redis later by writing one new
  class per repository — no domain code changes.
- **Presentation** uses `Cloude\Router`, `Cloude\View`,
  `Cloude\Http\Response` and `Cloude\Input` exactly as the simpler
  examples do.

The wiring lives in [`app/Routes.php`](app/Routes.php) — explicit,
boring, readable. That's what you trade for "no container".
