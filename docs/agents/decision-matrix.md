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
| Ship default configs from a library / module | `Cloude\Config::addPath('/path/to/your/config')` | Resolution order is `[core, app, ...extra]` — last entry wins on every key (deep-merge via `Arr::merge`) |

## HTTP responses

| You want to… | Use | Notes |
|---|---|---|
| Send a JSON response | `Http\Response::json($data, $status, $pretty)` | Don't `header()` + `echo json_encode()` by hand |
| 404 / redirect / 204 | `Response::notFound`, `redirect`, `noContent` | |
| Throw a 404 from anywhere | `throw new Http\NotFoundException("book $isbn")` | Caught by `ErrorHandler`; renders bundled `404.html.php` (HTML), JSON, or plain text |
| Throw any HTTP status | `throw new Http\HttpException(403, 'forbidden')` | Same as above; uses `500.html.php` template by default for non-404 |
| Cache a 200 at the CDN | `Http\Cache::ok($seconds)` | Sets both `Cache-Control` and `CDN-Cache-Control` |
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
| Truncate by the middle | `Str::truncateMiddle($path, 25)` | Keeps both ends, drops the middle |
| Dot-path access | `Arr::get($a, 'foo.bar.baz', $default)` | Also `set/has/forget/pluck/dot/undot/merge` |
| Pipeline data | `Collection::make($rows)->filter(...)->sortBy(...)->take(...)->pluck(...)->all()` | Implements `ArrayAccess`, `Countable`, iterable |
| Work with dates | `DateTime::now()`, `DateTime::parse('2026-05-18')`, `$d->addDays(7)->toDateString()`, `$d->isPast()`, `$d->diffForHumans()` | `Cloude\DateTime` extends `\DateTimeImmutable` |
| Freeze `now()` in tests | `DateTime::setTestNow($when)` / `clearTestNow()` (or `freezeTime()` on `Cloude\Testing\TestCase`) | Carbon-style time travel for deterministic `isPast()` / `diffForHumans()` tests |

## Model / Storage / Query

