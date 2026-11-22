<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Env;
use App\Core\Logger;
use App\Core\Response;
use App\Services\ApiAuth;
use App\Services\McpServer;

/** /mcp endpoint (Streamable HTTP). POST only: no SSE stream, no sessions. */
final class McpController extends Controller
{
    private const HEADERS = ['mcp-protocol-version', 'mcp-method', 'mcp-name', 'mcp-session-id', 'origin'];

    /** Browsers send Origin; only allow ones that are well-formed and, if configured, on the allow-list. */
    private function originAllowed(?string $origin): bool
    {
        if ($origin === null || $origin === '') {
            return true;
        }
        if (!preg_match('#^https?://[a-z0-9.\-\[\]:]+$#i', $origin)) {
            return false;
        }
        $self = parse_url(abs_url('/'));
        $own = ($self['scheme'] ?? 'https') . '://' . ($self['host'] ?? '') . (isset($self['port']) ? ':' . $self['port'] : '');
        $list = array_filter(array_map('trim', explode(',', (string) Env::get('MCP_ALLOWED_ORIGINS', ''))));
        return strcasecmp($origin, $own) === 0 || !$list || in_array(strtolower($origin), array_map('strtolower', $list), true);
    }

    public function handle(): Response
    {
        if (!$this->originAllowed($this->req->header('origin'))) {
            return Response::json(['jsonrpc' => '2.0', 'id' => null, 'error' => ['code' => -32600, 'message' => 'Origin not allowed.']], 403);
        }
        // 401 (with WWW-Authenticate → OAuth discovery) is thrown before anything else is processed.
        $ctx = (new ApiAuth($this->db))->resolve($this->req, abs_url('/.well-known/oauth-protected-resource/mcp'));
        $raw = (string) file_get_contents('php://input');
        if (strlen($raw) > 25 * 1024 * 1024) {
            return Response::json(['jsonrpc' => '2.0', 'id' => null, 'error' => ['code' => -32600, 'message' => 'Request too large.']], 413);
        }
        $msg = json_decode($raw, true);
        if ($msg === null && json_last_error() !== JSON_ERROR_NONE) {
            return Response::json(['jsonrpc' => '2.0', 'id' => null, 'error' => ['code' => McpServer::PARSE_ERROR, 'message' => 'Parse error: the body is not valid JSON.']], 400);
        }
        $headers = [];
        foreach (self::HEADERS as $h) {
            $headers[$h] = $this->req->header($h);
        }
        try {
            [$status, $body] = (new McpServer($this->db, $ctx))->handle($msg, $headers);
        } catch (\Throwable $e) {
            Logger::error($e, ['request_id' => request_id(), 'path' => '/mcp']);
            $status = 200;
            $body = ['jsonrpc' => '2.0', 'id' => is_array($msg) ? ($msg['id'] ?? null) : null, 'error' => ['code' => -32603, 'message' => 'Internal error (reference ' . request_id() . ').']];
        }
        if ($body === null) {
            return new Response('', $status);
        }
        $res = Response::json($body, $status);
        $res->headers['Cache-Control'] = 'no-store';
        return $res;
    }

    /** No server-initiated stream and no sessions to end. */
    public function notAllowed(): Response
    {
        $r = Response::json(['jsonrpc' => '2.0', 'id' => null, 'error' => ['code' => -32600, 'message' => 'Use POST. This server has no event stream and no sessions.']], 405);
        $r->headers['Allow'] = 'POST, OPTIONS';
        return $r;
    }
}
