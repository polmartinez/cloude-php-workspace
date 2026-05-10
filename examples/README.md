# Examples

Each subdirectory is an **independent** example. None of them depend on
each other — only on the framework itself (one level up at `../src/`).
You can copy any one out of the repo and treat it as a standalone
project.

| Example | What it shows |
|---------|---------------|
| [`basic/`](basic/)       | The smallest possible front-controller app. Routing, dynamic params, a JSON echo endpoint, two views. |
| [`contacts/`](contacts/) | Form handling with `JsonSchema` validation, file-per-entity storage via `JsonRepository`, accent-insensitive search, and a JSON endpoint consumed from JavaScript with debounced `fetch()`. |
| [`library/`](library/)   | DDD-style layering — Domain (value objects, aggregates, domain service), Application (use cases), Infrastructure (Cloude-backed adapters), Presentation (HTTP controllers). |
| [`recipes/`](recipes/)   | Standalone snippets — XML sitemap, Schema.org JSON-LD, MCP server, CLI task runner, custom JSON / Markdown repositories. Not full apps, just patterns to copy. |

## How to run them

See [`DEPLOYMENT.md`](../DEPLOYMENT.md) at the repo root — covers
`php -S` (one-line, no install), Docker (one-line, no PHP install),
and `docker compose` for longer dev sessions.

The shortest path:

```bash
php -S localhost:8000 -t examples/basic/www
php -S localhost:8001 -t examples/contacts/www
php -S localhost:8002 -t examples/library/www
```

## Independence rule

When adding a new example:

- Keep everything inside its own directory.
- The only allowed dependency is the framework, reached via the
  fallback autoloader in `www/index.php`
  (`dirname(__DIR__, 3) . '/src'`).
- Do not link to files in sibling examples from your README, code or
  config. If a snippet would be useful across examples, promote it to
  [`recipes/`](recipes/) instead.
