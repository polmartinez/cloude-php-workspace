# mcp — minimal MCP server

The smallest end-to-end MCP (Model Context Protocol) server built on
`cloude/framework`. Two toy tools, one static resource, three
endpoints. Designed to be the "hello world" of MCP in this codebase.

## Run

From the repository root:

```bash
php -S localhost:8003 -t examples/mcp/www
```

For Docker / nginx / etc., see [`../../DEPLOYMENT.md`](../../DEPLOYMENT.md).

## Endpoints

| Method | Path                      | Purpose                                          |
|--------|---------------------------|--------------------------------------------------|
| GET    | `/.well-known/mcp.json`   | Discovery manifest — capabilities + tool names   |
| GET    | `/mcp`                    | Same manifest (browser sanity check)             |
| POST   | `/mcp`                    | JSON-RPC 2.0 — `initialize`, `tools/*`, `resources/*` |

## Try it

```bash
# 1) Discovery — what tools / resources are available?
curl -s http://localhost:8003/.well-known/mcp.json | jq

# 2) List tools
curl -s -X POST http://localhost:8003/mcp \
     -H 'Content-Type: application/json' \
     -d '{"jsonrpc":"2.0","id":1,"method":"tools/list"}' | jq

# 3) Call `echo` — your typical connectivity check
curl -s -X POST http://localhost:8003/mcp \
     -H 'Content-Type: application/json' \
     -d '{"jsonrpc":"2.0","id":2,"method":"tools/call",
          "params":{"name":"echo","arguments":{"message":"hi from curl"}}}' | jq

# 4) Call `now` — no input args needed
curl -s -X POST http://localhost:8003/mcp \
     -H 'Content-Type: application/json' \
     -d '{"jsonrpc":"2.0","id":3,"method":"tools/call",
          "params":{"name":"now","arguments":{}}}' | jq

# 5) Read the static resource
curl -s -X POST http://localhost:8003/mcp \
     -H 'Content-Type: application/json' \
     -d '{"jsonrpc":"2.0","id":4,"method":"resources/read",
          "params":{"uri":"mem://welcome"}}' | jq

# 6) Bad input → validation error (-32602)
curl -s -X POST http://localhost:8003/mcp \
     -H 'Content-Type: application/json' \
     -d '{"jsonrpc":"2.0","id":5,"method":"tools/call",
          "params":{"name":"echo","arguments":{}}}' | jq
```

## What this demo shows

- **Tool registration** — `Server::tool($name, $description, $inputSchema, $handler)`.
  The handler receives the parsed `arguments` array (already validated
  against `inputSchema` by `Cloude\JsonSchema`) and must return an
  array, typically `{'content' => [['type' => 'text', 'text' => ...]]}`.
- **Resource provider + reader** — for a tiny static catalogue. Real
  servers would read from disk; the shape is the same.
- **Discovery manifest** — `Server::respondManifest()` emits
  `/.well-known/mcp.json` so clients can introspect.
- **JSON-RPC plumbing for free** — CORS headers, error codes,
  multi-method dispatch (`initialize`, `tools/list`, `tools/call`,
  `resources/list`, `resources/read`) all handled by `Server::dispatch()`.

## What's deliberately NOT here

- No external data source — the tools return ad-hoc strings. For a
  realistic server that reads from a JSON repo, see
  [`examples/contacts/`](../contacts/) (web demo) or
  [`examples/recipes/mcp.php`](../recipes/mcp.php) (denser snippet
  with multiple tools, enum validation, structured errors).
- No auth — production MCP servers behind an API key would validate
  it inside the tool handler (or in a middleware wrapping `dispatch()`).
- No `prompts/*` support — `Server::promptProvider/Reader` exist if
  you need them; this demo skips them for minimalism.

## Layout

```
examples/mcp/
├── www/
│   └── index.php           ← front controller (autoload + bootstrap + dispatch)
└── app/
    ├── config.php          ← BASE_URL, DEBUG
    └── Routes.php          ← builds the Server, registers /mcp + /.well-known
```

No `data/`, no `views/`, no `assets/` — MCP servers are pure JSON
back-ends, no UI to render.
