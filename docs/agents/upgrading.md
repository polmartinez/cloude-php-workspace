# Upgrading between versions

> Part of the [AGENTS](../../AGENTS.md) reference. For an agent that
> knows an older `cloude/framework`, or is updating an app's
> dependency: what behaves differently and what to change. The full
> list of releases is in [`CHANGELOG.md`](../../CHANGELOG.md).

## How to use this page

1. Find the installed version: `composer show cloude/framework | grep versions`
   (or `vendor/cloude/framework` → `git describe --tags`).
2. Read every section **above** that version, oldest first.
3. Each section says what to search for in the app (`grep` patterns)
   and what to replace it with.
4. After upgrading: `vendor/bin/cloude-test --fail-on-deprecation`.

Only versions that need action are listed. A version missing here
added API only (see the changelog) — nothing to change.

## Rules that hold for every 2.x release

- No public method was removed or renamed after 2.4.1. Code written
  for 2.5+ keeps compiling.
- What changes between minors is **behaviour**: what a call writes,
  returns or throws. That is what the sections below are about.
- Minimum PHP is 8.3 for all of 2.x; 2.23.2+ is clean on 8.5.

---

## → 2.26.0 · per-model connections

Only relevant if a model declares `$connection` other than `'default'`.

- **Search**: `Transaction::run(` and `Transaction::begin(` in code that
  writes through such a model.
- **Problem**: without a name they act on `'default'`; that model's
  writes were never part of the transaction.
- **Change** to `TheModel::transaction(fn () => …)` or
  `Transaction::run($fn, 'analytics')`.
- Tests that `configure()` a model and expect the next test to get the
  config-driven storage back: call `TheModel::resetStorage()` in
  `tearDown()`.

## → 2.25.0 · Redis errors

- **Search**: `catch (\RedisException` around `Cache::` calls.
- **Change**: connection, password and database failures are now
  `\RuntimeException` (message `Failed to connect to Redis at …`). Catch
  `\RuntimeException | \RedisException` — the second still covers a
  connection that drops mid-request.

## → 2.23.0 · native upsert available

Nothing breaks. Optional cleanup:

- **Search**: `updateOrCreate(` and hand-written "query → first() →
  find()+save() or create()" sequences.
- **Change** to `Model::upsert($match, $values)` **only where** a
  UNIQUE index or the PK covers the `$match` columns. Add
  `update: [..., 'updated_at']` if a `beforeSave()` hook stamps a
  column that must change on a collision.

## → 2.22.0 · driver-chosen quoting, PostgreSQL

- **Search**: `new Query(`, `new PdoStorage(`, `'quote_char'`.
- A third argument or `quote_char` you passed only to get backticks on
  MySQL can go; it is the default there. Keep it only to override.
- **Search**: `` ` `` (backticks) and `"` inside `selectRaw(` /
  `havingRaw(` strings if the app may run on PostgreSQL — remove the
  quoting or write it for the target server.
- **Search**: `->insert(` on PostgreSQL connections whose key column is
  not `id`: pass `returning: 'uuid'` to get the key back.

## → 2.21.0 · query cache keys

No code change. After deploying, automatically keyed query-cache
entries are recomputed once (old keys are no longer read; they expire
by TTL). Explicit `cache(…, key: …)` keys are unchanged.

## → 2.20.0 · `save()` writes changed columns only

- **Search**: code that relies on `save()` re-writing untouched
  columns, e.g. "load, save to bump / restore a column another process
  changed". That no longer happens — set the column explicitly.
- **Search** in tests: `lastCall('update')` and assertions on the full
  update payload. The payload is now only the changed columns.
- **Search**: comparisons of old and new values before calling
  `save()`. They can go; `save()` does it (`$model->isDirty()` exists).

## → 2.19.0 · decoded route parameters

- **Search**: `urldecode(` / `rawurldecode(` applied to a route
  parameter (`$p['slug']`, `$params[...]`).
- **Change**: remove the call. Leaving it decodes twice — `%25` would
  turn into a different character.

## → 2.18.0 · HEAD, transactions, mail

- **Search**: routes registered for `HEAD` that only duplicate a `GET`
  route — removable.
- **Search**: `$pdo->beginTransaction()` / `->commit()` mixed with
  `Transaction::` on the same connection — use one or the other; the
  outer `Transaction` level is now PDO's own transaction, so mixing
  throws `StorageException` ("There is already an active transaction").
- **Search**: manual `str_replace(["\r", "\n"], …)` on mail subjects /
  reply-to — no longer needed (keep it if harmless).

## → 2.13.0 · typed bindings

- **Search**: numeric values passed as strings to `where()` /
  `havingRaw()` bindings / `StorageException::execute()` when the
  comparison is against an aggregate or expression
  (`havingRaw('COUNT(*) > ?', ['5'])`).
- **Change**: pass `int` / `float`. String-typed digits stay strings
  on purpose (`'0123'` keeps its zero).

## → 2.12.1 · `Str::truncate()` length

- **Search**: `Str::truncate($s, $n - 3)` or other arithmetic that made
  room for the ellipsis.
- **Change**: pass the real maximum; the ellipsis is now counted inside
  it.

## → 2.11.0 · sessions start themselves

- **Search**: `Session::start()` with no arguments before the first
  `Session::get/set`. Removable; keep calls that pass cookie options.

## → 2.9.0 · `(string) DateTime`

- **Search**: `(string) $date`, string interpolation of a
  `Cloude\DateTime`, or a `datetime`-cast model attribute echoed
  directly. It is now `Y-m-d H:i:s`; use `->format(...)` where another
  format was expected.

## → 2.7.0 · no auto-timestamps

- Code written for 2.6.0's automatic `created_at` / `updated_at` needs
  the `beforeSave()` pattern from [model-schema](model-schema.md).

## → 2.4.1 · `Config::timezone()` removed

- **Search**: `Config::timezone(` → `Config::get('app.timezone', 'UTC')`.

## → 2.0.0 · model schema

- **Search**: `protected static array $casts` → rename to `$types`.
- **Search**: `::createTableSql(` / `::dropTableSql(` on a model →
  `Schema::createTableSql($table, $columns, …)` / `Schema::dropTableSql($table)`.

---

## Writing a release (framework maintainers)

When a change alters behaviour, the same commit updates:

1. `CHANGELOG.md` — a line under the new version, tagged
   **Changed** / **Added** / **Fixed** / **Removed**.
2. This page — a `→ x.y.z` section with *Search* and *Change* steps,
   if any app code may need to change.
3. The behaviour table in `AGENTS.md` ("Recent additions").

Version numbers: patch for fixes and docs, minor for new API or a
behaviour change that existing code survives, major for anything that
breaks a call.
