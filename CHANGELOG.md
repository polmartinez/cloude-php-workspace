# Changelog

All notable changes to `cloude/framework`. Versions follow
[SemVer](https://semver.org/); dates are omitted, tags are the source
of truth (`git tag -n1`).

**Upgrading code across these versions?** Read
[`docs/agents/upgrading.md`](docs/agents/upgrading.md): it lists, per
version, what behaves differently and exactly what to change.

Legend: **Changed** = existing code may behave differently ·
**Added** = new API, nothing to change · **Fixed** = bug fix ·
**Removed** = API gone.

## 2.26.0
- **Added** `Model::connectionName()`, `Model::transaction(callable)`, `Model::resetStorage()` for models on a connection other than `'default'`.

## 2.25.1
- Docs: `docs/agents/cache.md`; README section for `Cloude\Cache\Cache`.

## 2.25.0
- **Changed** `RedisStore`: a refused connection, rejected password or bad database raises `RuntimeException("Failed to connect to Redis at host:port…")` (phpredis's `RedisException` as `previous`) instead of a raw `RedisException`.
- **Added** Redis service and `tests/Cache/RedisIntegrationTest.php` in CI. In CI a skipped test fails the job.

## 2.24.1
- Docs: reading and maintaining the CI workflow.

## 2.24.0
- **Added** GitHub Actions workflow: PHP 8.3 / 8.4 / 8.5, PostgreSQL and MySQL services, code style.
- **Added** `cloude-test --fail-on-deprecation`.

## 2.23.3
- Docs: `docs/agents/testing.md`.

## 2.23.2
- **Fixed** Clean run on PHP 8.5: removed `Reflection*::setAccessible()` (tests) and `curl_close()` (`EventLog`), both deprecated in 8.5.
- **Changed** Test runner: deprecation notices are collected and listed after the summary instead of printed mid-run.

## 2.23.1
- Docs: choosing between `save`, `upsert` and `updateOrCreate`.

## 2.23.0
- **Added** `Query::upsert($row, $conflict, $update = null)`, `PdoStorage::upsert()`, `Model::upsert($match, $values = [], update: null)` — native `ON CONFLICT` / `ON DUPLICATE KEY UPDATE`.
- **Added** `tests/Storage/MysqlIntegrationTest.php`.

## 2.22.0
- **Added** PostgreSQL support across `Query`, `PdoStorage`, `Model`, `Schema`.
- **Changed** `new Query($pdo, …)` / `new PdoStorage($pdo, …)` / the storage factory pick the identifier quote from the PDO driver when none is given (was: always a backtick).
- **Changed** `Query::insert()` gains `?string $returning`; on PostgreSQL inserts use `RETURNING`.
- **Changed** `Query::count()` on a grouped query with no columns selects a constant in the subquery.
- **Added** `ILIKE` / `NOT ILIKE` operators; `TestCase::markTestSkipped()`; `Skipped:` in the runner summary.
- **Fixed** `Schema::createTableSql(…, 'pgsql')` emitted MySQL-only syntax.

## 2.21.0
- **Changed** Automatic `Query::cache()` keys include the connection name. **Added** `Connection::nameOf($pdo)`.

## 2.20.0
- **Changed** `Model::save()` on a loaded model writes only changed columns; with no change, no storage call.
- **Added** `Model::isDirty(?string)`, `Model::dirty()`.

## 2.19.0
- **Changed** Route parameters are percent-decoded (`rawurldecode`).

## 2.18.0
- **Fixed** Mail header injection: CR/LF in header values collapsed; envelope addresses stripped of whitespace / control characters; body normalised to CRLF.
- **Changed** A `HEAD` request with no `HEAD` route is served by the `GET` route.
- **Changed** `Transaction` uses PDO's own transaction at the outer level; savepoints via `exec()`; quiet commit after an implicit commit; depth counter can't get stuck.
- **Fixed** `StorageException::execute()` binds string keys as named placeholders again.
- **Fixed** `Input::header('Content-Type' | 'Content-Length')`.

## 2.17.2
- **Fixed** Markdown: italic inside bold.

## 2.17.1
- **Fixed** `Input::uri()` no longer throws on a malformed request URI (returns `/`).

## 2.17.0
- **Added** `Model::updateOrCreate($match, $values = [])`.

## 2.16.1
- **Changed** Session `memcached.servers` accepts a single `[host, port]` tuple.

## 2.16.0
- **Added** Session `handler`: `files` / `redis` / `memcached` via `config/session.php`.

## 2.15.0
- **Added** `config/session.php` drives cookie params, `gc_maxlifetime`, `name`, `save_path`.

## 2.14.2
- **Added** `Str::sub()`, `Str::len()`.

## 2.14.1
- **Added** `Input::server($key = null, $default = null)`.

## 2.14.0
- **Added** `Bootstrap::$env`, `Bootstrap::setEnv()`, `Bootstrap::env()`.

## 2.13.0
- **Added** `Query::groupBy()`, `havingRaw()`, `selectRaw()`.
- **Changed** Query / storage bindings are typed from the PHP value (`int`, `bool`, `null`), not all strings.

## 2.12.1
- **Changed** `Str::truncate()` never returns more than `$length` characters; the ellipsis counts.

## 2.12.0
- **Added** `Cloude\Cache\Cache` (array / file / redis / memcached; off by default), `Query::cache()`.

## 2.11.0
- **Changed** `Session` starts itself on first use (was: `start()` required).

## 2.10.0
- **Added** `Cloude\Storage\Transaction` with SAVEPOINT nesting.

## 2.9.0
- **Changed** `(string) Cloude\DateTime` returns `Y-m-d H:i:s`.

## 2.8.0
- **Added** `app.aliases` config: short class names in views.

## 2.7.0
- **Removed** Model auto-timestamps added in 2.6.0; use a `beforeSave()` hook.

## 2.6.0
- Model auto-timestamps (removed again in 2.7.0).

## 2.5.0
- **Changed** `Mailer::forge($partial)` deep-merges over `Config::get('email')`.

## 2.4.1
- **Removed** `Config::timezone()`; read `Config::get('app.timezone', 'UTC')`.

## 2.4.0
- **Added** framework `config/app.php` (`timezone` default `UTC`); `Bootstrap::run()` applies it.

## 2.3.0
- **Changed** `Schema` emitters validate descriptor shape strictly and throw on unknown keys.

## 2.2.0
- **Changed** FK emitters always write `ON DELETE` / `ON UPDATE` (default `NO ACTION`).

## 2.1.0
- **Added** `Model::$indexes`, `$foreignKeys`, `indexesSql()`, `foreignKeysSql()`; `Schema::indexSql()`, `foreignKeySql()`.

## 2.0.0
- **Changed** `Model::$casts` renamed to `$types`.
- **Removed** DDL from `Model` (`createTableSql` / `dropTableSql`); `Schema` is the standalone emitter.
