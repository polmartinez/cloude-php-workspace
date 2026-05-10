<?php

declare(strict_types=1);

/**
 * Recipe: a tiny MCP server with two tools, one resource, and CORS handled
 * for you. Drop-in replacement for the JSON-RPC plumbing every MCP server
 * repeats.
 *
 * Wire it from your router:
 *
 *   $mcp = require __DIR__ . '/recipes/mcp.php';
 *   $router->get('/.well-known/mcp.json', fn () => $mcp->respondManifest());
 *   $router->any(['/mcp', '/mcp-server'], fn () => $mcp->dispatch());
 *
 * Test from the shell:
 *
 *   curl -s localhost:8000/.well-known/mcp.json
 *   curl -s -X POST -H 'Content-Type: application/json' \
 *        -d '{"jsonrpc":"2.0","id":1,"method":"tools/list"}' \
 *        localhost:8000/mcp
 *   curl -s -X POST -H 'Content-Type: application/json' \
 *        -d '{"jsonrpc":"2.0","id":2,"method":"tools/call",
 *             "params":{"name":"echo","arguments":{"message":"hi"}}}' \
 *        localhost:8000/mcp
 */

use Cloude\Mcp\JsonRpc;
use Cloude\Mcp\McpException;
use Cloude\Mcp\Server;

$mcp = new Server(
    name:        'sample-mcp',
    version:     '1.0',
    description: 'Sample MCP server bundled with cloude/framework.',
    endpoint:    BASE_URL . '/mcp',
    instructions: 'Use the tools below. Pass `country` as ISO-3166-1 alpha-2 in lowercase.',
);

// ── Tool: echo ───────────────────────────────────────────────────────────────
// Smallest possible tool. Useful as a connectivity check.

$mcp->tool(
    name:        'echo',
    description: 'Echoes the provided message back.',
    inputSchema: [
        'type'       => 'object',
        'properties' => ['message' => ['type' => 'string', 'minLength' => 1]],
        'required'   => ['message'],
    ],
    handler: fn (array $args): array => [
        'content' => [['type' => 'text', 'text' => 'You said: ' . $args['message']]],
    ],
);

// ── Tool: country lookup ─────────────────────────────────────────────────────
// Shows: enum validation, throwing structured errors, returning markdown.

$countries = [
    'es' => 'Spain', 'fr' => 'France', 'de' => 'Germany',
    'gb' => 'United Kingdom', 'us' => 'United States',
];

$mcp->tool(
    name:        'country_name',
    description: 'Returns the English name of a country given its ISO-3166-1 alpha-2 code.',
    inputSchema: [
        'type'       => 'object',
        'properties' => [
            'country' => [
                'type'        => 'string',
                'pattern'     => '^[a-z]{2}$',
                'description' => 'Lowercase ISO-3166-1 alpha-2 code, e.g. es, fr, de.',
            ],
        ],
        'required' => ['country'],
    ],
    handler: function (array $args) use ($countries): array {
        $code = $args['country'];
        if (!isset($countries[$code])) {
            // Structured error → translates to JSON-RPC -32602 with this message.
            throw new McpException(JsonRpc::INVALID_PARAMS, "Unknown country code: $code");
        }
        return [
            'content' => [[
                'type' => 'text',
                'text' => "**$code** → {$countries[$code]}",
            ]],
        ];
    },
);

// ── Resource provider + reader (optional) ────────────────────────────────────
// Static catalogue. In a real server you'd read from disk or a DB.

$resources = [
    'mem://hello' => ['mimeType' => 'text/plain',     'text' => 'Hello, world.'],
    'mem://about' => ['mimeType' => 'text/markdown',  'text' => "# About\n\nSample MCP server."],
];

$mcp->resourceProvider(fn (): array => array_map(
    fn ($uri, $r) => ['uri' => $uri, 'name' => basename($uri), 'mimeType' => $r['mimeType']],
    array_keys($resources),
    $resources,
));

$mcp->resourceReader(function (string $uri) use ($resources): ?array {
    if (!isset($resources[$uri])) {
        return null; // → server returns -32002 Resource not found
    }
    return ['uri' => $uri] + $resources[$uri];
});

// Return the configured server so the caller can wire it up.
return $mcp;
