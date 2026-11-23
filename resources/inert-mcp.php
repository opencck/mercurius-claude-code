<?php

declare(strict_types=1);

/**
 * The inert MCP server Claude Code starts for a model call: it only lists the tools of the application, so the model
 * is offered them under the CLI's own names. It runs nothing: a call of a tool is answered with a refusal — only the
 * application runs tools. It reads the list from the file named in the first argument and speaks JSON-RPC over
 * stdin and stdout, one message per line; it ends when the CLI closes its input.
 *
 * spec: brief/models.md#claude-по-подписке
 * ref: references/hermes-official-plugins/claude-subscription-directsdk/inert_mcp.py@269ddcb:L1-L24 — the same three
 *      methods and answers, in PHP: the image of the service has no Python
 */

$manifest = json_decode((string) file_get_contents((string) ($argv[1] ?? '')), false, 512, JSON_THROW_ON_ERROR);
while (($line = fgets(STDIN)) !== false) {
    $row = json_decode($line, true);
    if (!is_array($row)) {
        continue;
    }
    $result = match ($row['method'] ?? null) {
        'initialize' => ['protocolVersion' => '2024-11-05', 'capabilities' => ['tools' => new stdClass()], 'serverInfo' => ['name' => 'mercurius-inert-inventory', 'version' => '1']],
        'tools/list' => ['tools' => $manifest],
        'tools/call' => ['isError' => true, 'content' => [['type' => 'text', 'text' => 'Denied: the tools of Claude Code are inert; only the application runs tools.']]],
        default => new stdClass(),
    };
    if (array_key_exists('id', $row)) {
        fwrite(STDOUT, json_encode(['jsonrpc' => '2.0', 'id' => $row['id'], 'result' => $result], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
        fflush(STDOUT);
    }
}
