<?php

declare(strict_types=1);

namespace App;

use Cloude\Mcp\Server;
use Cloude\Router;

/**
 * Wires a minimal MCP server with two tools and a single static resource.
 *
 * Endpoints:
 *
 *   GET  /.well-known/mcp.json    →  discovery manifest
 *   GET  /mcp                     →  same manifest (browser sanity check)
 *   POST /mcp                     →  JSON-RPC 2.0 (initialize, tools/*, resources/*)
 *
 * Try it from the shell once you have `php -S localhost:8003 -t www`:
 *
 *   curl -s http://localhost:8003/.well-known/mcp.json | jq
 *
 *   curl -s -X POST http://localhost:8003/mcp \
 *        -H 'Content-Type: application/json' \
 *        -d '{"jsonrpc":"2.0","id":1,"method":"tools/list"}' | jq
 *
 *   curl -s -X POST http://localhost:8003/mcp \
 *        -H 'Content-Type: application/json' \
 *        -d '{"jsonrpc":"2.0","id":2,"method":"tools/call",
 *             "params":{"name":"echo","arguments":{"message":"hi"}}}' | jq
 */
class Routes
{
    public static function register(Router $router): void
    {
        $mcp = self::buildServer();

        $router->get('/.well-known/mcp.json', $mcp->respondManifest(...));
        $router->any(['/mcp', '/mcp-server'], $mcp->dispatch(...));
    }

    private static function buildServer(): Server
    {
        $mcp = new Server(
            name:        'cloude-mcp-demo',
            version:     '1.0.0',
            description: 'Minimal MCP server bundled with cloude/framework. Two toy tools, one static resource.',
            endpoint:    BASE_URL . '/mcp',
            instructions: 'Use `echo` for a connectivity check, or `now` to get the current UTC time. Read the welcome resource at mem://welcome.',
        );

        // ── Tool 1: echo ─────────────────────────────────────────────────────
        // Smallest possible tool. Useful as a smoke test from any MCP client.

        $mcp->tool(
            name:        'echo',
            description: 'Echoes the supplied message back.',
            inputSchema: [
                'type'       => 'object',
                'properties' => [
                    'message' => ['type' => 'string', 'minLength' => 1],
                ],
                'required'             => ['message'],
                'additionalProperties' => false,
            ],
            handler: static fn (array $args): array => [
                'content' => [['type' => 'text', 'text' => 'You said: ' . $args['message']]],
            ],
        );

        // ── Tool 2: now ──────────────────────────────────────────────────────
        // Zero-input tool. Shows that an empty `properties` schema is fine.

        $mcp->tool(
            name:        'now',
            description: 'Returns the current UTC timestamp as ISO-8601.',
            inputSchema: ['type' => 'object', 'properties' => (object) [], 'additionalProperties' => false],
            handler: static fn (array $args): array => [
                'content' => [['type' => 'text', 'text' => gmdate('c')]],
            ],
        );

        // ── Static resource catalogue ───────────────────────────────────────
        // One in-memory resource exposed at mem://welcome. Servers backed by
        // disk content would read from a directory instead (see the
        // `examples/recipes/mcp.php` snippet for the dynamic shape).

        $resources = [
            'mem://welcome' => [
                'mimeType' => 'text/markdown',
                'text'     => "# Welcome\n\nThis is a minimal MCP server.\nTry the `echo` and `now` tools.",
            ],
        ];

        $mcp->resourceProvider(static fn (): array => array_map(
            static fn (string $uri, array $r): array => [
                'uri'      => $uri,
                'name'     => basename($uri),
                'mimeType' => $r['mimeType'],
            ],
            array_keys($resources),
            $resources,
        ));

        $mcp->resourceReader(static function (string $uri) use ($resources): ?array {
            if (!isset($resources[$uri])) {
                return null;                                            // → -32002 RESOURCE_NOT_FOUND
            }
            return ['uri' => $uri] + $resources[$uri];
        });

        return $mcp;
    }
}
