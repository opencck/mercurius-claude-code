<?php

declare(strict_types=1);

namespace Mercurius\ClaudeCode;

use SensitiveParameter;

/**
 * The environment a Claude Code child gets: a short list of what it needs, nothing else of the service's own. A key of
 * the API never reaches it — the CLI would sign in with it and bill API usage instead of the subscription; an inherited
 * address of the API or a switch to a cloud backend stops the service instead of being dropped silently.
 *
 * spec: brief/models.md#вход-в-подписку-claude
 * ref: references/hermes-official-plugins/claude-subscription-directsdk/directsdk_setup.py@269ddcb:L129-L160
 *      (QUIET_TRAFFIC, ALWAYS_QUIET, CALLER_IDENTITY, BACKEND_OVERRIDES, BACKEND_SWITCHES) — the same switches; here the
 *      environment is built from a whitelist instead of a copy with keys removed, and the caller is named mercurius
 * ref: references/hermes-official-plugins/claude-subscription-directsdk/directsdk.py@269ddcb:L640-L665
 *      (_with_login_identity) — without USER a signed-in CLI answers loggedIn: false
 */
final class ChildEnv {
    /** what a process needs to start and to find its files; Windows names are for the tests of developers */
    private const PASSED = ['PATH', 'LANG', 'LC_ALL', 'TZ', 'TMPDIR', 'TEMP', 'TMP', 'SystemRoot', 'SYSTEMROOT', 'PATHEXT', 'COMSPEC', 'NODE_EXTRA_CA_CERTS'];
    /** an address or a backend the service did not choose: refused, not dropped */
    private const REFUSED_SET = ['ANTHROPIC_BASE_URL'];
    private const REFUSED_SWITCHES = ['CLAUDE_CODE_USE_BEDROCK', 'CLAUDE_CODE_USE_VERTEX', 'CLAUDE_CODE_USE_FOUNDRY'];
    private const OFF = ['', '0', 'false', 'no', 'off'];
    /** what a kind of call may add: where the CLI sends its requests and through what */
    private const EXTRA = ['ANTHROPIC_BASE_URL', 'HTTPS_PROXY', 'HTTP_PROXY', 'NO_PROXY', 'CLAUDE_CODE_MAX_OUTPUT_TOKENS', 'CLAUDE_CODE_PROMPT_CACHE_TTL'];
    /** no self-update, no feedback upload, no session title request, no telemetry; the caller's own name */
    private const FIXED = [
        'DISABLE_AUTOUPDATER' => '1',
        'DISABLE_FEEDBACK_COMMAND' => '1',
        'CLAUDE_CODE_DISABLE_TERMINAL_TITLE' => '1',
        'CLAUDE_CODE_DISABLE_NONESSENTIAL_TRAFFIC' => '1',
        'DISABLE_TELEMETRY' => '1',
        'DISABLE_ERROR_REPORTING' => '1',
        'CLAUDE_CODE_ENTRYPOINT' => 'mercurius',
        'CLAUDE_CODE_DISABLE_ARTIFACT' => '1',
    ];

    /**
     * A model call: one request and nothing of the CLI's own agent — no search of tools, no repeats (the relay would
     * refuse them), no compaction, no reminders in the history, no memory and no instruction files of the CLI.
     */
    private const CALL = [
        'ENABLE_TOOL_SEARCH' => 'false',
        'CLAUDE_CODE_MAX_RETRIES' => '0',
        'DISABLE_AUTO_COMPACT' => '1',
        'DISABLE_COMPACT' => '1',
        'CLAUDE_CODE_TOTAL_TOKENS_REMINDER' => 'off',
        'CLAUDE_CODE_DISABLE_AUTO_MEMORY' => '1',
        'CLAUDE_CODE_DISABLE_CLAUDE_MDS' => '1',
    ];

    /**
     * @param array<string, string> $parent the environment of the service
     * @param non-empty-string $configDir where the CLI keeps its sign-in
     */
    public function __construct(
        private readonly array $parent,
        private readonly string $configDir,
    ) {}

    /**
     * @param ?string $oauthToken the token of `claude setup-token` the application sent with this request; never kept
     * @param array<string, string> $extra what one kind of call adds (the address of the relay, a proxy)
     * @return array<string, string>
     * @throws RefusedEnvironment
     */
    public function build(#[SensitiveParameter] ?string $oauthToken, array $extra = []): array {
        foreach (self::REFUSED_SET as $name) {
            if (trim($this->parent[$name] ?? '') !== '') {
                throw new RefusedEnvironment(sprintf('%s is set in the environment of the service: Claude Code would talk to another address. Remove it.', $name));
            }
        }
        foreach (self::REFUSED_SWITCHES as $name) {
            if (!in_array(strtolower(trim($this->parent[$name] ?? '')), self::OFF, true)) {
                throw new RefusedEnvironment(sprintf('%s is on in the environment of the service: Claude Code would use a cloud backend instead of the subscription. Remove it.', $name));
            }
        }
        foreach (array_keys($extra) as $name) {
            if (!in_array($name, self::EXTRA, true)) {
                throw new RefusedEnvironment(sprintf('%s is not a variable a call may add to the environment of Claude Code.', $name));
            }
        }
        $env = [];
        foreach (self::PASSED as $name) {
            if (isset($this->parent[$name]) && $this->parent[$name] !== '') {
                $env[$name] = $this->parent[$name];
            }
        }
        $user = trim($this->parent['USER'] ?? $this->parent['LOGNAME'] ?? '') ?: (get_current_user() ?: 'claude');
        $env['USER'] = $user;
        $env['LOGNAME'] = $user;
        $env['HOME'] = trim($this->parent['HOME'] ?? '') ?: $this->configDir;
        $env['CLAUDE_CONFIG_DIR'] = $this->configDir;
        if ($oauthToken !== null && $oauthToken !== '') {
            $env['CLAUDE_CODE_OAUTH_TOKEN'] = $oauthToken;
        }
        return [...$env, ...$extra, ...self::FIXED];
    }

    /**
     * The environment of a model call: the relay is the only address of the API the child knows.
     *
     * ref: references/hermes-official-plugins/claude-subscription-directsdk/directsdk.py@269ddcb:L1032-L1045 — the
     *      switches of a call; the limit of the answer goes by the CLI's own variable (L1054-L1058)
     *
     * @param string $relay the address of the relay with the route of this call
     * @return array<string, string>
     * @throws RefusedEnvironment
     */
    public function call(#[SensitiveParameter] ?string $oauthToken, string $relay, ?int $maxTokens): array {
        $extra = ['ANTHROPIC_BASE_URL' => $relay];
        if ($maxTokens !== null) {
            $extra['CLAUDE_CODE_MAX_OUTPUT_TOKENS'] = (string) $maxTokens;
        }
        return [...$this->build($oauthToken, $extra), ...self::CALL];
    }
}