| You want to… | Use | Notes |
|---|---|---|
| Build a SQL query | `User::query()->where('age', '>', 18)->orderBy('name')->get()` | `Cloude\Storage\Query` — SELECT/INSERT/UPDATE/DELETE + WHERE/JOIN/ORDER BY |
| Nested AND/OR predicates | `$q->where('active', 1)->whereGroup(fn ($g) => $g->where('role', 'admin')->orWhere('role', 'editor'))` | Use `orWhereGroup` for the OR-joined variant |
| INNER / LEFT / RIGHT / CROSS JOIN | `$q->leftJoin('orders', 'orders.user_id', '=', 'users.id')` | Columns may be `'table.col'` strings; quoted automatically |
| Static table / column references | `User::table()`, `User::field('email')`, `User::as('u')` | Avoid hand-writing `'users.email'` literals; pair `as()` with `Query::from()`/`join()` for typed joins |
| Alias a column in SELECT | `$q->select('id', ['name', 'type_name'])` (preferred), or `User::alias('name', 'type_name')`, or `$u->alias('name', 'who')` | Each emits the `[column, alias]` tuple that `select()` accepts. Legacy `'name AS alias'` string still works |
| Cast model attributes | `protected static array $types = ['age' => 'int', 'price' => 'decimal:2', 'tags' => 'json', 'created_at' => 'datetime', 'status' => 'enum:' . Status::class]` | Applied on hydrate (read) and save (write); null passes through. See `Cloude\Model\Cast` |
| Catch a SQL error | `catch (\Cloude\Storage\StorageException $e)` | Subclasses: `TableNotFoundException`, `ColumnNotFoundException`, `DuplicateKeyException`, `IntegrityConstraintException`, `ConnectionException`, `SyntaxErrorException`. `$e->sql`, `$e->bindings`, `$e->sqlState` are public readonly |
| Auto-fill `created_at` / `updated_at` | Override `beforeSave()` with the four-line pattern documented at [`model-schema.md`](model-schema.md#recommended-auto-managed-created_at--updated_at) | Framework ships no `$timestamps` built-in — the pattern is `if (in_array('created_at', static::$properties, true) && !$this->isPersisted()) $this->created_at ??= $now;` etc. |
| Declare indexes / FKs on the model | `protected static array $indexes / $foreignKeys` | Emit standalone SQL via `User::indexesSql()` / `User::foreignKeysSql()` — metadata-only, see [`model-schema.md`](model-schema.md) |
| Emit `CREATE TABLE` SQL (full) | `Schema::createTableSql($table, $columns, $indexes, $foreignKeys, dialect: 'mysql')` | Standalone helper. `mysql` / `pgsql`. **Not a migration framework** |
| Foreign key with ON DELETE / ON UPDATE | `['columns' => ['role_id'], 'references' => 'roles', 'on' => ['id'], 'on_delete' => 'set null', 'on_update' => 'cascade']` | Used in `Model::$foreignKeys` or as `Schema::createTableSql`/`foreignKeySql` argument. Actions: `cascade`, `set null`, `restrict`, `no action`, `set default`. **Always emitted** in the SQL — defaults to `NO ACTION` when omitted |

## Repositories (file-based)

| You want to… | Use | Notes |
|---|---|---|
| Directory of `.json` per entity | extend `Data\JsonRepository` | Override `transform($data, $slug)` |
| Directory of `.md` per entity | extend `Data\MarkdownRepository` | Reads `.md.gz` transparently |
| Markdown → HTML | `Markdown::toHtml($md)` | In-house parser; no Parsedown |
| Markdown frontmatter + body | `Markdown::parse($content)` | Returns `meta`, `html`, `paragraphs`, `description`, `noindex` |
| Serve a `.md` over HTTP | `Markdown\Server::serve($path, $canonical)` | 404 / 304 / canonical / gzip passthrough |

## Sessions / mail / MCP

| You want to… | Use | Notes |
|---|---|---|
| Sessions | `Session::start()` then `set/get/has/forget/all`. Flash via `flash/pullFlash/reflash`. CSRF via `csrfToken/checkCsrf`. Auth flow: `regenerate()` after login | `Cloude\Session` — hardened defaults (`httponly`, `samesite=Lax`, `secure` on HTTPS). Opt-in: doesn't auto-start in `Bootstrap::run()` |
| Send email (SMTP / sendmail) | `Mailer::forge()->send([...])` | Reads `app/config/email.php`. Framework ships defaults at `config/email.php`; app overrides key-by-key. AUTH LOGIN + STARTTLS for SMTP |
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

| You want to… | Use | Notes |
|---|---|---|
| Write a test | `class FooTest extends \Cloude\Testing\TestCase`. Methods named `test*` are discovered. Lifecycle: `setUp()` / `tearDown()`. Assertions: `assertSame/True/False/Null/Count/InstanceOf/StringContainsString/Json/...` (PHPUnit-compatible names) |
| Run the tests | `vendor/bin/cloude-test` (or `composer test`). Filter: `--filter=Pattern`. Path scope: `cloude-test tests/Storage` |
| Parameterise a test | `#[\Cloude\Testing\DataProvider('cases')]` on the method + `public static function cases(): array { return ['label' => [arg1, arg2], ...]; }` |
| Expect an exception | `$this->expectException(SomeException::class)` (optional `expectExceptionMessage('substr')`). The runner verifies it was thrown |
| Cloude-specific helpers on TestCase | `useArrayModel()`, `useSqliteModel()`, `useMockModel()`, `captureHttp()`, `assertJsonResponse()`, `assertHttpException()`, `freezeTime()`, `assertModelHas()`, `assertModelReceived()` |
| Mock a Model's storage with call recording | `$store = $this->useMockModel(User::class, $rows); /* code under test */; $this->assertModelReceived($store, 'update', times: 1); $store->lastCall('update')` | `Cloude\Testing\MockStorage` wraps `ArrayStorage` and records calls. For code that goes through `Model::query()`, use `useSqliteModel()` instead |
