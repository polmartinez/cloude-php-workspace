# Decision matrix — when to use what

> Part of the [AGENTS](../../AGENTS.md) reference. The full
> "you want to do X → use Y" lookup table for the framework's
> public surface. Optimised for random access: skim by topic,
> grab the call form, follow the link to the class docblock if
> you need more.

## Config

| You want to… | Use | Notes |
|---|---|---|
| Look up a project path | `Config::path('data')` / `Config::path('views')` | Reads `app.paths.{name}` — preferred over `DATA_DIR`-style globals |
| Resolve the base URL | `Config::baseUrl(['example.com'])` | Memoized; reads `app.base_url` → env → auto-detect |
| Read the debug flag | `Config::debug()` | Reads `app.debug` → env → false |
| Read the configured timezone | `Config::get('app.timezone', 'UTC')` | FW ships `config/app.php` with `'timezone' => 'UTC'` default; `Bootstrap::run()` calls `date_default_timezone_set()` with the resolved value at boot |
| Use short names in views (`View::e(...)`, `Str::slug(...)`) without `use` statements | Declare `'aliases' => ['View', 'Input', 'Str']` in `app/config/app.php` | `Bootstrap::run()` calls `class_alias('Cloude\<short>', '<short>')` for each entry. Skipped silently when the short name is already taken — your own classes are never stomped. Alternative: standard PHP `use Cloude\{View, Input, Str};` at the top of each view file |
| Any other config value | `Config::get('db.default.dsn')` | Multi-env file loader, see [`Cloude\Config`](../../README.md#cloudeconfig) |
| Set the app environment (`production` / `development` / `staging` / ...) | `Bootstrap::setEnv('production')` BEFORE `Config::configure(...)` — or assign `Bootstrap::$env = 'production'` and `Bootstrap::run()` will sync it | Drives the per-env overlay: any file under `app/config/production/` (or whatever name you pick) deep-merges over the base configs. Resolution order: `Bootstrap::setEnv()` → `APP_ENV` / `ENVIRONMENT` env var → Config default (`'dev'`). Read back via `Bootstrap::env()` |
| Ship default configs from a library / module | `Cloude\Config::addPath('/path/to/your/config')` | Resolution order is `[core, app, ...extra]` — last entry wins on every key (deep-merge via `Arr::merge`) |

## HTTP request input

| You want to… | Use | Notes |
|---|---|---|
| Request method | `Input::method()` | Uppercase, defaults to `'GET'`. The Router answers a `HEAD` with the matching `GET` route when no `HEAD` route exists |
| URI path (no query string) | `Input::uri()` | Normalized; double slashes collapsed |
| Route parameters | `$router->get('/libro/{slug}', fn (array $p) => $p['slug'])` | Percent-decoded (`caf%C3%A9` → `café`) since v2.19.0 — don't decode again. Matching runs on the raw path, so an encoded `%2F` stays inside its segment |
| Query-string param | `Input::get('q')` / `Input::get('q', 'default')` / `Input::get()` (full `$_GET`) | |
| POST field | `Input::post('email')` / `Input::post('email', '')` / `Input::post()` (full `$_POST`) | |
| Raw `$_SERVER` key | `Input::server('REMOTE_ADDR')` / `Input::server('HTTP_X_TENANT', 'public')` / `Input::server()` (full `$_SERVER`) | Case-sensitive — use canonical upper-snake (`HTTP_*`, `REMOTE_ADDR`, `PATH_INFO`, ...). Prefer the typed helpers when one exists |
| Request header | `Input::header('User-Agent')` / `Input::header('X-Request-Id', 'n/a')` | Translates `User-Agent` → `HTTP_USER_AGENT` under the hood; `Content-Type` / `Content-Length` are read from their un-prefixed `$_SERVER` keys |
| Client IP | `Input::ip()` (or `Input::ip(trustProxy: true)` behind a CDN you control) | Reads `REMOTE_ADDR`; with `trustProxy`, the first entry of `X-Forwarded-For` |
| JSON body | `Input::json()` | Returns `?array` (null on empty/invalid) |
| Raw body | `Input::body()` | String, never null |

## HTTP responses

| You want to… | Use | Notes |
|---|---|---|
| Send a JSON response | `Http\Response::json($data, $status, $pretty)` | Don't `header()` + `echo json_encode()` by hand |
| 404 / redirect / 204 | `Response::notFound`, `redirect`, `noContent` | |
| Throw a 404 from anywhere | `throw new Http\NotFoundException("book $isbn")` | Caught by `ErrorHandler`; renders bundled `404.html.php` (HTML), JSON, or plain text |
| Throw any HTTP status | `throw new Http\HttpException(403, 'forbidden')` | Same as above; uses `500.html.php` template by default for non-404 |
| Cache a 200 at the CDN | `Http\Cache::ok($stimeout = 86400, $timeout = 0)` | Sets `Cache-Control` + `CDN-Cache-Control`. `$stimeout` → `s-maxage` (shared / CDN); `$timeout` → `max-age` (browser). Default: CDN holds 1 day, browser revalidates every request (`max-age=0` → 304s when unchanged). Pass both args to cache in the browser too: `Cache::ok(86400, 300)` |
| Conditional GET (304) | `Cache::conditionalGet(filemtime($path))` | Returns true when client is fresh |
| Versioned asset URLs (`/{mtime}/assets/…`) | `Http\AssetUrl::configure(...)` then `AssetUrl::get($rel)` | Apache rewrite required |

## Files / JSON / encoding

| You want to… | Use | Notes |
|---|---|---|
| Read JSON file (cached) | `JsonFile::read($path)` / `readOr($path, $default)` | Per-request cache; `null` on missing/invalid |
| Write JSON atomically | `JsonFile::write($path, $data, $pretty)` | Temp + rename |
| Encode/decode by type | `Format::json($input)`, `Format::yaml`, `Format::xml`, `Format::markdown` | Dispatches by `string` ↔ `array` |
| Validate against JSON Schema | `JsonSchema::validate($data, $schema)` | Returns errors list, empty = valid |

## Strings, arrays, collections, dates

| You want to… | Use | Notes |
|---|---|---|
| Slug / transliterate | `Str::slug`, `Str::ascii` | Needs `ext-intl` for non-Latin |
| Random tokens, UUIDs, hashes | `Str::random()`, `Str::uuid()`, `Str::hash()` | |
| Case conversion | `Str::camel/pascal/snake/kebab` | Handles camel-case + non-alnum boundaries |
| Mask for privacy | `Str::mask('+34600123456', '*', 4, -3)` | Negative length keeps a tail visible |
| Truncate by the end | `Str::truncate($text, 80)` (default `…` is `'...'`) | Final length **never exceeds** `$length` — ellipsis budget comes OUT of `$length`, not on top. Multibyte-safe |
| Truncate by the middle | `Str::truncateMiddle($path, 25)` | Keeps both ends, drops the middle |
| Multibyte substring | `Str::sub($text, 0, 4)` / `Str::sub($text, -3)` | Wrapper over `mb_substr` — codepoints not bytes. Use instead of byte-level `substr()` for any text that may be non-ASCII |
| Multibyte length | `Str::len($text)` | Wrapper over `mb_strlen` — codepoints not bytes. Use instead of `strlen()` |
| Dot-path access | `Arr::get($a, 'foo.bar.baz', $default)` | Also `set/has/forget/pluck/dot/undot/merge` |
| Pipeline data | `Collection::make($rows)->filter(...)->sortBy(...)->take(...)->pluck(...)->all()` | Implements `ArrayAccess`, `Countable`, iterable |
| Work with dates | `DateTime::now()`, `DateTime::parse('2026-05-18')`, `$d->addDays(7)->toDateString()`, `$d->isPast()`, `$d->diffForHumans()` | `Cloude\DateTime` extends `\DateTimeImmutable` |
| Freeze `now()` in tests | `DateTime::setTestNow($when)` / `clearTestNow()` (or `freezeTime()` on `Cloude\Testing\TestCase`) | Carbon-style time travel for deterministic `isPast()` / `diffForHumans()` tests |

## Model / Storage / Query

| You want to… | Use | Notes |
|---|---|---|
| Build a SQL query | `User::query()->where('age', '>', 18)->orderBy('name')->get()` | `Cloude\Storage\Query` — SELECT/INSERT/UPDATE/DELETE + WHERE/JOIN/GROUP BY/HAVING/ORDER BY |
| GROUP BY + aggregates | `$q->select('country')->selectRaw('COUNT(*)', 'n')->groupBy('country')->orderBy('n', 'DESC')->get()` | `selectRaw($expr, $alias?)` appends a raw expression (no quoting); first call clears the default `*`. Use for any function call: `COUNT/SUM/AVG/MIN/MAX/...` |
| HAVING (filter on aggregates) | `$q->groupBy('country')->havingRaw('COUNT(*) > ?', [10])->get()` | Multiple `havingRaw()` calls AND-join. `$expression` is emitted verbatim — pass values through `$bindings`, never interpolate |
| Count distinct groups | `$q->groupBy('country')->count()` | Auto-wraps as `SELECT COUNT(*) FROM (<grouped query>)` so `count()` stays a scalar even with GROUP BY |
| Nested AND/OR predicates | `$q->where('active', 1)->whereGroup(fn ($g) => $g->where('role', 'admin')->orWhere('role', 'editor'))` | Use `orWhereGroup` for the OR-joined variant |
| INNER / LEFT / RIGHT / CROSS JOIN | `$q->leftJoin('orders', 'orders.user_id', '=', 'users.id')` | Columns may be `'table.col'` strings; quoted automatically |
| Static table / column references | `User::table()`, `User::field('email')`, `User::as('u')` | Avoid hand-writing `'users.email'` literals; pair `as()` with `Query::from()`/`join()` for typed joins |
| Alias a column in SELECT | `$q->select('id', ['name', 'type_name'])` (preferred), or `User::alias('name', 'type_name')`, or `$u->alias('name', 'who')` | Each emits the `[column, alias]` tuple that `select()` accepts. Legacy `'name AS alias'` string still works |
| Save a loaded model | `$u = User::find(1); $u->name = 'Ada'; $u->save();` | The UPDATE carries only the columns that changed since the row was read or last saved; with no change, storage is not called (`afterSave()` still runs). `$u->isDirty()` / `$u->isDirty('name')` / `$u->dirty()` (changed columns in storage form) tell you what a save would write. Columns set in `beforeSave()` are included |
| Atomic upsert on a unique key | `Target::upsert(['plan_id' => $p, 'week_no' => $w], ['value' => $v])` — builder form `$q->upsert($row, ['plan_id', 'week_no'], $updateCols?)` | One `INSERT … ON CONFLICT DO UPDATE` (`ON DUPLICATE KEY UPDATE` on MySQL). `$match` / the conflict list **must be a UNIQUE index or the PK**. On collision only `$values` are overwritten; add `update: [..., 'updated_at']` for hook-filled columns; `[]` = leave the existing row. Model form returns the row. See [`databases.md`](databases.md#native-upsert) |
| Upsert without a unique index (or on JSON / array storage) | `User::updateOrCreate(['email' => $e], ['name' => $n, 'active' => 1])` | Finds the row matching every `$match` k/v; updates with `$values` if present, creates `$match + $values` if missing (`$values` wins on overlap). `$values = []` makes it a read-or-create. **Not atomic alone** — pair with a UNIQUE index on `$match` columns + `Transaction::run()` for safe concurrent calls (`DuplicateKeyException` on the loser is recoverable) |
| Cast model attributes | `protected static array $types = ['age' => 'int', 'price' => 'decimal:2', 'tags' => 'json', 'created_at' => 'datetime', 'status' => 'enum:' . Status::class]` | Applied on hydrate (read) and save (write); null passes through. See `Cloude\Model\Cast` |
| Use PostgreSQL | `'dsn' => 'pgsql:host=…;dbname=…'` in `config/storage.php` — nothing else | `Query`, `PdoStorage` and `Model` follow the PDO driver: identifiers quoted with `"`, `insert()` gets its key through `RETURNING` (`$q->insert($data, returning: 'uuid')` to pick the column; default `id`), `update()` / `delete()` with `limit()` work, booleans go into `BOOLEAN` and integer columns alike, `ILIKE` / `NOT ILIKE` are accepted operators. `Schema::…Sql(…, 'pgsql')` and `User::indexesSql('pgsql')` emit PostgreSQL DDL. Don't pass `quote_char` unless overriding. Full detail: [`databases.md`](databases.md) |
| Catch a SQL error | `catch (\Cloude\Storage\StorageException $e)` | Subclasses: `TableNotFoundException`, `ColumnNotFoundException`, `DuplicateKeyException`, `IntegrityConstraintException`, `ConnectionException`, `SyntaxErrorException`. `$e->sql`, `$e->bindings`, `$e->sqlState` are public readonly |
| Run hand-written SQL | `StorageException::execute($pdo, 'SELECT … WHERE id = :id', ['id' => 5])->fetchAll()` | Same error wrapping and typed binding as the builder. `$params`: a list for `?`, or `name => value` for `:name`. Get the PDO with `Connection::pdo('default')`; `Connection::nameOf($pdo)` gives the pool name back |
| Wrap N writes in a transaction | `Transaction::run(fn() => …, 'default')` | `Cloude\Storage\Transaction` — closure auto-commits on return, rolls back + rethrows on any `\Throwable`. Manual API: `Transaction::begin()` / `commit()` / `rollback()` / `inTransaction()` / `depth()` The outer level is PDO's own transaction (`$pdo->inTransaction()` agrees); don't call `$pdo->beginTransaction()` inside it. |
| Nested transactions | Same API — second `Transaction::begin()` issues a `SAVEPOINT`; inner `commit` / `rollback` operate on the savepoint | Real nesting via SAVEPOINTs (most PDO drivers reject native nested `BEGIN`). Tracks depth per connection name |
| Auto-fill `created_at` / `updated_at` | Override `beforeSave()` with the four-line pattern documented at [`model-schema.md`](model-schema.md#recommended-auto-managed-created_at--updated_at) | Framework ships no `$timestamps` built-in — the pattern is `if (in_array('created_at', static::$properties, true) && !$this->isPersisted()) $this->created_at ??= $now;` etc. |
| Declare indexes / FKs on the model | `protected static array $indexes / $foreignKeys` | Emit standalone SQL via `User::indexesSql()` / `User::foreignKeysSql()` — metadata-only, see [`model-schema.md`](model-schema.md) |
| Emit `CREATE TABLE` SQL (full) | `Schema::createTableSql($table, $columns, $indexes, $foreignKeys, dialect: 'mysql')` | Standalone helper. `mysql` / `pgsql`. **Not a migration framework** |
| Foreign key with ON DELETE / ON UPDATE | `['columns' => ['role_id'], 'references' => 'roles', 'on' => ['id'], 'on_delete' => 'set null', 'on_update' => 'cascade']` | Used in `Model::$foreignKeys` or as `Schema::createTableSql`/`foreignKeySql` argument. Actions: `cascade`, `set null`, `restrict`, `no action`, `set default`. **Always emitted** in the SQL — defaults to `NO ACTION` when omitted |

## Repositories (file-based)

| You want to… | Use | Notes |
|---|---|---|
| Directory of `.json` per entity | extend `Data\JsonRepository` | Override `transform($data, $slug)` |
| Directory of `.md` per entity | extend `Data\MarkdownRepository` | Reads `.md.gz` transparently |
| Markdown → HTML (content you wrote) | `Markdown::toHtml($md)` | In-house parser; no Parsedown. Inline HTML passes through: not for text from outside |
| Markdown → HTML (a comment, a note, a model's answer) | `Markdown::toSafeHtml($md)` | Escaped first; links only relative / http(s) / mailto / tel; no tag or attribute from the text |
| Markdown frontmatter + body | `Markdown::parse($content)` | Returns `meta`, `html`, `paragraphs`, `description`, `noindex` |
| Serve a `.md` over HTTP | `Markdown\Server::serve($path, $canonical)` | 404 / 304 / canonical / gzip passthrough |

## Cache

Full guide: [`cache.md`](cache.md).

| You want to… | Use | Notes |
|---|---|---|
| Enable caching | Set `'default' => 'redis'` (or `'file'` / `'memcached'` / `'array'`) in `app/config/cache.php` | FW ships `default => false`, so every `Cache::*` call is a no-op `NullStore` until the app opts in. Named stores still work via `Cache::store('name')` even with `default => false` |
| Read / write the default cache store | `Cache::get($key)` / `Cache::put($key, $val, $ttl)` / `Cache::forget($key)` / `Cache::flush()` | `Cloude\Cache\Cache` — static façade. Default store is named by `cache.default` in `config/cache.php`. `ttl=0` (default) means no expiration |
| Get-or-compute pattern | `Cache::remember('key', 300, fn () => $heavy())` | Producer runs only on miss. `null` IS cacheable (uses internal sentinel) |
| Cache a SELECT query | `User::query()->where('active', 1)->cache(300)->get()` | Affects every SELECT terminal: `get`, `first`, `count`, `value`, `pluck`. Cache key defaults to `sha1(connection + sql + bindings)`; changing WHERE / ORDER BY / columns auto-invalidates |
| Cached query w/ manual invalidation | `User::query()->where(...)->cache(300, key: 'users:active')->get()` then `Cache::forget('users:active')` in `afterSave()` | Pass `key:` for stable, app-managed keys |
| Route a query to a non-default store | `User::query()->cache(60, store: 'fast')->get()` | Resolves via `Cache::store('fast')`; configure both in `cache.stores` |
| Reach a non-default store directly | `Cache::store('memcached')->set('foo', 1, 60)` | Same API as the façade — Store interface is 5 methods |
| Inject a store (tests) | `Cache::set('default', new ArrayStore())` | The literal `'default'` resolves to whatever store the config names as default — swap once, works for every `Cache::*` call and every cached query |
| Drivers | `array` (in-process, tests), `file` (one file per key under a dir), `redis` (ext-redis), `memcached` (ext-memcached) | Declare driver + driver-specific options per store in `config/cache.php` |
| Survive a cache outage | `try { $v = Cache::remember(…, $load); } catch (\RuntimeException \| \RedisException) { $v = $load(); }` | A configured store that cannot be reached throws; it never falls back silently. Redis connection / auth / database errors are `RuntimeException("Failed to connect to Redis at host:port…")`, raised on the first call (connections are lazy) |

## Sessions / mail / MCP

| You want to… | Use | Notes |
|---|---|---|
| Sessions | Just call `Session::set/get/has/forget/all`. Flash via `flash/pullFlash/reflash`. CSRF via `csrfToken/checkCsrf`. Auth flow: `regenerate()` after login | `Cloude\Session` — **lazy auto-start** with hardened defaults (`httponly`, `samesite=Lax`, `secure` on HTTPS) on first access. Routes that never touch session never issue a cookie. Call `Session::start([], $cookieParams)` first only when you need cookie-param overrides |
| Customize cookie lifetime / GC / save path / name | Declare `config/session.php` with `cookie_lifetime` / `cookie_samesite` / `cookie_secure` / `cookie_httponly` / `cookie_path` / `cookie_domain` / `gc_maxlifetime` / `name` / `save_path` | Auto-applied by `Session::start()` (and the lazy auto-start). Order: hardened defaults < FW `config/session.php` < `app/config/session.php` < explicit `Session::start($opts, $cookieParams)` args. **Pair `cookie_lifetime` with `gc_maxlifetime`** — a long cookie is useless if PHP GC's the server-side data after 24min |
| Store sessions in Redis / Memcached | In `config/session.php` set `'handler' => 'redis'` + a `'redis'` block (host/port/database/password/prefix), or `'handler' => 'memcached'` + a `'memcached'` block (`'servers' => [['host', port], ...]`) | Validates the extension is loaded and throws a clear message otherwise. The framework builds the `session.save_path` URI for you — for Redis: `tcp://host:port?database=N&prefix=...&auth=...`; for Memcached: `host1:port1,host2:port2`. Default handler is `'files'` (php.ini) |
| Send email (SMTP / sendmail) | `Mailer::forge()->send([...])` | Reads `app/config/email.php`. Framework ships defaults at `config/email.php`; app overrides key-by-key. AUTH LOGIN + STARTTLS for SMTP Line breaks in header values (subject, reply-to, addresses, custom headers) are collapsed to a space, so form input cannot add headers; the body is sent with CRLF line endings. |
| Sign outbound mail with DKIM | Add a `'dkim'` block in `app/config/email.php`: `'dkim' => ['domain' => '...', 'selector' => '...', 'private_key' => '/path/to/key.pem']` | `Cloude\Mail\DkimSigner` — relaxed/relaxed canon + RSA-SHA256 |
| MCP (Model Context Protocol) server | `new Mcp\Server(...)`, `tool()`, `resourceProvider()`, `resourceReader()` | HTTP / JSON-RPC 2.0; auto-validates `inputSchema` |

## DDD / Domain helpers

| You want to… | Use | Notes |
|---|---|---|
| DDD: value object base | `extends \Cloude\Domain\ValueObject` with `readonly` props + `__toString()`; gets structural `equals()` for free | Optional. Throw `Cloude\Domain\DomainException` from the constructor to enforce invariants at construction |
| DDD: aggregate root w/ events | `extends \Cloude\Domain\AggregateRoot`; call `recordEvent(new SomeEvent(...))` inside domain methods; application layer drains via `pullDomainEvents()` after persistence | `Cloude\Domain\DomainEvent` is the marker interface — implement it on plain readonly classes. Framework ships no event bus on purpose |
| DDD: domain invariant exception | `throw new \Cloude\Domain\DomainException("...")` | Extends `\DomainException`. Catch at the application boundary and translate to a user-friendly response |

## CLI / logging / webhooks

| You want to… | Use | Notes |
|---|---|---|
| CLI script | `Cli::parseArgs($argv)` + `flag/option/positional` + `info/warn/error/success/abort` | TTY-gated colors |
| Group CLI scripts | `TaskRunner::register / registerClass` | One entry-point script with `prefix:method` dispatch |
| File log with daily rotation | `new Logger($path, minLevel: 'info')` | |
| Fire-and-forget webhook | `EventLog::send($payload)` | curl_multi at shutdown |

## Testing

Full guide: [`testing.md`](testing.md).

| You want to… | Use | Notes |
|---|---|---|
| Write a test | `class FooTest extends \Cloude\Testing\TestCase`. Methods named `test*` are discovered. Lifecycle: `setUp()` / `tearDown()`. Assertions: `assertSame/True/False/Null/Count/InstanceOf/StringContainsString/Json/...` (PHPUnit-compatible names) |
| Run the tests | `vendor/bin/cloude-test` (or `composer test`). Filter: `--filter=Pattern`. Path scope: `cloude-test tests/Storage` |
| Parameterise a test | `#[\Cloude\Testing\DataProvider('cases')]` on the method + `public static function cases(): array { return ['label' => [arg1, arg2], ...]; }` |
| Expect an exception | `$this->expectException(SomeException::class)` (optional `expectExceptionMessage('substr')`). The runner verifies it was thrown |
| See what a newer PHP deprecates | Run the suite on it: `php8.5 vendor/bin/cloude-test` | The runner lists every deprecation notice once after the summary (`Deprecations (n):` + file:line) without failing. The framework itself raises none on 8.3 – 8.5 |
| Make deprecations fail the run (CI) | `vendor/bin/cloude-test --fail-on-deprecation` | Exit 1 and `FAILED` when PHP raised any. `.github/workflows/tests.yml` runs it on PHP 8.3 / 8.4 / 8.5 with PostgreSQL, MySQL and Redis services; there a skipped test fails the job too |
| Skip a test that needs something missing | `$this->markTestSkipped('needs ext-redis')` (in the test or in `setUp()`) | Shown as `S`, counted under `Skipped:`, exit code stays 0 |
| Run the PostgreSQL integration tests | `CLOUDE_TEST_PGSQL_DSN='pgsql:host=127.0.0.1;dbname=cloude_test' CLOUDE_TEST_PGSQL_USER=… CLOUDE_TEST_PGSQL_PASS=… composer test` | Without the DSN `tests/Storage/PgsqlIntegrationTest.php` is skipped. It only touches tables prefixed `cloude_it_` |
| Run the Redis integration tests | `CLOUDE_TEST_REDIS_HOST=127.0.0.1 composer test` (needs `ext-redis`) | `tests/Cache/RedisIntegrationTest.php` — `RedisStore`, `Cache` on Redis, the `redis` session handler. Skipped without the variable or the extension; writes only under a random `cloude_it_…:` prefix |
| Cloude-specific helpers on TestCase | `useArrayModel()`, `useSqliteModel()`, `useMockModel()`, `captureHttp()`, `assertJsonResponse()`, `assertHttpException()`, `freezeTime()`, `assertModelHas()`, `assertModelReceived()` |
| Mock a Model's storage with call recording | `$store = $this->useMockModel(User::class, $rows); /* code under test */; $this->assertModelReceived($store, 'update', times: 1); $store->lastCall('update')` | `Cloude\Testing\MockStorage` wraps `ArrayStorage` and records calls. For code that goes through `Model::query()`, use `useSqliteModel()` instead |
