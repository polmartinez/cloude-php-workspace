# Contacts demo

> **Pattern**: MVC + Repository (document variant) — Controllers +
> a Repository that extends `Cloude\Data\JsonRepository`, data as
> plain arrays. See [`../../PATTERNS.md`](../../PATTERNS.md) for when
> this is the right fit and when to move to DDD instead.

A tiny address-book built on `cloude/framework`. Single front controller,
file-backed JSON storage, server-side validation, and a JSON endpoint
consumed by a small `fetch()` script for live search.

## Run

From the repository root:

```bash
php -S localhost:8001 -t examples/contacts/www
```

Open <http://localhost:8001>. No `composer install` needed.

For Docker / `docker compose`, see [`../../DEPLOYMENT.md`](../../DEPLOYMENT.md).

## What you'll see

- **`/`** — full list rendered server-side, plus a search box. Typing in
  the box hits `GET /api/search?q=…` (200 ms debounced) and re-renders
  the list with the JSON response.
- **`/new`** — form. Empty / too-short input is rejected on the server
  via `Cloude\JsonSchema`, and the page re-renders with the error
  list and the previous values restored.
- **`/contact/{slug}`** — detail page. Slugs are generated from the
  name with `Cloude\Str::slug` and de-duplicated with a numeric suffix.
- **Delete** — a `POST /contact/{slug}/delete` button on the detail
  page, followed by a 303 redirect home.

## Layout

```
contacts/
├── www/
│   ├── index.php              ← front controller
│   └── assets/
│       ├── app.css
│       └── app.js             ← debounced fetch() to /api/search
├── app/
│   ├── config.php             ← BASE_URL, DATA_DIR, DEBUG
│   ├── Routes.php             ← route table
│   ├── Controller/
│   │   └── ContactsController.php
│   └── Repository/
│       └── ContactsRepo.php   ← extends Cloude\Data\JsonRepository
├── views/
│   ├── layout.html.php             ← shared chrome
│   ├── home.html.php               ← list + search
│   ├── new.html.php                ← form
│   ├── detail.html.php             ← single contact
│   └── 404.html.php
└── data/
    └── contacts/
        ├── ada-lovelace.json
        ├── alan-turing.json
        └── grace-hopper.json
```

## Routes

| Method | Path                          | Handler                                     |
|--------|-------------------------------|---------------------------------------------|
| GET    | `/`                           | `ContactsController::home`                  |
| GET    | `/api/search?q=…`             | `ContactsController::apiSearch` (JSON)      |
| GET    | `/new`                        | `ContactsController::newForm`               |
| POST   | `/new`                        | `ContactsController::create`                |
| GET    | `/contact/{slug}`             | `ContactsController::show`                  |
| POST   | `/contact/{slug}/delete`      | `ContactsController::delete`                |

## Try it from the shell

```bash
# Search
curl -s 'http://localhost:8001/api/search?q=ada' | jq

# Create
curl -i -X POST http://localhost:8001/new \
     -d 'name=Linus Torvalds' \
     -d 'email=linus@kernel.org'

# Delete
curl -i -X POST http://localhost:8001/contact/linus-torvalds/delete
```

## Where each framework piece shows up

| Piece                       | Used in                                          |
|-----------------------------|--------------------------------------------------|
| `Cloude\Bootstrap`          | `www/index.php` — `serveStaticIfExists` + `run`  |
| `Cloude\Router` + `{slug:regex}` | `app/Routes.php`                            |
| `Cloude\Input::post / get`  | `ContactsController::create / apiSearch`         |
| `Cloude\JsonSchema`         | `ContactsController::create` (server validation) |
| `Cloude\Http\Response::json / redirect` | `apiSearch`, `create`, `delete`      |
| `Cloude\Data\JsonRepository` | `app/Repository/ContactsRepo.php` (extends)     |
| `Cloude\Collection`         | `ContactsRepo::search` returns one               |
| `Cloude\Str::slug / ascii`  | `ContactsRepo::uniqueSlug / search`              |
| `Cloude\View::render / e()` | every template under `views/`                    |
