# Recipes

> Part of the [AGENTS](../../AGENTS.md) reference. Read the matching
> recipe before writing equivalent code.

Located at `vendor/cloude/framework/examples/recipes/` (or
`examples/recipes/` if you're in the framework repo itself). Each
file is self-contained, runnable, and copy-pasteable.

| File | Pattern |
|---|---|
| [`sitemap.php`](../../examples/recipes/sitemap.php) | XML sitemap (and sitemap-index) with `Format::xml` + `Response::xml` |
| [`jsonld.php`](../../examples/recipes/jsonld.php) | Schema.org JSON-LD (Article / BreadcrumbList / FAQPage) with `Format::json` |
| [`mcp.php`](../../examples/recipes/mcp.php) | MCP server with two tools and a static resource catalogue |
| [`tasks.php`](../../examples/recipes/tasks.php) | TaskRunner with one inline task and a task class |
| [`data.php`](../../examples/recipes/data.php) | Custom `JsonRepository` / `MarkdownRepository` subclasses |
| [`model.php`](../../examples/recipes/model.php) | `Cloude\Model` Active Record: connection, CRUD, joins, casts |
| [`mail.php`](../../examples/recipes/mail.php) | `Mailer::forge()` with SMTP / sendmail / memory transports |
| [`markdown.php`](../../examples/recipes/markdown.php) | Markdown rendering, parser swap, GFM tables |
| [`config.php`](../../examples/recipes/config.php) | Multi-env `Cloude\Config` setup (storage, dev/prod overrides) |
| [`config/email.php`](../../examples/recipes/config/email.php) | Drop-in `app/config/email.php` with SMTP / sendmail / DKIM hooks |

## Example apps (full mini-projects)

Located at `examples/` in the repo:

| App | Pattern (see [`PATTERNS.md`](../../PATTERNS.md)) |
|---|---|
| [`basic/`](../../examples/basic/) | Transaction Script — minimal skeleton |
| [`contacts/`](../../examples/contacts/) | MVC + Repository — form, JSON Schema, live search |
| [`library/`](../../examples/library/) | DDD layered — Domain / Application / Infrastructure / Presentation |
| [`mcp/`](../../examples/mcp/) | Minimal MCP server with two tools and a resource |
