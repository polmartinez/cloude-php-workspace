<?php

declare(strict_types=1);

namespace Cloude\Mcp;

use Cloude\Http\Response;
use Cloude\Input;
use Cloude\JsonSchema;

/**
 * Minimal MCP (Model Context Protocol) server, HTTP transport, JSON-RPC 2.0.
 *
 * Wires up the protocol-level boilerplate that every MCP server repeats:
 *
 *   - CORS headers + OPTIONS preflight
 *   - JSON-RPC parsing, error codes, response shape
 *   - `initialize`, `ping`, `notifications/*`, `prompts/*`, `resources/*`,
 *     `logging/setLevel`, `tools/list`, `tools/call`
 *   - automatic input validation against each tool's `inputSchema` via
 *     `Cloude\JsonSchema` — bad arguments turn into a -32602 response
 *     before the handler ever runs
 *   - `/.well-known/mcp.json` discovery manifest
 *
 * Out of scope: stdio transport, SSE / streaming, schema-against-schema
 * meta-validation, auth (do that in your route or a middleware).
 *
 *   $mcp = new Server(
 *       name:        'my-data',
 *       version:     '1.0',
 *       description: 'Public dataset.',
 *       endpoint:    BASE_URL . '/mcp',
 *   );
 *
 *   $mcp->tool(
 *       name:        'echo',
 *       description: 'Echoes the message.',
 *       inputSchema: [
 *           'type'       => 'object',
 *           'properties' => ['message' => ['type' => 'string']],
 *           'required'   => ['message'],
 *       ],
 *       handler: fn (array $args) => [
 *           'content' => [['type' => 'text', 'text' => $args['message']]],
 *       ],
 *   );
 *
 *   $router->get('/.well-known/mcp.json', fn () => $mcp->respondManifest());
 *   $router->any(['/mcp', '/mcp-server'], fn () => $mcp->dispatch());
 */
class Server
{
    private const PROTOCOL_VERSION = '2024-11-05';

    /** @var array<string, array{description:string, inputSchema:array<mixed>, handler:callable}> */
    private array $tools = [];

    /** @var (callable(): array<int,array<string,mixed>>)|null */
    private $resourceProvider = null;

    /** @var (callable(string): ?array<string,mixed>)|null */
    private $resourceReader = null;

    /** @var (callable(): array<int,array<string,mixed>>)|null */
    private $promptProvider = null;

    /** @var (callable(string, array<string,mixed>): ?array<string,mixed>)|null */
    private $promptReader = null;

    public function __construct(
        private readonly string $name,
        private readonly string $version,
        private readonly string $description,
        private readonly string $endpoint,
        private readonly string $instructions = '',
        private readonly string $protocolVersion = self::PROTOCOL_VERSION,
    ) {}

    /**
     * Register a tool. The handler receives the parsed `arguments` object as
     * an array (already validated against $inputSchema) and must return an
     * array — typically with an MCP-style `content` field:
     *
     *   return ['content' => [['type' => 'text', 'text' => '...']]];
     *
     * To signal a user-visible error, throw `McpException` with one of the
     * `JsonRpc::*` codes.
     *
     * @param array<mixed> $inputSchema
     */
    public function tool(string $name, string $description, array $inputSchema, callable $handler): self
    {
        $this->tools[$name] = [
            'description' => $description,
            'inputSchema' => $inputSchema,
            'handler'     => $handler,
        ];
        return $this;
    }

    /**
     * Provider for `resources/list`. The callable returns a list of resource
     * descriptors: `[{'uri' => ..., 'name' => ..., 'mimeType' => ...}, ...]`.
     */
    public function resourceProvider(callable $cb): self
    {
        $this->resourceProvider = $cb;
        return $this;
    }

    /**
     * Reader for `resources/read`. Receives the URI requested by the client.
     * Return either a content descriptor (`['uri' => ..., 'mimeType' => ..., 'text' => ...]`)
     * or null to signal "not found" (translated to a -32002 response).
     */
    public function resourceReader(callable $cb): self
    {
        $this->resourceReader = $cb;
        return $this;
    }

    /**
     * Provider for `prompts/list`. Same shape as resourceProvider.
     */
    public function promptProvider(callable $cb): self
    {
        $this->promptProvider = $cb;
        return $this;
    }

    /**
     * Reader for `prompts/get`. Receives (name, arguments). Return null to
     * signal "not found".
     */
    public function promptReader(callable $cb): self
    {
        $this->promptReader = $cb;
        return $this;
    }

    /**
     * Handles one HTTP request: CORS, JSON-RPC parse, dispatch, write
     * response. Call this from a route handler.
     */
    public function dispatch(): void
    {
        $this->emitCorsHeaders();

        $method = Input::method();
        if ($method === 'OPTIONS') {
            Response::noContent();
            return;
        }

        // GET on the endpoint with no body returns a small server-info
        // payload — convenient for browser sanity checks and for clients
        // that probe before posting.
        if ($method === 'GET') {
            $this->respondManifest();
            return;
        }

        $input = Input::json();
        if (!is_array($input)) {
            $this->emitError(null, JsonRpc::PARSE_ERROR, 'Invalid JSON');
            return;
        }

        $rpcMethod = (string) ($input['method'] ?? '');
        $id        = $input['id'] ?? null;
        $params    = is_array($input['params'] ?? null) ? $input['params'] : [];

        if ($rpcMethod === '') {
            $this->emitError($id, JsonRpc::INVALID_REQUEST, 'Missing method');
            return;
        }

        try {
            $result = $this->dispatchMethod($rpcMethod, $params);
            if ($result === null) {
                // Notification — no response body, 202 Accepted per MCP convention.
                http_response_code(202);
                return;
            }
            $this->emitResult($id, $result);
        } catch (McpException $e) {
            $this->emitError($id, $e->getCode(), $e->getMessage());
        } catch (\Throwable $e) {
            $this->emitError($id, JsonRpc::INTERNAL_ERROR, 'Internal error: ' . $e->getMessage());
        }
    }

