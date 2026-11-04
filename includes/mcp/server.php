<?php
// includes/mcp/server.php
//
// MCP "Streamable HTTP" endpoint (stateless, JSON responses only, no SSE).
// POST /mcp with a JSON-RPC message; Authorization: Bearer <token from /oauth/token>.
// Methods: initialize, ping, tools/list, tools/call; notifications get 202.

require_once __DIR__ . '/oauth.php';
require_once __DIR__ . '/tools.php';

if (!function_exists('mcp_handle_request')) {

define('MCP_PROTOCOLS', ['2025-11-25', '2025-06-18', '2025-03-26', '2024-11-05']);

function mcp_rpc_send(int $status, $body): void {
    if (!headers_sent()) {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        header('X-LiteSpeed-Cache-Control: no-cache');
        header('Access-Control-Allow-Origin: *');
    }
    if ($body !== null) echo json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function mcp_rpc_error($id, int $code, string $msg): array {
    return ['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => $code, 'message' => $msg]];
}

function mcp_handle_request(PDO $pdo, string $method): void {
    if ($method === 'GET' || $method === 'DELETE') {
        header('Allow: POST');
        mcp_rpc_send(405, mcp_rpc_error(null, -32000, 'This server only accepts POST (no event stream).'));
    }
    if ($method !== 'POST') mcp_rpc_send(405, mcp_rpc_error(null, -32000, 'Use POST.'));

    $ctx = mcp_bearer($pdo);
    if (!$ctx) mcp_unauthorized();

    // Audit-log identity for everything this request changes.
    $_SESSION['user_id']   = $ctx['user_id'];
    $_SESSION['user_name'] = mb_substr($ctx['user_name'] . ' (AI: ' . $ctx['client_name'] . ')', 0, 100);
    date_default_timezone_set('America/New_York');

    $raw = (string)file_get_contents('php://input');
    $msg = json_decode($raw, true);
    if (!is_array($msg)) mcp_rpc_send(400, mcp_rpc_error(null, -32700, 'Parse error: body must be JSON.'));

    $batch = array_is_list($msg) && $msg !== [];
    $replies = [];
    foreach ($batch ? $msg : [$msg] as $m) {
        $r = mcp_dispatch($pdo, is_array($m) ? $m : [], $ctx);
        if ($r !== null) $replies[] = $r;
    }
    if (!$replies) mcp_rpc_send(202, null);           // only notifications / responses
    mcp_rpc_send(200, $batch ? $replies : $replies[0]);
}

/** Returns a JSON-RPC response, or null for notifications. */
function mcp_dispatch(PDO $pdo, array $m, array $ctx): ?array {
    $id     = $m['id'] ?? null;
    $method = (string)($m['method'] ?? '');
    $params = is_array($m['params'] ?? null) ? $m['params'] : [];
    $isNote = !array_key_exists('id', $m);

    if (($m['jsonrpc'] ?? '') !== '2.0' || $method === '') {
        return $isNote ? null : mcp_rpc_error($id, -32600, 'Invalid request.');
    }
    if ($isNote) return null;   // notifications/initialized, notifications/cancelled, ...

    try {
        switch ($method) {
            case 'initialize':
                $want = (string)($params['protocolVersion'] ?? '');
                return ['jsonrpc' => '2.0', 'id' => $id, 'result' => [
                    'protocolVersion' => in_array($want, MCP_PROTOCOLS, true) ? $want : MCP_PROTOCOLS[0],
                    'capabilities'    => ['tools' => ['listChanged' => false]],
                    'serverInfo'      => ['name' => 'bat-cave-road-farm', 'title' => 'Bat Cave Road Farm', 'version' => '1.0.0'],
                    'instructions'    => "Bat Cave Road Farm's own admin system (Old Fort, NC; Eastern time). "
                        . "You are acting for {$ctx['user_name']}, a farm administrator. "
                        . "Dates are YYYY-MM-DD. Read freely; before any change (stock, statuses, drafts, files) say what you'll change. "
                        . "Confirming a booking emails the customer. File edits change the live site (with a backup) but not GitHub. "
                        . "Never invent prices, hours or policies; look them up with the tools.",
                ]];
            case 'ping':
                return ['jsonrpc' => '2.0', 'id' => $id, 'result' => new stdClass()];
            case 'tools/list':
                return ['jsonrpc' => '2.0', 'id' => $id, 'result' => ['tools' => mcp_tools_list()]];
            case 'tools/call':
                $name = (string)($params['name'] ?? '');
                $args = is_array($params['arguments'] ?? null) ? $params['arguments'] : [];
                return ['jsonrpc' => '2.0', 'id' => $id, 'result' => mcp_tools_call($pdo, $name, $args)];
            case 'resources/list':
                return ['jsonrpc' => '2.0', 'id' => $id, 'result' => ['resources' => []]];
            case 'prompts/list':
                return ['jsonrpc' => '2.0', 'id' => $id, 'result' => ['prompts' => []]];
            default:
                return mcp_rpc_error($id, -32601, "Method not found: $method");
        }
    } catch (InvalidArgumentException $e) {
        return mcp_rpc_error($id, -32602, $e->getMessage());
    } catch (Throwable $e) {
        $ref = bin2hex(random_bytes(4));
        error_log("[MCP $ref] $method: " . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
        // Tool failures go back as a tool result so the AI can tell the user.
        if ($method === 'tools/call') {
            return ['jsonrpc' => '2.0', 'id' => $id, 'result' => ['isError' => true,
                'content' => [['type' => 'text', 'text' => 'Server error (reference ' . $ref . '). Check the admin to see whether the change went through before retrying.']]]];
        }
        return mcp_rpc_error($id, -32603, 'Server error (reference ' . $ref . ').');
    }
}

}
