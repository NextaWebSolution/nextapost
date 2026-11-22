<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\ApiError;
use App\Core\DB;

/**
 * MCP server (Streamable HTTP, JSON responses only, no sessions).
 *
 * Dual-era:
 *  - modern clients (2026-07-28): every request carries its version in params._meta; headers
 *    MCP-Protocol-Version / Mcp-Method / Mcp-Name must match the body; server/discover is offered.
 *  - legacy clients (2025-11-25 and earlier): initialize handshake, then ordinary requests. We never
 *    mint a session id, so any request can be served by any PHP worker.
 *
 * Tools are generated from AgentApi::operations(), so MCP and REST always match.
 */
final class McpServer
{
    public const MODERN = ['2026-07-28'];
    public const LEGACY = ['2025-11-25', '2025-06-18', '2025-03-26'];
    private const META = 'io.modelcontextprotocol/';

    // JSON-RPC / MCP error codes
    public const PARSE_ERROR = -32700;
    public const INVALID_REQUEST = -32600;
    public const METHOD_NOT_FOUND = -32601;
    public const INVALID_PARAMS = -32602;
    public const HEADER_MISMATCH = -32020;
    public const UNSUPPORTED_VERSION = -32022;

    public function __construct(private DB $db, private array $ctx)
    {
    }

    public static function supportedVersions(): array
    {
        return array_merge(self::MODERN, self::LEGACY);
    }

    public static function serverInfo(): array
    {
        return ['name' => 'nextapost', 'title' => app_name(), 'version' => APP_VERSION];
    }

    public static function instructions(): string
    {
        return 'NextaPost schedules social media posts for a team. Start with get_workspace to learn the time zone, '
            . 'your role and what you may do, then list_channels for channel ids. Times are ISO 8601: add Z or an offset '
            . 'for an exact time, otherwise the workspace time zone is used. Use action "draft" to save without publishing, '
            . '"schedule" with publish_at (or queue=true for the channel\'s next free slot), "review" to ask an approver, '
            . 'and "now" only when the user clearly asked to publish immediately. Tool errors explain what to fix.';
    }

    /** MCP tool definitions, in a stable order. */
    public static function tools(): array
    {
        $tools = [];
        foreach (AgentApi::operations() as $name => $op) {
            $schema = ['type' => 'object', 'properties' => $op['params'] ? $op['params'] : (object) []];
            if (!empty($op['required'])) {
                $schema['required'] = $op['required'];
            }
            $write = !empty($op['write']);
            $tools[] = [
                'name' => $name, 'title' => $op['title'], 'description' => $op['description'], 'inputSchema' => $schema,
                'annotations' => [
                    'title' => $op['title'], 'readOnlyHint' => !$write, 'destructiveHint' => !empty($op['destructive']),
                    'idempotentHint' => !$write || str_starts_with($name, 'update_') || str_starts_with($name, 'set_'),
                    'openWorldHint' => in_array($name, ['publish_post_now', 'import_media_from_url'], true),
                ],
            ];
        }
        return $tools;
    }

    private static function rpcError($id, int $code, string $message, mixed $data = null): array
    {
        $e = ['code' => $code, 'message' => $message];
        if ($data !== null) {
            $e['data'] = $data;
        }
        return ['jsonrpc' => '2.0', 'id' => $id, 'error' => $e];
    }

    /** Decode the =?base64?…?= sentinel used in Mcp-Name / Mcp-Param-* headers. */
    public static function headerValue(?string $v): ?string
    {
        if ($v !== null && preg_match('/^=\?base64\?([A-Za-z0-9+\/=]*)\?=$/', $v, $m)) {
            $d = base64_decode($m[1], true);
            return $d === false ? null : $d;
        }
        return $v;
    }

    /**
     * Handle one HTTP POST body.
     * @param array<string,?string> $headers lower-case header names
     * @return array{0:int,1:?array} [HTTP status, JSON body or null for 202]
     */
    public function handle(mixed $msg, array $headers): array
    {
        if (!is_array($msg) || array_is_list($msg)) {
            return [400, self::rpcError(null, self::INVALID_REQUEST, 'Send one JSON-RPC message per request (batches are not supported).')];
        }
        if (($msg['jsonrpc'] ?? null) !== '2.0') {
            return [400, self::rpcError($msg['id'] ?? null, self::INVALID_REQUEST, 'jsonrpc must be "2.0".')];
        }
        // Client responses and notifications need no reply.
        if (!isset($msg['method'])) {
            return [202, null];
        }
        if (!array_key_exists('id', $msg)) {
            return [202, null];
        }
        $id = $msg['id'];
        if (!is_string($id) && !is_int($id)) {
            return [400, self::rpcError(null, self::INVALID_REQUEST, 'id must be a string or an integer.')];
        }
        $method = (string) $msg['method'];
        $params = is_array($msg['params'] ?? null) ? $msg['params'] : [];
        $meta = is_array($params['_meta'] ?? null) ? $params['_meta'] : [];
        $modernVersion = $meta[self::META . 'protocolVersion'] ?? null;

        if ($modernVersion !== null && $method !== 'initialize') {
            return $this->modern($id, $method, $params, (string) $modernVersion, $headers);
        }
        return $this->legacy($id, $method, $params, $headers);
    }