    /**
     * Emits the `/.well-known/mcp.json` manifest. Wire it on a GET route.
     */
    public function respondManifest(): void
    {
        Response::json([
            'version' => '1.0',
            'servers' => [[
                'name'            => $this->name,
                'description'     => $this->description,
                'endpoint'        => $this->endpoint,
                'transport'       => 'http',
                'protocolVersion' => $this->protocolVersion,
                'capabilities'    => $this->capabilities(),
                'tools'           => array_keys($this->tools),
            ]],
        ], pretty: true);
    }

    // ── internals ────────────────────────────────────────────────────────────

    private function emitCorsHeaders(): void
    {
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, Authorization, MCP-Protocol-Version');
        header('Cache-Control: no-cache');
        header('CDN-Cache-Control: no-cache');
    }

    /**
     * @param array<string,mixed> $params
     */
    private function dispatchMethod(string $method, array $params): mixed
    {
        return match ($method) {
            'initialize' => [
                'protocolVersion' => $this->protocolVersion,
                'capabilities'    => [
                    'tools'     => ['listChanged' => false],
                    'resources' => ['subscribe' => false, 'listChanged' => false],
                    'prompts'   => ['listChanged' => false],
                ],
                'serverInfo'   => ['name' => $this->name, 'version' => $this->version],
                'instructions' => $this->instructions,
            ],
            'notifications/initialized', 'notifications/cancelled' => null,
            'ping'                       => new \stdClass(),
            'logging/setLevel'           => new \stdClass(),
            'resources/templates/list'   => ['resourceTemplates' => []],
            'resources/subscribe',
            'resources/unsubscribe'      => throw new McpException(JsonRpc::METHOD_NOT_FOUND, 'Subscriptions not supported'),
            'resources/list'             => ['resources' => $this->resourceProvider !== null ? ($this->resourceProvider)() : []],
            'resources/read'             => $this->readResource($params),
            'prompts/list'               => ['prompts' => $this->promptProvider !== null ? ($this->promptProvider)() : []],
            'prompts/get'                => $this->getPrompt($params),
            'tools/list'                 => ['tools' => $this->listTools()],
            'tools/call'                 => $this->callTool($params),
            default                      => throw new McpException(JsonRpc::METHOD_NOT_FOUND, "Method not found: $method"),
        };
    }

    /**
     * @return array<int, array{name:string, description:string, inputSchema:array<mixed>}>
     */
    private function listTools(): array
    {
        $list = [];
        foreach ($this->tools as $name => $t) {
            $list[] = [
                'name'        => $name,
                'description' => $t['description'],
                'inputSchema' => $t['inputSchema'],
            ];
        }
        return $list;
    }

    /**
     * @param array<string,mixed> $params
     * @return array<string,mixed>
     */
    private function callTool(array $params): array
    {
        $name = (string) ($params['name'] ?? '');
        $args = is_array($params['arguments'] ?? null) ? $params['arguments'] : [];

        if (!isset($this->tools[$name])) {
            throw new McpException(JsonRpc::METHOD_NOT_FOUND, "Tool not found: $name");
        }
        $tool = $this->tools[$name];

        $errors = JsonSchema::validate($args, $tool['inputSchema']);
        if ($errors !== []) {
            throw new McpException(JsonRpc::INVALID_PARAMS, 'Invalid arguments: ' . implode('; ', $errors));
        }

        $result = ($tool['handler'])($args);
        if (!is_array($result)) {
            throw new \RuntimeException("Tool '$name' handler must return an array");
        }
        return $result;
    }

    /**
     * @param array<string,mixed> $params
     * @return array<string,mixed>
     */
    private function readResource(array $params): array
    {
        $uri = (string) ($params['uri'] ?? '');
        if ($this->resourceReader === null) {
            throw new McpException(JsonRpc::METHOD_NOT_FOUND, 'Resources not supported');
        }
        $content = ($this->resourceReader)($uri);
        if ($content === null) {
            throw new McpException(JsonRpc::RESOURCE_NOT_FOUND, "Resource not found: $uri");
        }
        return ['contents' => [$content]];
    }

    /**
     * @param array<string,mixed> $params
     * @return array<string,mixed>
     */
    private function getPrompt(array $params): array
    {
        if ($this->promptReader === null) {
            throw new McpException(JsonRpc::METHOD_NOT_FOUND, 'Prompts not supported');
        }
        $name = (string) ($params['name'] ?? '');
        $args = is_array($params['arguments'] ?? null) ? $params['arguments'] : [];
        $prompt = ($this->promptReader)($name, $args);
        if ($prompt === null) {
            throw new McpException(JsonRpc::INVALID_PARAMS, "Prompt not found: '$name'");
        }
        return $prompt;
    }

    /**
     * @return array<string,bool>
     */
    private function capabilities(): array
    {
        return [
            'tools'     => $this->tools !== [],
            'resources' => $this->resourceProvider !== null || $this->resourceReader !== null,
            'prompts'   => $this->promptProvider !== null || $this->promptReader !== null,
        ];
    }

    private function emitResult(mixed $id, mixed $result): void
    {
        Response::json(['jsonrpc' => '2.0', 'id' => $id, 'result' => $result]);
    }

    private function emitError(mixed $id, int $code, string $message): void
    {
        Response::json([
            'jsonrpc' => '2.0',
            'id'      => $id,
            'error'   => ['code' => $code, 'message' => $message],
        ]);
    }
}
