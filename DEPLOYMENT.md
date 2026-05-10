# Running the examples

The bundled apps under [`examples/`](examples/) are intentionally
**self-contained** — each one boots through `php -S` (built-in PHP
server) without `.htaccess`, nginx config, or any other web server
involvement. The same commands work on every host.

Pick whichever runner fits your machine.

## With `php -S` (no install required)

PHP ≥ 8.4 is the only prerequisite. From the **repository root**:

```bash
php -S localhost:8000 -t examples/basic/www       # basic skeleton
php -S localhost:8001 -t examples/contacts/www    # form + live search
php -S localhost:8002 -t examples/library/www     # DDD layering
```

Then open the matching URL in your browser. The framework's
`Cloude\Bootstrap::serveStaticIfExists()` handles static-asset
passthrough automatically under `cli-server`, so CSS/JS under
`www/assets/` is served directly without front-controller hops.

## With Docker (no PHP install required)

The official PHP CLI image is enough — no Dockerfile, no
`docker-compose.yml`. From the **repository root**:

```bash
# basic
docker run --rm -it -p 8000:8000 -v "$PWD":/app -w /app/examples/basic \
    php:8.4-cli php -S 0.0.0.0:8000 -t www

# contacts
docker run --rm -it -p 8001:8001 -v "$PWD":/app -w /app/examples/contacts \
    php:8.4-cli php -S 0.0.0.0:8001 -t www

# library
docker run --rm -it -p 8002:8002 -v "$PWD":/app -w /app/examples/library \
    php:8.4-cli php -S 0.0.0.0:8002 -t www
```

> The whole repo is mounted at `/app` so the example's `index.php` can
> reach the framework's `src/` via its built-in fallback autoloader.
> No `composer install` needed.

### Optional: enable `ext-intl` for accent-aware slugs

`Cloude\Str::slug()` and `::ascii()` use `ext-intl`'s `Transliterator`
when present, so "Cataluña" becomes `cataluna` instead of `catalu-a`.
The base `php:8.4-cli` image doesn't ship `ext-intl`. To opt in, run:

```bash
docker run --rm -it -p 8001:8001 -v "$PWD":/app -w /app/examples/contacts \
    php:8.4-cli sh -c '
        apt-get update && apt-get install -y libicu-dev \
            && docker-php-ext-install -j$(nproc) intl \
            && php -S 0.0.0.0:8001 -t www
    '
```

The non-`intl` fallback still produces URL-safe slugs — just with the
non-ASCII characters dropped instead of transliterated.

## With `docker compose` (long-lived dev sessions)

If you'd rather keep one running container per app, drop a small
`compose.yml` at the repo root:

```yaml
services:
  basic:
    image: php:8.4-cli
    working_dir: /app/examples/basic
    command: php -S 0.0.0.0:8000 -t www
    ports: ["8000:8000"]
    volumes: [".:/app"]

  contacts:
    image: php:8.4-cli
    working_dir: /app/examples/contacts
    command: php -S 0.0.0.0:8001 -t www
    ports: ["8001:8001"]
    volumes: [".:/app"]

  library:
    image: php:8.4-cli
    working_dir: /app/examples/library
    command: php -S 0.0.0.0:8002 -t www
    ports: ["8002:8002"]
    volumes: [".:/app"]
```

Then:

```bash
docker compose up basic     # one app
docker compose up           # all three
```

## Production stacks

The framework itself is plain PHP 8.4 — it runs on Apache, nginx +
PHP-FPM, Caddy, Heroku, Fly.io, anywhere. The bundled examples
deliberately stay generic so the focus is on what each demo
illustrates, not the deploy plumbing.

For a production deploy you'll typically:

1. Copy your project files (yours, not the examples) onto the host.
2. Run `composer install --no-dev`.
3. Point your web server's document root at `www/`.
4. Forward every non-existing file to `www/index.php`.

The exact rewrite rule is one line per server:

| Server  | Rule |
|---------|------|
| Apache  | `RewriteRule ^ index.php [L]` (after a `-f` / `-d` short-circuit) |
| nginx   | `try_files $uri $uri/ /index.php?$query_string;` |
| Caddy   | `php_fastcgi unix//run/php/php-fpm.sock` + `file_server` |
| `php -S` | `Cloude\Bootstrap::serveStaticIfExists($docroot)` (already in every example's `index.php`) |

Anything more elaborate (HTTPS, headers, cache TTLs) is your hosting
platform's job, not the framework's.
