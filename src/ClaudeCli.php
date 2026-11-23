<?php

declare(strict_types=1);

namespace Mercurius\ClaudeCode;

use Amp\ByteStream\ReadableStream;
use Amp\Cancellation;
use Amp\CancelledException;
use Amp\Future;
use Amp\Process\Process;
use Amp\TimeoutCancellation;
use SensitiveParameter;
use Throwable;

use function Amp\async;
use function Amp\ByteStream\buffer;

/**
 * The official Claude Code CLI as the service runs it: found, of which version, and how it would sign in. The CLI is
 * never changed and its sign-in files are never read: every answer here is the CLI's own.
 *
 * spec: brief/models.md#claude-по-подписке
 * ref: references/hermes-official-plugins/claude-subscription-directsdk/directsdk_setup.py@269ddcb:L424-L453
 *      (setup_status) — `auth status` as JSON: loggedIn, authMethod, apiProvider, subscriptionType; a sign-in by an API
 *      key or a cloud backend is "logged in" for the CLI and is not the subscription for us (L49-L53, _METERED_AUTH)
 */
final class ClaudeCli {
    private const PROBE_SECONDS = 20.0;
    private const METERED = ['api_key', 'api_key_helper', 'third_party'];

    /**
     * @param non-empty-list<string> $command the CLI and its fixed arguments
     * @param non-empty-string $minVersion the oldest CLI the service works with
     */
    public function __construct(
        private readonly array $command,
        private readonly ChildEnv $env,
        private readonly string $minVersion,
    ) {}

    /** @return non-empty-list<string> */
    public function command(): array {
        return $this->command;
    }

    public function env(): ChildEnv {
        return $this->env;
    }

    /** The version the CLI names; null when it is not found or does not answer. */
    public function version(): ?string {
        try {
            [$code, $out] = $this->run(['--version'], null);
        } catch (Throwable) {
            return null;
        }
        return $code === 0 && preg_match('~\d+\.\d+\.\d+~', $out, $m) === 1 ? $m[0] : null;
    }

    /**
     * @param ?string $oauthToken the token the application sent with the request: the status says how that request
     *        would sign in
     */
    public function status(#[SensitiveParameter] ?string $oauthToken): CliStatus {
        $version = $this->version();
        if ($version === null) {
            return new CliStatus(null, false, CliStatus::NONE, '', 'Claude Code is not found in the image of the service.');
        }
        $ok = version_compare($version, $this->minVersion, '>=');
        if (!$ok) {
            return new CliStatus($version, false, CliStatus::NONE, '', sprintf('Claude Code %s is older than %s the service needs.', $version, $this->minVersion));
        }
        try {
            [, $out] = $this->run(['auth', 'status'], $oauthToken);
        } catch (RefusedEnvironment $e) {
            return new CliStatus($version, true, CliStatus::NONE, '', $e->getMessage());
        } catch (Throwable) {
            return new CliStatus($version, true, CliStatus::NONE, '', 'Claude Code did not answer about its sign-in.');
        }
        /** @var mixed $auth */
        $auth = str_starts_with(ltrim($out), '{') ? json_decode($out, true) : null;
        if (!is_array($auth) || ($auth['loggedIn'] ?? false) !== true) {
            return new CliStatus($version, true, CliStatus::NONE, '', 'Nobody is signed in to Claude.');
        }
        $method = self::text($auth, 'authMethod', '');
        $provider = self::text($auth, 'apiProvider', 'firstParty');
        $plan = self::text($auth, 'subscriptionType', '');
        if (in_array($method, self::METERED, true) || $provider !== 'firstParty') {
            return new CliStatus($version, true, CliStatus::METERED, '', 'Claude Code would sign in with an API key or a cloud backend, which bills API usage instead of the subscription.');
        }
        return new CliStatus($version, true, $method === 'oauth_token' ? CliStatus::TOKEN : CliStatus::LOGIN, $plan);
    }

    /** @param array<array-key, mixed> $data */
    private static function text(array $data, string $key, string $default): string {
        /** @var mixed $value */
        $value = $data[$key] ?? null;
        return is_string($value) ? $value : $default;
    }

    /** Removes the sign-in the CLI keeps. */
    public function logout(): void {
        try {
            $this->run(['auth', 'logout'], null);
        } catch (Throwable) {
            // nothing to remove, or the CLI is not there: the status says what is left
        }
    }

    /**
     * @param list<string> $args
     * @return array{int, string} the exit code and what it printed
     * @throws RefusedEnvironment
     */
    private function run(array $args, #[SensitiveParameter] ?string $oauthToken): array {
        $process = Process::start([...$this->command, ...$args], null, $this->env->build($oauthToken));
        $process->getStdin()->close();
        $cancellation = new TimeoutCancellation(self::PROBE_SECONDS);
        try {
            $stderr = async(self::read(...), $process->getStderr(), $cancellation);
            $out = self::read($process->getStdout(), $cancellation);
            $code = $process->join($cancellation);
            self::settle($stderr);
        } catch (CancelledException $e) {
            $process->kill();
            throw $e;
        }
        return [$code, $out];
    }

    /** @param Future<mixed> $future */
    private static function settle(Future $future): void {
        try {
            $future->await();
        } catch (Throwable) {
            // what the CLI wrote to stderr is not needed for a probe
        }
    }

    private static function read(ReadableStream $stream, Cancellation $cancellation): string {
        return buffer($stream, $cancellation);
    }
}
