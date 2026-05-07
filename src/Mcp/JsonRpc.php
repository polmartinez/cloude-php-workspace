<?php

declare(strict_types=1);

namespace Cloude\Mcp;

/**
 * JSON-RPC 2.0 + MCP-specific error codes.
 *
 * The first five are the JSON-RPC 2.0 standard. RESOURCE_NOT_FOUND is the
 * de-facto code MCP servers use when a `resources/read` URI is unknown.
 *
 * Use these from inside tool handlers to throw structured errors:
 *
 *   throw new McpException(JsonRpc::INVALID_PARAMS, "country not in allowlist");
 */
final class JsonRpc
{
    public const PARSE_ERROR        = -32700;
    public const INVALID_REQUEST    = -32600;
    public const METHOD_NOT_FOUND   = -32601;
    public const INVALID_PARAMS     = -32602;
    public const INTERNAL_ERROR     = -32603;
    public const RESOURCE_NOT_FOUND = -32002;
}
