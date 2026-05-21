# Bootstrapping a project

> Part of the [AGENTS](../../AGENTS.md) reference. Covers the
> directory constants, the canonical `www/index.php`, and the
> `app/config/app.php` shape every project shares.

**Philosophy (FuelPHP-style):** define a *fixed, minimal* set of
directory constants once, then route every other knob — base URL,
debug, data path, view path, db, mail, … — through `Cloude\Config`
files under `APPPATH/config/`.

## The three path constants

`Bootstrap::initPaths()` defines these once, before anything else:

| Constant   | Meaning                          | Typical value             |
|------------|----------------------------------|---------------------------|
| `DOCROOT`  | public web root (`www/`)         | `__DIR__` inside index.php |
| `APPPATH`  | application root (`app/`)        | `dirname(__DIR__) . '/app'` |
| `BASEPATH` | project root (parent of both)    | auto-derived (`dirname(APPPATH)`) |

Trailing slashes are stripped. If you pre-defined any of them (e.g. in
tests), `initPaths()` leaves the existing value alone.

## Canonical `www/index.php`

```php
<?php
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

\Cloude\Bootstrap::initPaths(
    docroot: __DIR__,
    apppath: dirname(__DIR__) . '/app',
);
\Cloude\Config::configure(APPPATH . '/config');

if (\Cloude\Bootstrap::serveStaticIfExists(DOCROOT)) {
    return false;  // dev-server static-file passthrough
}

\Cloude\Bootstrap::run();   // reads debug + views + timezone from Config

$router = new \Cloude\Router(\Cloude\Config::baseUrl(['example.com', 'localhost']));
// ...register routes...
$router->dispatch();
```

## Canonical `app/config/app.php`

```php
<?php
return [
    'base_url' => \Cloude\Config::env('BASE_URL'),    // null → auto-detect
    'debug'    => \Cloude\Config::boolEnv('DEBUG'),
    'timezone' => \Cloude\Config::env('TZ', 'UTC'),   // applied by Bootstrap::run()
    'aliases'  => ['View', 'Input', 'Str'],           // ← short-name shortcuts (see below)
    'paths' => [
        'data'  => BASEPATH . '/data',
        'views' => APPPATH . '/views',
    ],
];
```

### Short-name aliases (optional)

The `aliases` key lets `Bootstrap::run()` register process-global
aliases so views — and any other code — can drop the `Cloude\`
prefix:

```php
// In a view (.html.php), no `use` statements needed:
<title><?= View::e($title) ?></title>
<input value="<?= View::e(Input::get('q', '')) ?>">
<a href="/p/<?= Str::slug($post->title) ?>">…</a>
```

Each entry is the short name of a class under `Cloude\` (e.g.
`'View'` → `\Cloude\View`). The framework looks the full name up via
the autoloader and calls `class_alias($full, $short)`. Skipped
silently when the short name is already taken — your own classes /
PHP built-ins are never stomped.

Opt-in, no defaults: omit the `aliases` key and **no aliases are
registered**. If you prefer explicit `use Cloude\{View, Input, Str};`
at the top of each view, that's the standard PHP shape and works
without any framework wiring.

The framework ships `config/app.php` with `'timezone' => 'UTC'` as a
default. Your `app/config/app.php` overrides any key you set
(deep-merge); omit `'timezone'` to inherit `'UTC'`.

Then anywhere in the app:

```php
\Cloude\Config::baseUrl(['example.com']);     // memoized, validated against allowlist
\Cloude\Config::debug();                      // bool
\Cloude\Config::path('data');                 // app.paths.data
\Cloude\Config::get('app.timezone', 'UTC');   // any other config key
\Cloude\Config::get('db.default.dsn');
```

## Legacy bootstrap (still supported)

The previous global-constants approach (`defineBaseUrl()`,
`defineDebug()`, ad-hoc `DATA_DIR`) keeps working unchanged —
`Config::baseUrl()` / `Config::debug()` honor `BASE_URL` / `DEBUG`
when they're already defined as global constants. Migrate at your own
pace; **prefer the Config-driven approach in new code**.
