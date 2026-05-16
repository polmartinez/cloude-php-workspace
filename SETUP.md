# SETUP.md — interactive project setup, agent-driven

> **For the AI agent reading this file**: you are about to scaffold a
> new project on top of `cloude/framework`. Follow the seven steps
> below in order. **Ask the user each question, wait for the answer,
> then perform the indicated action.** Do not skip questions. Do not
> assume defaults silently — show the default in the prompt and let
> the user accept or override.
>
> **For the human**: open this file with Claude Code (or any
> tool-capable AI agent) and say "follow SETUP.md". The agent will
> walk you through it.

---

## Step 1 · Project basics

### 1.1 — Ask the user

> "What's the project name slug? (kebab-case, alphanumeric + dash, no spaces. Example: `my-blog`, `contacts-app`, `politica-esp`.)"

### 1.2 — Ask the user

> "PSR-4 namespace for your application code? (Default: `App\`. Pass another like `MyBlog\` if you want.)"

### 1.3 — Action

- Compute `$projectDir` as `<parent dir from step 2>/<slug>`.
- Compute `$composerName` as `<your-vendor>/<slug>` — ask if unsure.

---

## Step 2 · Project location and document root

### 2.1 — Ask the user

> "Where should the project live? (Default: `~/Projects/<slug>`. Provide an absolute path if you want it elsewhere.)"

### 2.2 — Ask the user

