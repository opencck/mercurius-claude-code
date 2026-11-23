<?php

declare(strict_types=1);

namespace Mercurius\ClaudeCode;

use Amp\Process\Process;
use Amp\TimeoutCancellation;
use SensitiveParameter;
use Throwable;

use function Amp\async;
use function Amp\ByteStream\buffer;
use function Amp\File\createDirectoryRecursively;
use function Amp\File\isDirectory;

/**
 * The usage of the plan, asked from the CLI without a model call. The CLI fetches the limits with the sign-in it
 * holds, so the service never reads or sends that sign-in; the CLI runs behind a permit of the relay nobody uses —
 * a request to a model could not leave even if the CLI made one.
 *
 * spec: brief/models.md#лимиты-подписки-и-переход-на-модель-категории
 * ref: references/hermes-official-plugins/claude-subscription-directsdk/directsdk_setup.py@269ddcb:L594-L630
 *      (_ask_usage) — the control request get_usage on the input of the CLI, the same command line, the answer in
 *      control_response; the switch of nonessential traffic is left out for this run, since with it the CLI skips
 *      the very fetch that is asked for
 */
final class Usage {
    private const REQUEST_ID = 'mercurius-usage';
    private const SECONDS = 15.0;

    /** @param non-empty-string $workDir the folder the CLI runs in */
    public function __construct(
        private readonly ClaudeCli $cli,
        private readonly Relay $relay,
        private readonly string $workDir,
    ) {}

    /**
     * @return array{?array<array-key, mixed>, string} the limits of the plan, or null and why not
     */
    public function fetch(#[SensitiveParameter] ?string $oauthToken): array {
        $admission = new Admission(new ToolNames());
        $process = null;
        try {
            if (!isDirectory($this->workDir)) {
                createDirectoryRecursively($this->workDir, 0700);
            }
            $process = Process::start([
                ...$this->cli->command(),
                '-p', '--input-format', 'stream-json', '--output-format', 'stream-json', '--verbose',
                '--tools', '', '--setting-sources', '', '--strict-mcp-config', '--mcp-config', '{"mcpServers":{}}',
                '--disable-slash-commands', '--no-session-persistence',
            ], $this->workDir, $this->cli->env()->probe($oauthToken, $this->relay->admit($admission)));
            $stdin = $process->getStdin();
            $stdin->write(json_encode(['type' => 'control_request', 'request_id' => self::REQUEST_ID, 'request' => ['subtype' => 'get_usage', 'skip_behaviors' => true]], JSON_THROW_ON_ERROR) . "\n");
            $stdin->end();
            $wait = new TimeoutCancellation(self::SECONDS);
            $stderr = async(static fn(): string => buffer($process->getStderr(), $wait));
            $out = buffer($process->getStdout(), $wait);
            $stderr->ignore();
        } catch (RefusedEnvironment $e) {
            return [null, $e->getMessage()];
        } catch (Throwable) {
            return [null, 'Claude Code did not answer about the usage in time'];
        } finally {
            try {
                if ($process !== null && $process->isRunning()) {
                    $process->kill();
                }
            } catch (Throwable) {
                // it exited between the check and the signal
            }
            $this->relay->release($admission);
        }
        foreach (explode("\n", $out) as $line) {
            /** @var mixed $row */
            $row = str_starts_with($line, '{') ? json_decode($line, true) : null;
            /** @var mixed $response */
            $response = is_array($row) && ($row['type'] ?? null) === 'control_response' ? ($row['response'] ?? null) : null;
            if (!is_array($response) || ($response['request_id'] ?? null) !== self::REQUEST_ID) {
                continue;
            }
            if (($response['subtype'] ?? null) !== 'success') {
                return [null, 'Claude Code refused the usage request'];
            }
            /** @var mixed $body */
            $body = $response['response'] ?? null;
            /** @var mixed $limits */
            $limits = is_array($body) ? ($body['rate_limits'] ?? null) : null;
            if (is_array($limits)) {
                return [$limits, ''];
            }
            return [null, is_array($body) && ($body['rate_limits_available'] ?? false) === true ? 'Claude Code returned no plan limits' : 'no sign-in to a Claude subscription'];
        }
        return [null, 'Claude Code gave no usage answer'];
    }
}
