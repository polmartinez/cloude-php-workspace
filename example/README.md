# Cloude - Example App

A minimal, ready-to-run project built on top of [`cloude/framework`](../).

## Run locally

From this directory:

```bash
composer install
php -S localhost:8000 -t www
```

Open <http://localhost:8000>.

> When running from inside this repository (without `composer install`), `www/index.php` falls back to autoloading the framework directly from `../src/`. You only need Composer for the optional dependencies (Parsedown).

## What it ships

```
example/
  composer.json          # depends on cloude/framework
  www/
    index.php            # entry point: bootstraps config, autoload and the router
    .htaccess            # Apache rewrite rules (all non-files -> index.php)
  app/
    config.php           # BASE_URL, ROOT_DIR, DEBUG...
    routes.php           # App\Routes::register()
  views/
    layout.php           # base HTML layout
    home.php             # landing page
    hello.php            # dynamic-parameter example
    about.php            # static page
    404.php              # not-found page
```

## Routes

| Method | Path              | Handler                                    |
|--------|-------------------|--------------------------------------------|
| GET    | `/`               | Renders `home.php`                         |
| GET    | `/hello/{name}`   | Renders `hello.php` with `$name`           |
| GET    | `/about`          | Renders `about.php`                        |
| POST   | `/api/echo`       | Returns a JSON dump of the request         |

## Try the JSON endpoint

```bash
curl -X POST http://localhost:8000/api/echo \
  -H 'Content-Type: application/json' \
  -d '{"hello": "world"}'
```

## Use as a starting point

1. Copy this directory into a new repo.
2. Rename the namespace in `app/` (currently `App\`) if you want.
3. `composer require cloude/framework` to pull the framework from Packagist.
4. Add your routes in `app/routes.php` and your templates in `views/`.
