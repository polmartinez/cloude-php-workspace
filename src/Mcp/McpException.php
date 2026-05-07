<?php

declare(strict_types=1);

namespace Cloude\Mcp;

/**
 * Exception type for tool / resource / prompt handlers to signal a
 * structured JSON-RPC error to the client.
 *
 *   throw new McpException(JsonRpc::INVALID_PARAMS, "country not in allowlist");
 *
 * Server::dispatch() catches this and turns it into the proper
 * `{"error": {"code": ..., "message": ...}}` response. Any other Throwable
 * becomes an INTERNAL_ERROR (-32603).
 */
class McpException extends \RuntimeException
{
    /**
     * The JSON-RPC error code (e.g. JsonRpc::INVALID_PARAMS) is stored in
     * the inherited `$code` property, accessible via `$e->getCode()`.
     */
    public function __construct(int $code, string $message)
    {
        parent::__construct($message, $code);
    }
}