    // ---------------------------------------------------------------- modern (2026-07-28)

    private function modern($id, string $method, array $params, string $version, array $headers): array
    {
        if (!in_array($version, self::MODERN, true)) {
            return [400, self::rpcError($id, self::UNSUPPORTED_VERSION, 'Unsupported protocol version', ['supported' => self::supportedVersions(), 'requested' => $version])];
        }
        $hv = $headers['mcp-protocol-version'] ?? null;
        if ($hv === null || $hv !== $version) {
            return [400, self::rpcError($id, self::HEADER_MISMATCH, 'Header mismatch: MCP-Protocol-Version must be sent and equal the version in _meta.')];
        }
        if (($headers['mcp-method'] ?? null) !== $method) {
            return [400, self::rpcError($id, self::HEADER_MISMATCH, 'Header mismatch: Mcp-Method must be sent and equal the request method.')];
        }
        if (in_array($method, ['tools/call', 'resources/read', 'prompts/get'], true)) {
            $bodyName = (string) ($params['name'] ?? $params['uri'] ?? '');
            if (self::headerValue($headers['mcp-name'] ?? null) !== $bodyName) {
                return [400, self::rpcError($id, self::HEADER_MISMATCH, 'Header mismatch: Mcp-Name must be sent and equal params.name.')];
            }
        }
        $result = match ($method) {
            'server/discover' => [
                'supportedVersions' => self::supportedVersions(), 'capabilities' => ['tools' => (object) []],
                'instructions' => self::instructions(), 'ttlMs' => 3600000, 'cacheScope' => 'private',
            ],
            'tools/list' => ['tools' => self::tools(), 'ttlMs' => 300000, 'cacheScope' => 'private'],
            'tools/call' => $this->callTool($params),
            default => null,
        };
        if ($result === null) {
            return [404, self::rpcError($id, self::METHOD_NOT_FOUND, 'Method not found: ' . $method)];
        }
        if (isset($result['__error'])) {
            return [200, self::rpcError($id, $result['__error'][0], $result['__error'][1])];
        }
        $result = ['resultType' => 'complete'] + $result;
        $result['_meta'] = [self::META . 'serverInfo' => self::serverInfo()];
        return [200, ['jsonrpc' => '2.0', 'id' => $id, 'result' => $result]];
    }

    // ---------------------------------------------------------------- legacy (initialize handshake)

    private function legacy($id, string $method, array $params, array $headers): array
    {
        if ($method === 'initialize') {
            $asked = (string) ($params['protocolVersion'] ?? '');
            return [200, ['jsonrpc' => '2.0', 'id' => $id, 'result' => [
                'protocolVersion' => in_array($asked, self::LEGACY, true) ? $asked : self::LEGACY[0],
                'capabilities' => ['tools' => ['listChanged' => false]],
                'serverInfo' => self::serverInfo(),
                'instructions' => self::instructions(),
            ]]];
        }
        $hv = $headers['mcp-protocol-version'] ?? null;
        if ($hv !== null && !in_array($hv, self::LEGACY, true)) {
            return [400, self::rpcError($id, self::INVALID_REQUEST, 'Unsupported MCP-Protocol-Version. Supported: ' . implode(', ', self::supportedVersions()) . '.')];
        }
        $result = match ($method) {
            'ping' => (object) [],
            'tools/list' => ['tools' => self::tools()],
            'tools/call' => $this->callTool($params),
            default => null,
        };
        if ($result === null) {
            return [200, self::rpcError($id, self::METHOD_NOT_FOUND, 'Method not found: ' . $method)];
        }
        if (is_array($result) && isset($result['__error'])) {
            return [200, self::rpcError($id, $result['__error'][0], $result['__error'][1])];
        }
        return [200, ['jsonrpc' => '2.0', 'id' => $id, 'result' => $result]];
    }

    // ---------------------------------------------------------------- tools/call

    private function callTool(array $params): array
    {
        $name = $params['name'] ?? null;
        if (!is_string($name) || !isset(AgentApi::operations()[$name])) {
            return ['__error' => [self::INVALID_PARAMS, 'Unknown tool: ' . (is_string($name) ? $name : '(none)')]];
        }
        $args = $params['arguments'] ?? [];
        if ($args instanceof \stdClass) {
            $args = (array) $args;
        }
        if (!is_array($args)) {
            return ['__error' => [self::INVALID_PARAMS, 'arguments must be an object.']];
        }
        try {
            $data = (new AgentApi($this->db, $this->ctx, 'mcp'))->call($name, $args);
            $json = (string) json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
            return ['content' => [['type' => 'text', 'text' => $json]], 'structuredContent' => $data, 'isError' => false];
        } catch (ApiError $e) {
            // Tool execution errors go back to the model so it can correct itself.
            $err = ['error' => $e->errorCode ?? 'error', 'message' => $e->getMessage()];
            if ($e->details) {
                $err['details'] = $e->details;
            }
            return ['content' => [['type' => 'text', 'text' => (string) json_encode($err, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)]], 'structuredContent' => $err, 'isError' => true];
        }
    }
}