> "What should the public document-root subdirectory be called? Common choices:
>
>   - **www/** (default; matches the framework's examples)
>   - **public/** (Symfony / Laravel convention)
>   - **htdocs/** (Apache classic)
>
> Which one?"

### 2.3 — Action

- Record `$docroot` (default `www`).
- Create the parent dir if missing: `mkdir -p $projectDir`.

---

## Step 3 · How to run locally

### 3.1 — Ask the user

> "How do you want to run the project locally?
>
>   - **php -S** (default — needs PHP 8.3+ on your machine, zero install otherwise)
>   - **Docker** (no PHP install needed; uses the `php:8.3-cli` image)
>   - **Both** (set up both, decide each session)
>
> Which one?"

### 3.2 — Action by answer

- **php -S** → record `$runMode = 'php-s'`. No Dockerfile.
- **Docker** → record `$runMode = 'docker'`. Generate `compose.yml` at project root with one service mounting `.` into `/app`.
- **Both** → record `$runMode = 'both'`. Generate `compose.yml` AND keep `php -S` instructions in the README.

Recipe to copy into `compose.yml` (when needed):

```yaml
services:
  app:
    image: php:8.3-cli
    working_dir: /app
    command: php -S 0.0.0.0:8000 -t <docroot>
    ports: ["8000:8000"]
    volumes: [".:/app"]
```

Substitute `<docroot>` with the value from step 2.2.

For deployment beyond local dev, see [`DEPLOYMENT.md`](DEPLOYMENT.md)
(Apache / nginx / Caddy rewrite rules).

---

## Step 4 · Architecture pattern

### 4.1 — Ask the user

> "Which architecture pattern fits the app you're building? See [`PATTERNS.md`](PATTERNS.md) for the long version; the short version is:
>
>   - **Transaction Script** — fewer than ~10 routes, no shared logic, throwaway tool. Inline route handlers in `app/routes.php`.
>   - **MVC + Repository** (default) — 10–50 routes, CRUD-heavy, content site or admin. Controllers + Repository + Views.
>   - **DDD Layered** — rich domain with invariants you need enforced everywhere. Domain / Application / Infrastructure / Presentation. Don't pick this unless you can name your aggregates and invariants.
>   - **Decide later** — defaults to Transaction Script; trivial to migrate to MVC when you outgrow it.
>
> Which one?"

### 4.2 — Action by answer

- **Transaction Script** / **Decide later** → copy [`examples/basic/`](examples/basic/) as the starting template.
- **MVC + Repository** → ask the follow-up at 4.3, then copy.
- **DDD Layered** → copy [`examples/library/`](examples/library/) as the starting template.

### 4.3 — Follow-up (only if MVC + Repository was chosen)

> "What shape is your primary data? See PATTERNS.md §Tier 2:
>
>   - **Document-style** (slug-keyed JSON / Markdown files, content-site shape) — copy [`examples/contacts/`](examples/contacts/)
>   - **Relational rows / pk-keyed flat dicts** (typed entities via `Cloude\Model`) — copy [`examples/basic/`](examples/basic/) and add a `Cloude\Model` subclass per entity following [`examples/recipes/model.php`](examples/recipes/model.php)
>
> Which one?"

### 4.4 — Action

Whatever example was chosen at 4.2 / 4.3:

- `cp -R examples/<chosen>/. $projectDir/` (everything including hidden files)
- Rename namespace `App\` to the user's choice from step 1.2 if different:
  - In every `*.php` file under `$projectDir/app/`, replace `namespace App\` with `namespace <UserNs>\`
  - Same for every `use App\` and every `\App\` reference
- Update `$projectDir/composer.json`:
  - Set `name` to `$composerName`
  - Set `autoload.psr-4` to `{"<UserNs>\\": "app/classes/"}` if that's the chosen structure (DDD layout uses `app/`, basic uses `app/`, contacts uses `app/`)
- Rename `$docroot` if user picked something other than `www`:
  - `mv $projectDir/www $projectDir/$docroot`
  - Adjust references in `$projectDir/$docroot/index.php` (the `dirname(__DIR__, …)` paths don't change since we just renamed, but the `/<docroot>` in any URL or doc still does)
  - Update `composer.json` autoload paths if needed

---

## Step 5 · Frontend approach (CSS)

### 5.1 — Ask the user

> "How do you want to handle CSS?
>
>   - **None** (plain HTML, no styles) — for pure JSON APIs or MCP servers
>   - **Pico.css** (default; classless, semantic, ~10 KB, perfect for prototypes and admins) — via CDN
>   - **Bootstrap 5** — via CDN, the de-facto familiar choice
>   - **Tailwind CSS** — via CDN (Play CDN, dev-only — for prod you'll want a build step)
>   - **Custom** — empty `assets/app.css` for you to fill in
>
> Which one?"

### 5.2 — Action by answer

Insert the appropriate `<link>` tag into `$projectDir/app/views/layout.html.php` inside `<head>`:

- **None** → no change
- **Pico.css** → `<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@picocss/pico@2/css/pico.min.css">`
- **Bootstrap 5** → `<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" crossorigin="anonymous">`
- **Tailwind** → `<script src="https://cdn.tailwindcss.com"></script>` (Tailwind Play CDN — recommend a build step for prod)
- **Custom** → create `$projectDir/$docroot/assets/app.css` (empty), link from layout: `<link rel="stylesheet" href="/assets/app.css">`

### 5.3 — Record the choice

Store `$css = '<answer>'` for the summary in step 7.

---

## Step 6 · Frontend interactivity (JS)

### 6.1 — Ask the user

> "How much client-side JS will you need?
>
>   - **None** (default for server-rendered apps; HTML forms POST to routes)
>   - **Vanilla JS only** (fetch() + DOM, no library) — copy the pattern from [`examples/contacts/www/assets/app.js`](examples/contacts/www/assets/app.js)
>   - **Alpine.js** (CDN, 7 KB, great for sprinkles of interactivity without a build step) — adds `<script defer src=".../alpinejs"></script>` to the layout
>   - **htmx** (CDN, 14 KB, server-side HTML responses — pairs perfectly with `Cloude\View`)
>
> Which one?"

### 6.2 — Action by answer

- **None** → no change
- **Vanilla JS only** → create `$projectDir/$docroot/assets/app.js` (empty), link `<script src="/assets/app.js" defer></script>` in the layout
- **Alpine.js** → `<script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.14.1/dist/cdn.min.js"></script>`
- **htmx** → `<script src="https://unpkg.com/htmx.org@2.0.3"></script>` (use the latest stable from htmx.org)

---

## Step 7 · Optional modules

### 7.1 — Multi-environment config

> "Will you have different config per environment (dev vs prod, secrets per env)?"

- **Yes** → create `$projectDir/app/config/` directory with at least `storage.php` (empty `<?php return [];` is fine). Wire `\Cloude\Config::configure(__DIR__ . '/config');` in `app/config.php`.
- **No** → skip. Add later when needed (see [`examples/recipes/config.php`](examples/recipes/config.php)).

### 7.2 — Persistence

> "Will you store data? If so, what shape?"

- **No storage** → skip.
- **JSON / Markdown documents** (slug-keyed files, content-site shape) → already covered if you copied `examples/contacts/`. Otherwise mention `Cloude\Data\JsonRepository` / `Cloude\Data\MarkdownRepository`.
- **Relational (MySQL / SQLite / Postgres)** → add `storage.php` config with a PDO connection, copy [`examples/recipes/model.php`](examples/recipes/model.php) as a starting pattern.
- **Both** → both setups, see PATTERNS.md §Tier 2 variants.

### 7.3 — Mail

> "Will the app send email (welcome, password reset, notifications, daily report, etc.)?"

- **Yes** → create `$projectDir/app/config/mail.php` with the sendmail default:

    ```php
    <?php
    return [
        'transport' => 'sendmail',
        'from'      => 'noreply@example.com',
    ];
    ```

  Adjust to SMTP later (MailerSend / Mailgun / SendGrid configs in [`examples/recipes/mail.php`](examples/recipes/mail.php)).

- **No** → skip.

### 7.4 — MCP server

> "Will you expose an MCP (Model Context Protocol) endpoint for AI tools / agents to call?"

- **Yes** → add `examples/mcp/app/Routes.php`'s `Routes::register` body's MCP wiring to the user's `Routes.php`. Reference: [`examples/mcp/`](examples/mcp/).
- **No** → skip.

---

## Step 8 · Install + smoke test

### 8.1 — Composer

In `$projectDir`:

```bash
# Add the framework. Pin to the current release floor; bump as needed.
composer require cloude/framework
```

If composer isn't installed locally, prefer the project-local `composer.phar`:

```bash
php -r "copy('https://getcomposer.org/installer', 'composer-setup.php');"
php composer-setup.php
php -r "unlink('composer-setup.php');"
php composer.phar require cloude/framework
```

### 8.2 — Smoke

If `$runMode` is `php-s` or `both`:

```bash
php -S localhost:8000 -t $docroot
# In another terminal:
curl -s -o /dev/null -w '%{http_code}\n' http://localhost:8000/
# Expected: 200
```

If `$runMode` is `docker` or `both`:

```bash
docker compose up app
# In another terminal:
curl -s -o /dev/null -w '%{http_code}\n' http://localhost:8000/
# Expected: 200
```

### 8.3 — Summary

After all steps, summarise the configuration for the user:

```
Created project: $projectDir
  - composer name:   $composerName
  - PHP namespace:   <UserNs>\
  - docroot:         $docroot/
  - pattern:         <transaction-script | mvc-doc | mvc-model | ddd>
  - run mode:        <php-s | docker | both>
  - CSS:             <none | pico | bootstrap | tailwind | custom>
  - JS:              <none | vanilla | alpine | htmx>
  - multi-env config: <yes | no>
  - persistence:     <none | json | model | both>
  - mail:            <yes | no>
  - MCP:             <yes | no>

Next steps:
  1. cd $projectDir
  2. <run command for your chosen mode>
  3. Open http://localhost:8000
  4. Edit app/Routes.php to add your first route
  5. See PATTERNS.md to know when you're outgrowing the chosen pattern
```

---

## Notes for the agent

- **Don't suggest patterns the user didn't ask for.** They picked one; build that one.
- **Don't add Cloude modules they didn't opt into** (no Mailer if step 7.3 was "No", no MCP route if step 7.4 was "No").
- **Don't pin composer to `dev-main`** unless explicitly asked. Use the current released version.
- **Don't reach for symfony/* or laravel/* shims.** The framework is self-contained on purpose.
- **Show the user the file tree at the end** so they know what they got.
- **If a step's answer is ambiguous**, ask a clarifying question rather than guessing.
- **PHP / docker availability**: check before recommending. If `php --version` returns < 8.3, suggest Docker mode instead of `php -S`.

---

## When the user just wants "the recommended setup"

If the user says "give me your recommendation, just pick", use these defaults:

| Step | Default |
|---|---|
| 1.2 namespace | `App\` |
| 2.2 docroot | `www/` |
| 3 run mode | `php -S` (Docker if PHP not installed) |
| 4 pattern | MVC + Repository (document variant, `contacts/` template) |
| 5 CSS | Pico.css via CDN |
| 6 JS | Vanilla JS (empty `app.js` ready) |
| 7.1 multi-env | No (add later) |
| 7.2 persistence | JSON documents |
| 7.3 mail | No (add later) |
| 7.4 MCP | No (add later) |

Confirm this set with the user once before proceeding ("I'm going to use these defaults: [list] — OK?").
