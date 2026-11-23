<?php

declare(strict_types=1);

/**
 * A stand-in for the Claude Code CLI in tests: the commands the service runs, with the same output shapes as the real
 * CLI (checked on 2.1.330). Its state is a file in CLAUDE_CONFIG_DIR; each run also writes the environment it got there.
 */

$args = array_slice($argv, 1);
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
fwrite(STDERR, "fake-claude: unknown command\n");
exit(2);
