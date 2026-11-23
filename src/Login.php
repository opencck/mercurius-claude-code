<?php

declare(strict_types=1);

namespace Mercurius\ClaudeCode;

use Amp\CancelledException;
use Amp\Process\Process;
use Amp\TimeoutCancellation;
use Revolt\EventLoop;
use SensitiveParameter;
use Throwable;

use function Amp\async;
use function Amp\ByteStream\buffer;

/**
 * The owner signs in to the subscription through the service: `claude auth login` prints a link and waits for the code
 * on its input; the service hands the link out and passes the code in. The sign-in itself is the CLI's — its files are
 * not read here. One sign-in at a time; a code that does not come in time ends the process.
 *
 * Checked on Claude Code 2.1.330: without a terminal the CLI prints the link to stdout and reads the code from stdin.
 *
 * spec: brief/models.md#вход-в-подписку-claude
 * ref: references/hermes-official-plugins/claude-subscription-directsdk/directsdk_setup.py@269ddcb:L46-L57 (LOGIN_HINT)
 *      — the sign-in is `claude auth login`; the plugin leaves it to a person at a terminal, here the service runs it
 */
final class Login {
    /** seconds the owner has to bring the code */
    public const WAIT = 900.0;
    private const LINK_SECONDS = 30.0;
    private const FINISH_SECONDS = 60.0;

    private ?Process $process = null;
    private ?string $url = null;
    private ?string $timer = null;
    private bool $starting = false;

    public function __construct(
        private readonly ClaudeCli $cli,
        private readonly float $wait = self::WAIT,
    ) {}

    public function pending(): bool {
        return $this->process !== null;
    }

    /**
     * Starts a sign-in, or gives the link of the one that waits.
     *
     * @return string the link the owner opens
     * @throws LoginFailed
     */
    public function start(): string {
        if ($this->process !== null && $this->url !== null && $this->url !== '') {
            return $this->url;
        }
        if ($this->starting) {
            throw new LoginFailed('A sign-in is starting already: ask again in a moment.');
        }
        $this->cancel();
        $this->starting = true;
        try {
            return $this->begin();
        } finally {
            $this->starting = false;
        }
    }

    /** @throws LoginFailed */
    private function begin(): string {
        try {
            $process = Process::start([...$this->cli->command(), 'auth', 'login', '--claudeai'], null, $this->cli->env()->build(null));
        } catch (Throwable $e) {
            throw new LoginFailed($e instanceof RefusedEnvironment ? $e->getMessage() : 'Claude Code did not start.');
        }
        // stderr is drained so the CLI never blocks on a full pipe; it is not kept: it may name the account
        async(static function () use ($process): void {
            try {
                buffer($process->getStderr());
            } catch (Throwable) {
            }
        })->ignore();
        $seen = '';
        $url = '';
        $cancellation = new TimeoutCancellation(self::LINK_SECONDS);
        try {
            while ($url === '') {
                $chunk = $process->getStdout()->read($cancellation);
                if ($chunk === null) {
                    throw new LoginFailed('Claude Code ended before it gave a link.');
                }
                $seen .= $chunk;
                if (strlen($seen) > 65_536) {
                    throw new LoginFailed('Claude Code gave no link.');
                }
                // the link is whole once something follows it: a chunk may end in the middle of it
                if (preg_match('~(https://[^\s"\'<>]+)\s~', $seen, $found) === 1) {
                    $url = $found[1];
                }
            }
        } catch (CancelledException) {
            $process->kill();
            throw new LoginFailed('Claude Code gave no link in time.');
        } catch (LoginFailed $e) {
            if ($process->isRunning()) {
                $process->kill();
            }
            throw $e;
        }
        $this->process = $process;
        $this->url = $url;
        // the timer ends its own sign-in: a later one has its own timer
        $this->timer = EventLoop::delay($this->wait, function () use ($process): void {
            if ($this->process === $process) {
                $this->timer = null;
                $this->cancel();
            } elseif ($process->isRunning()) {
                $process->kill();
            }
        });
        EventLoop::unreference($this->timer);
        return $url;
    }

    /**
     * Passes the owner's code to the waiting sign-in.
     *
     * @return bool whether the CLI took it
     * @throws LoginFailed when no sign-in waits
     */
    public function code(#[SensitiveParameter] string $code): bool {
        $process = $this->process;
        if ($process === null) {
            throw new LoginFailed('No sign-in waits for a code: start one first.');
        }
        $this->forget();
        try {
            $process->getStdin()->write(trim($code) . "\n");
            $process->getStdin()->end();
            $cancellation = new TimeoutCancellation(self::FINISH_SECONDS);
            async(static function () use ($process, $cancellation): void {
                try {
                    buffer($process->getStdout(), $cancellation);
                } catch (Throwable) {
                }
            })->ignore();
            return $process->join($cancellation) === 0;
        } catch (Throwable) {
            if ($process->isRunning()) {
                $process->kill();
            }
            return false;
        }
    }

    /** Ends a sign-in that waits, if any. */
    public function cancel(): void {
        $process = $this->process;
        $this->forget();
        if ($process !== null && $process->isRunning()) {
            $process->kill();
        }
    }

    private function forget(): void {
        if ($this->timer !== null) {
            EventLoop::cancel($this->timer);
            $this->timer = null;
        }
        $this->process = null;
        $this->url = null;
    }
}
