<?php

declare(strict_types=1);

/**
 * A stand-in for the Claude Code CLI in tests: the commands the service runs, with the same output shapes as the real
 * CLI (checked on 2.1.330). Its state is a file in CLAUDE_CONFIG_DIR; each run also writes the environment it got there.
 */

$args = [];
/** @var mixed $given */
$given = $_SERVER['argv'] ?? null;
/** @var mixed $arg */
foreach (array_slice(is_array($given) ? $given : [], 1) as $arg) {
    if (is_string($arg)) {
        $args[] = $arg;
    }
}
$dir = (string) getenv('CLAUDE_CONFIG_DIR');
if ($dir !== '' && is_dir($dir)) {
    file_put_contents($dir . '/env.json', json_encode(getenv(), JSON_THROW_ON_ERROR));
}
$login = $dir . '/login.json';

if ($args === ['--version']) {
    echo (is_file($dir . '/version') ? trim((string) file_get_contents($dir . '/version')) : '2.1.330') . " (Claude Code)\n";
    exit(0);
}
if ($args === ['auth', 'status']) {
    $method = 'none';
    if (getenv('ANTHROPIC_API_KEY') !== false) {
        $method = 'api_key';
    } elseif (getenv('CLAUDE_CODE_OAUTH_TOKEN') !== false) {
        $method = 'oauth_token';
    } elseif (is_file($login)) {
        $method = 'claude.ai';
    }
    $status = ['loggedIn' => $method !== 'none', 'authMethod' => $method, 'apiProvider' => 'firstParty'];
    if ($method === 'claude.ai' || $method === 'oauth_token') {
        $status['subscriptionType'] = 'max';
    }
    echo json_encode($status, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT), "\n";
    exit($method === 'none' ? 1 : 0);
}
if (($args[0] ?? '') === 'auth' && ($args[1] ?? '') === 'login') {
    echo "Opening browser to sign in…\nIf the browser didn't open, visit:\nhttps://claude.ai/oauth/authorize?code=true&client_id=fake&state=fake-state\nPaste code here if prompted > ";
    $code = trim((string) fgets(STDIN));
    if ($code === 'good-code') {
        file_put_contents($login, '{}');
        echo "\nLogin successful.\n";
        exit(0);
    }
    echo "\nLogin failed: invalid code\n";
    exit(1);
}
if ($args === ['auth', 'logout']) {
    @unlink($login);
    echo "Successfully logged out from your Anthropic account.\n";
    exit(0);
}
if (in_array('-p', $args, true)) {
    // A model call: the frames of the history on stdin, events on stdout, one request to ANTHROPIC_BASE_URL for the
    // queried turn. The file `mode` in CLAUDE_CONFIG_DIR picks a way to misbehave.
    $option = static function (string $name) use ($args): string {
        $at = array_search($name, $args, true);
        return $at === false ? '' : ($args[$at + 1] ?? '');
    };
    $emit = static function (array $event): void {
        echo json_encode($event, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), "\n";
        flush();
    };
    $mode = is_file($dir . '/mode') ? trim((string) file_get_contents($dir . '/mode')) : 'ok';
    if ($option('--settings') === '') {
        // a question about the plan, without a model call: a control request on the input, its answer on the output.
        // The file `usage.json` is what the plan says; without it a window is far from full and extra usage is off.
        /** @var mixed $asked */
        $asked = json_decode((string) fgets(STDIN), true, 16, JSON_THROW_ON_ERROR);
        /** @var mixed $id */
        $id = is_array($asked) ? ($asked['request_id'] ?? '') : '';
        file_put_contents($dir . '/usage-env.json', json_encode(getenv(), JSON_THROW_ON_ERROR));
        file_put_contents($dir . '/usage-count', (string) ((int) @file_get_contents($dir . '/usage-count') + 1));
        /** @var mixed $limits */
        $limits = is_file($dir . '/usage.json')
            ? json_decode((string) file_get_contents($dir . '/usage.json'), true, 16, JSON_THROW_ON_ERROR)
            : ['limits' => [['kind' => 'session', 'percent' => 12, 'resets_at' => '2030-01-01T00:00:00Z']], 'extra_usage' => ['is_enabled' => false]];
        $emit(['type' => 'system', 'subtype' => 'init']);
        $emit(['type' => 'control_response', 'response' => ['request_id' => $id, 'subtype' => 'success', 'response' => ['rate_limits' => $limits, 'rate_limits_available' => true]]]);
        exit(0);
    }
    file_put_contents($dir . '/args.json', json_encode($args, JSON_THROW_ON_ERROR));
    $system = (string) file_get_contents($option('--system-prompt-file'));
    /** @var array{env: array{CLAUDE_CODE_EXTRA_BODY: string}} $settings */
    $settings = json_decode((string) file_get_contents($option('--settings')), true, 16, JSON_THROW_ON_ERROR);
    /** @var stdClass $extra */
    $extra = json_decode($settings['env']['CLAUDE_CODE_EXTRA_BODY'], false, 512, JSON_THROW_ON_ERROR);
    /** @var array{mcpServers: array<string, array{command: string, args: list<string>}>} $mcp */
    $mcp = json_decode($option('--mcp-config'), true, 16, JSON_THROW_ON_ERROR);
    foreach ($mcp['mcpServers'] as $server) {
        // the CLI asks a server for its tools at the start; the fake also tries to call one
        $command = [$server['command'], ...$server['args']];
        $helper = proc_open($command, [['pipe', 'r'], ['pipe', 'w'], STDERR], $pipes);
        if ($helper === false) {
            continue;
        }
        $answers = [];
        foreach ([['id' => 1, 'method' => 'initialize'], ['method' => 'notifications/initialized'], ['id' => 2, 'method' => 'tools/list'], ['id' => 3, 'method' => 'tools/call', 'params' => ['name' => 'x']]] as $row) {
            fwrite($pipes[0], json_encode(['jsonrpc' => '2.0'] + $row, JSON_THROW_ON_ERROR) . "\n");
            if (isset($row['id'])) {
                /** @var mixed $answer */
                $answer = json_decode((string) fgets($pipes[1]), true, 64, JSON_THROW_ON_ERROR);
                array_push($answers, $answer);
            }
        }
        fclose($pipes[0]);
        fclose($pipes[1]);
        proc_close($helper);
        file_put_contents($dir . '/mcp.json', json_encode($answers, JSON_THROW_ON_ERROR));
    }
    $emit(['type' => 'system', 'subtype' => 'init', 'session_id' => $option('--session-id')]);
    if ($mode === 'garbage') {
        // a wrapper around the CLI that prints its own line and gives up
        echo "Welcome to a shell that prints a banner\n";
        flush();
        exit(0);
    }
    if ($mode === 'silent') {
        sleep(60);
    }
    if ($mode === 'slow') {
        usleep(1_500_000);
    }
    $post = static function (object $body) use ($emit): int {
        $context = stream_context_create(['http' => [
            'method' => 'POST',
            'header' => "content-type: application/json\r\nauthorization: Bearer fake-oauth-token\r\nanthropic-beta: oauth-2025-04-20\r\nx-app: cli\r\nconnection: close",
            'content' => json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            'ignore_errors' => true,
            'timeout' => 20,
        ]]);
        $stream = @fopen((string) getenv('ANTHROPIC_BASE_URL') . '/v1/messages?beta=true', 'r', false, $context);
        if ($stream === false) {
            return 0;
        }
        $status = (int) (explode(' ', $http_response_header[0] ?? '')[1] ?? 0);
        while (($line = fgets($stream)) !== false) {
            if ($status === 200 && str_starts_with($line, 'data:')) {
                $emit(['type' => 'stream_event', 'event' => json_decode(trim(substr($line, 5)), false, 512, JSON_THROW_ON_ERROR)]);
            }
        }
        fclose($stream);
        return $status;
    };
    $messages = [];
    while (($line = fgets(STDIN)) !== false) {
        /** @var mixed $frame */
        $frame = json_decode($line, false, 512, JSON_THROW_ON_ERROR);
        if (!$frame instanceof stdClass) {
            continue;
        }
        /** @var mixed $message */
        $message = $frame->message ?? null;
        array_push($messages, $message);
        if (($frame->shouldQuery ?? true) === false) {
            $emit(['type' => 'result', 'subtype' => 'success', 'is_error' => false, 'num_turns' => 0]);
            continue;
        }
        if (($frame->type ?? null) !== 'user') {
            continue;
        }
        if (is_file($dir . '/rate-limit.json')) {
            // the CLI says in its output how the windows of the plan stand
            /** @var mixed $info */
            $info = json_decode((string) file_get_contents($dir . '/rate-limit.json'), true, 16, JSON_THROW_ON_ERROR);
            $emit(['type' => 'rate_limit_event', 'rate_limit_info' => $info]);
        }
        if ($mode === 'limit') {
            // a used-up window: the CLI refuses by itself and sends nothing
            $emit(['type' => 'assistant', 'error' => 'rate_limit', 'message' => ['content' => [['type' => 'text', 'text' => "You've hit your session limit"]]]]);
            $emit(['type' => 'result', 'subtype' => 'success', 'is_error' => true, 'num_turns' => 1]);
            exit(1);
        }
        if ($mode === 'logged-out') {
            $emit(['type' => 'assistant', 'error' => 'authentication_failed', 'message' => ['content' => [['type' => 'text', 'text' => 'Not logged in · Please run /login']]]]);
            $emit(['type' => 'result', 'subtype' => 'success', 'is_error' => true, 'num_turns' => 1]);
            exit(1);
        }
        $body = (object) ([
            'model' => $option('--model'),
            'max_tokens' => (int) getenv('CLAUDE_CODE_MAX_OUTPUT_TOKENS'),
            'system' => $system,
            'messages' => $messages,
            'stream' => true,
        ] + get_object_vars($extra));
        $status = $post($body);
        if ($status === 401 || ($status !== 200 && $mode === 'retry')) {
            // the real CLI renews a refused sign-in and repeats; `retry` repeats after any failure
            $status = $post($body);
        }
        if ($status !== 200) {
            $emit(['type' => 'assistant', 'error' => $status === 429 ? 'rate_limit' : 'unknown', 'message' => ['content' => [['type' => 'text', 'text' => 'API Error: ' . $status]]]]);
            $emit(['type' => 'result', 'subtype' => 'success', 'is_error' => true, 'num_turns' => 1]);
            exit(1);
        }
        $emit(['type' => 'result', 'subtype' => 'success', 'is_error' => false, 'num_turns' => 1]);
        exit(0);
    }
    exit(0);
}
fwrite(STDERR, "fake-claude: unknown command\n");
exit(2);
