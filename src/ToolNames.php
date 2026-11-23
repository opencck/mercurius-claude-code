<?php

declare(strict_types=1);

namespace Mercurius\ClaudeCode;

/**
 * The names of the application's tools as Claude Code offers them, and the way back. The CLI offers a tool of an MCP
 * server under the name of the server, so every name gets the prefix; a name longer than the CLI allows gets a short
 * form with a hash. In an answer the model sometimes loses the prefix or the name of the server: the application's
 * name is restored either way.
 *
 * spec: brief/models.md#claude-по-подписке
 * ref: references/hermes-official-plugins/claude-subscription-directsdk/directsdk.py@269ddcb:L424-L447 (request_body)
 *      — names are unique ASCII identifiers; longer than 50 — the first 41 characters and 8 of the hash
 * ref: references/hermes-official-plugins/claude-subscription-directsdk/directsdk.py@269ddcb:L513-L524
 *      (offered_tool_name) — the lost prefix; a name that was not offered stays the model's
 */
final class ToolNames {
    public const SERVER = 'mercurius';
    public const PREFIX = 'mcp__' . self::SERVER . '__';
    private const LONGEST = 50;

    /** @var array<string, string> the application's name → the name in the CLI, without the prefix */
    private array $native = [];
    /** @var array<string, string> */
    private array $own = [];

    /**
     * @return string the name the CLI lists the tool under, without the prefix
     * @throws RequestRejected
     */
    public function add(string $name): string {
        if (preg_match('~^[A-Za-z0-9_-]{1,64}$~', $name) !== 1 || isset($this->native[$name])) {
            throw new RequestRejected('Tool names must be unique ASCII identifiers of at most 64 characters');
        }
        $native = strlen($name) <= self::LONGEST ? $name : substr($name, 0, 41) . '_' . substr(hash('sha256', $name), 0, 8);
        if (isset($this->own[$native])) {
            throw new RequestRejected(sprintf('Tool names %s and %s are the same for Claude Code', $name, $this->own[$native]));
        }
        $this->native[$name] = $native;
        $this->own[$native] = $name;
        return $native;
    }

    /** The name of a call in the history as the CLI knows it; a call of a tool that is not offered now stays as it was. */
    public function sent(string $name): string {
        return isset($this->native[$name]) ? self::PREFIX . $this->native[$name] : $name;
    }

    /** The application's name of a tool the model called. */
    public function restored(string $name): string {
        if (isset($this->own[$name])) {
            // a tool of the application may itself be named like a tool of a server: the exact name wins
            return $this->own[$name];
        }
        if (str_starts_with($name, self::PREFIX)) {
            $name = substr($name, strlen(self::PREFIX));
        } elseif (str_starts_with($name, 'mcp__') && isset($this->own[substr($name, 5)])) {
            $name = substr($name, 5);
        }
        return $this->own[$name] ?? $name;
    }
}
