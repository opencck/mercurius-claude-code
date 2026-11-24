<?php

declare(strict_types=1);

namespace Mercurius\ClaudeCode;

use Amp\ByteStream\ReadableStream;
use Amp\Cancellation;
use Amp\CancelledException;
use Amp\CompositeCancellation;
use Amp\DeferredFuture;
use Amp\Future;
use Amp\Pipeline\Queue;
use Amp\Process\Process;
use Amp\TimeoutCancellation;
use Psr\Log\LoggerInterface;
use Revolt\EventLoop;
use RuntimeException;
use stdClass;
use Throwable;

use function Amp\async;
use function Amp\File\createDirectory;
use function Amp\File\createDirectoryRecursively;
use function Amp\File\deleteDirectory;
use function Amp\File\deleteFile;
use function Amp\File\isDirectory;
use function Amp\File\listFiles;
use function Amp\File\write;

/**
 * One model call through the official Claude Code: a process of the CLI is started for the call, gets the history as
 * the frames of its input, sends its one request through the admission relay and is gone. The CLI is a transport:
 * it has no tools of its own, one turn, no session kept, no settings of a user; the tools of the application are only
 * listed to it by an inert server. The answer the application gets is the answer of the API the relay saw, not what
 * the CLI printed; the CLI's output is read for the acknowledgments of the history and for what the CLI says when
 * it sends nothing.
 *
 * spec: brief/models.md#claude-по-подписке
 * ref: references/hermes-official-plugins/claude-subscription-directsdk/directsdk.py@269ddcb:L996-L1072 (_run) — the
 *      files of a request (the system text, the settings with the added body, the list of tools), no MCP server
 *      without tools, the command line, one session per conversation. The inert server is a file of the image, not a
 *      copy per request
 * ref: references/hermes-official-plugins/claude-subscription-directsdk/directsdk.py@269ddcb:L1143-L1162 — every
 *      reply of the user but the last is sent with shouldQuery: false and waits for a result of zero turns
 * ref: references/hermes-official-plugins/claude-subscription-directsdk/directsdk.py@269ddcb:L1174-L1177 and
 *      L112-L119 (NATIVE_ERROR_STATUS) — an error the CLI answers itself and the status that stands for it
 * ref: references/hermes-official-plugins/claude-subscription-directsdk/directsdk.py@269ddcb:L476-L622
 *      (_fast_eligible, _hand_off) — the answer does not wait for the CLI to exit; the CLI gets ten seconds and is
 *      killed. Here the answer goes on as the API streams it, so there is no check of the CLI's result first
 * ref: references/hermes-official-plugins/claude-subscription-directsdk/concurrency.py@269ddcb:L1-L114 — a process
 *      of the CLI takes a quarter of a gigabyte, so calls wait in the order they came for one of a few places; a
 *      place is held until the process is gone; a call that waited too long or was cancelled never starts late.
 *      There is one limit, not a limit per profile, and no heartbeat: the application waits on the connection
 */
final class ModelCall {
    private const NATIVE_STATUS = ['rate_limit' => 429, 'billing_error' => 402, 'authentication_failed' => 401, 'overloaded' => 529, 'server_error' => 503];
    private const TYPES = [401 => 'authentication_error', 402 => 'billing_error', 429 => 'rate_limit_error', 503 => 'api_error', 529 => 'overloaded_error'];
    private const REAP_SECONDS = 10.0;
    private const STDERR_BYTES = 400;

    /** the processes of the CLI running now */
    private int $held = 0;
    /** @var list<DeferredFuture<mixed>> the calls that wait for a place, in the order they came */
    private array $waiting = [];

    /**
     * @param non-empty-string $tmpDir where the files of a request live for the time of the call
     * @param non-empty-string $workDir the folder the CLI runs in: empty, the same for every call
     * @param float $idleSeconds how long the CLI may print nothing before the call is given up
     * @param positive-int $maxConcurrent how many processes of the CLI run at once
     * @param float $queueSeconds how long a call waits for a place before it is refused
     */
    public function __construct(
        private readonly ClaudeCli $cli,
        private readonly Relay $relay,
        private readonly Limits $limits,
        private readonly string $tmpDir,
        private readonly string $workDir,
        private readonly LoggerInterface $logger,
        private readonly float $idleSeconds = 300.0,
        private readonly int $maxConcurrent = 4,
        private readonly float $queueSeconds = 300.0,
    ) {}

    /**
     * @param ?string $oauthToken the token of `claude setup-token` the application sent with the request
     * @param string $policy the owner's word about paid use beyond the plan: stop, allow or notify
     * @param float $reserve the percent of a window at which `stop` refuses
     * @throws CallFailed
     * @throws CancelledException
     */
    public function run(MessagesRequest $request, #[\SensitiveParameter] ?string $oauthToken, Cancellation $cancellation, string $policy = Limits::STOP, float $reserve = 100.0): Answer {
        // the application went away while the request was read: nothing starts for it
        $cancellation->throwIfRequested();
        try {
            $this->take(new CompositeCancellation($cancellation, new TimeoutCancellation($this->queueSeconds)));
        } catch (CancelledException $e) {
            if ($cancellation->isRequested()) {
                throw $e;
            }
            throw new CallFailed(529, 'overloaded_error', sprintf('All %d places for Claude Code were busy for %d s', $this->maxConcurrent, (int) $this->queueSeconds));
        }
        $held = true;
        $free = function () use (&$held): void {
            if ($held) {
                $held = false;
                $this->give();
            }
        };
        try {
            $cancellation->throwIfRequested();
            // after the wait for a place: calls that ran meanwhile may have crossed the limit
            $this->limits->admit($oauthToken, $policy, $reserve);
            $observe = function (array $event) use ($oauthToken, $policy, $reserve): ?array {
                return $this->limits->observeEvent($oauthToken, $event, $policy, $reserve);
            };
            return $this->start($request, $oauthToken, $cancellation, $free, $observe);
        } catch (\Throwable $e) {
            // the call did not start, or failed before its answer: the place is free at once
            $free();
            throw $e;
        }
    }

    /** Waits for a place among the processes of the CLI, in the order the calls came. */
    private function take(Cancellation $cancellation): void {
        if ($this->held < $this->maxConcurrent) {
            $this->held++;
            return;
        }
        $turn = new DeferredFuture();
        $this->waiting[] = $turn;
        try {
            $turn->getFuture()->await($cancellation);
        } catch (CancelledException $e) {
            $this->waiting = array_values(array_filter($this->waiting, static fn(DeferredFuture $other): bool => $other !== $turn));
            if ($turn->isComplete()) {
                // the place came at the moment the call gave up: it goes on to the next, a late call never starts
                $this->give();
            }
            throw $e;
        }
    }

    private function give(): void {
        $next = array_shift($this->waiting);
        if ($next === null) {
            $this->held--;
        } else {
            $next->complete();
        }
    }

    /**
     * @param \Closure(): void $free gives back the place of the call: held until its process is gone
     * @param \Closure(array<array-key, mixed>): ?array{using: bool, resetsAt: ?float} $observe takes what the CLI says
     *        about the windows of the plan
     * @throws CallFailed
     * @throws CancelledException
     */
    private function start(MessagesRequest $request, #[\SensitiveParameter] ?string $oauthToken, Cancellation $cancellation, \Closure $free, \Closure $observe): Answer {
        $admission = new Admission($request->names);
        $admission->queried = self::plainObjects($request->frames[count($request->frames) - 1]['message']['content']);
        $admission->oneShot = $request->manifest === [] && count($request->frames) === 1;
        $relay = $this->relay->admit($admission);
        $dir = $this->tmpDir . '/claude-code-' . bin2hex(random_bytes(8));
        try {
            if (!isDirectory($this->workDir)) {
                createDirectoryRecursively($this->workDir, 0700);
            }
            createDirectory($dir, 0700);
            write($dir . '/system.md', $request->system);
            // the settings of the CLI set the variable inside the process: a full list of tools does not fit a command line
            // reading files is denied outright: the text of a user must not make the CLI attach a file of the service
            write($dir . '/settings.json', json_encode(['env' => ['CLAUDE_CODE_EXTRA_BODY' => $request->body], 'permissions' => ['deny' => ['Read']]], MessagesRequest::JSON));
            $servers = new stdClass();
            if ($request->manifest !== []) {
                write($dir . '/tools.json', json_encode($request->manifest, MessagesRequest::JSON));
                $servers->{ToolNames::SERVER} = ['command' => PHP_BINARY, 'args' => [dirname(__DIR__) . '/resources/inert-mcp.php', $dir . '/tools.json']];
            }
            $command = [
                ...$this->cli->command(),
                '-p', '--model', $request->native,
                '--input-format', 'stream-json', '--output-format', 'stream-json', '--verbose', '--include-partial-messages',
                '--tools', '',
                '--system-prompt-file', $dir . '/system.md',
                '--settings', $dir . '/settings.json',
                '--setting-sources', '',
                '--strict-mcp-config', '--disable-slash-commands',
                '--max-turns', '1',
                '--permission-mode', 'dontAsk',
                '--no-session-persistence',
                '--mcp-config', json_encode(['mcpServers' => $servers], MessagesRequest::JSON),
            ];
            if ($request->effort !== null) {
                // the CLI adds its own level of effort to a turn, and it wins over the one in the body
                $command = [...$command, '--effort', $request->effort];
            }
            $command = [...$command, '--session-id', $request->sessionId()];
            // a call nothing will repeat keeps its cache five minutes, not an hour: a long entry costs more to write
            $process = Process::start($command, $this->workDir, $this->cli->env()->call($oauthToken, $relay, $request->maxTokens, $admission->oneShot ? '5m' : null));
        } catch (Throwable $e) {
            $this->relay->release($admission);
            $this->remove($dir);
            if (!$e instanceof RefusedEnvironment) {
                $this->logger->error('claude-code: the CLI did not start', ['error' => $e::class]);
            }
            throw new CallFailed(503, 'api_error', $e instanceof RefusedEnvironment ? $e->getMessage() : 'Claude Code could not be started.');
        }
        $stderr = async(static fn(): string => self::head($process->getStderr()));
        $outcome = new Outcome();
        // the application may go away at any moment of the call, also in the middle of an answer: the CLI is stopped,
        // and with it the permit and the request to the API — nothing is paid for an answer nobody reads
        $subscription = $cancellation->subscribe(static fn() => self::kill($process));
        $drive = async(function () use ($process, $request, $outcome, $admission, $dir, $cancellation, $subscription, $free, $observe): bool {
            try {
                $this->drive($process, $request, $outcome, $observe);
            } finally {
                $free();
                $cancellation->unsubscribe($subscription);
                $this->relay->release($admission);
                $this->remove($dir);
            }
            return false;
        });
        try {
            Future\awaitFirst([$admission->answering(), $drive], $cancellation);
        } catch (CancelledException $e) {
            self::kill($process);
            throw $e;
        }
        if ($admission->status !== 200 || !$admission->answering()->isComplete()) {
            self::kill($process);
            throw self::failure($admission, $outcome, self::said($stderr));
        }
        return new Answer($admission, static function () use ($drive, $process): void {
            async(static function () use ($drive, $process): void {
                try {
                    $drive->await(new TimeoutCancellation(self::REAP_SECONDS));
                } catch (Throwable) {
                    self::kill($process);
                }
            })->ignore();
        });
    }

    /** @param \Closure(array<array-key, mixed>): ?array{using: bool, resetsAt: ?float} $observe */
    private function drive(Process $process, MessagesRequest $request, Outcome $outcome, \Closure $observe): void {
        /** @var Queue<array<array-key, mixed>> $events */
        $events = new Queue();
        async(static function () use ($process, $events): void {
            try {
                $pending = '';
                $stdout = $process->getStdout();
                while (($chunk = $stdout->read()) !== null) {
                    $pending .= $chunk;
                    while (($end = strpos($pending, "\n")) !== false) {
                        $line = trim(substr($pending, 0, $end));
                        $pending = substr($pending, $end + 1);
                        if ($line === '') {
                            continue;
                        }
                        /** @var mixed $event */
                        $event = json_decode($line, true);
                        if (!is_array($event)) {
                            throw new RuntimeException('Claude Code printed a line that is not an event: ' . substr($line, 0, 200));
                        }
                        $events->pushAsync($event)->ignore();
                    }
                }
                $events->complete();
            } catch (Throwable $e) {
                if (!$events->isComplete()) {
                    $events->error($e);
                }
            }
        })->ignore();
        $watch = '';
        $touch = function () use (&$watch, $process, $outcome): void {
            if ($watch !== '') {
                EventLoop::cancel($watch);
            }
            $watch = EventLoop::delay($this->idleSeconds, static function () use ($process, $outcome): void {
                $outcome->timedOut = true;
                self::kill($process);
            });
        };
        try {
            $touch();
            $iterator = $events->iterate();
            $stdin = $process->getStdin();
            $last = count($request->frames) - 1;
            foreach ($request->frames as $index => $frame) {
                // an earlier reply of the user is history: the CLI takes it without asking the model
                $replayed = $frame['type'] === 'user' && $index < $last;
                $stdin->write(json_encode($replayed ? [...$frame, 'shouldQuery' => false] : $frame, MessagesRequest::JSON) . "\n");
                $touch();
                while ($replayed) {
                    if (!$iterator->continue()) {
                        throw new RuntimeException('Claude Code exited before it took the history');
                    }
                    $touch();
                    $event = $iterator->getValue();
                    if (($event['type'] ?? null) === 'result') {
                        if (($event['num_turns'] ?? null) !== 0 || ($event['is_error'] ?? false) !== false) {
                            throw new RuntimeException('Claude Code did not take the history as a replay');
                        }
                        break;
                    }
                }
            }
            $stdin->end();
            while ($iterator->continue()) {
                $touch();
                $event = $iterator->getValue();
                if (($event['type'] ?? null) === 'rate_limit_event') {
                    // the CLI reports the windows of the plan in its output: the only word about them during a call
                    $outcome->resetsAt = $observe($event)['resetsAt'] ?? $outcome->resetsAt;
                    continue;
                }
                if (($event['type'] ?? null) !== 'assistant') {
                    continue;
                }
                /** @var mixed $message */
                $message = $event['message'] ?? null;
                /** @var mixed $code */
                $code = $event['error'] ?? (is_array($message) ? ($message['error'] ?? null) : null);
                if ($code === null || $code === false) {
                    continue;
                }
                $texts = [];
                /** @var mixed $block */
                foreach (is_array($message) && is_array($message['content'] ?? null) ? $message['content'] : [] as $block) {
                    /** @var mixed $text */
                    $text = is_array($block) && ($block['type'] ?? null) === 'text' ? ($block['text'] ?? null) : null;
                    if (is_string($text)) {
                        $texts[] = $text;
                    }
                }
                $outcome->nativeError = implode("\n", $texts);
                $outcome->nativeCode = is_string($code) ? $code : '';
            }
        } catch (Throwable $e) {
            $outcome->detail = $e instanceof RuntimeException ? $e->getMessage() : 'Claude Code stopped taking the request';
            self::kill($process);
        } finally {
            if ($watch !== '') {
                EventLoop::cancel($watch);
            }
            try {
                $process->join(new TimeoutCancellation(5.0));
            } catch (Throwable) {
                self::kill($process);
            }
        }
    }

    /**
     * The blocks as plain JSON objects, the way the relay reads the request of the CLI.
     *
     * @param list<stdClass> $blocks
     * @return list<stdClass>
     */
    private static function plainObjects(array $blocks): array {
        /** @var mixed $copy */
        $copy = json_decode(json_encode($blocks, MessagesRequest::JSON), false, 512, JSON_THROW_ON_ERROR);
        return is_array($copy) ? array_values(array_filter($copy, static fn(mixed $b): bool => $b instanceof stdClass)) : [];
    }

    private static function failure(Admission $admission, Outcome $outcome, string $stderr): CallFailed {
        $said = $stderr === '' ? '' : ' (Claude Code said: ' . $stderr . ')';
        if ($admission->status !== null && $admission->status !== 200) {
            $status = $admission->status >= 400 && $admission->status <= 599 ? $admission->status : 502;
            $body = trim($admission->errorBody());
            /** @var mixed $answer */
            $answer = json_decode($body, true);
            /** @var mixed $error */
            $error = is_array($answer) ? ($answer['error'] ?? null) : null;
            /** @var mixed $type */
            $type = is_array($error) ? ($error['type'] ?? null) : null;
            /** @var mixed $message */
            $message = is_array($error) ? ($error['message'] ?? null) : null;
            if (is_string($type) && is_string($message)) {
                // the answer of the API as it was
                return new CallFailed($status, $type, $message, $body, $admission->headers);
            }
            return new CallFailed($status, self::TYPES[$status] ?? 'api_error', sprintf('The API answered %d', $admission->status), null, $admission->headers);
        }
        // what the service stopped the CLI for comes first: a request dropped by that stop is its consequence
        if ($outcome->timedOut) {
            return new CallFailed(504, 'timeout_error', 'Claude Code was silent for too long' . $said);
        }
        if ($outcome->detail !== null) {
            return new CallFailed(502, 'api_error', $outcome->detail . $said);
        }
        if ($admission->failure !== null) {
            return new CallFailed(502, 'api_error', sprintf('The request did not reach the API (%s)', $admission->failure));
        }
        if ($outcome->nativeError !== null) {
            $status = self::NATIVE_STATUS[$outcome->nativeCode] ?? 502;
            $text = $status === 401 ? 'Nobody is signed in to Claude, or the sign-in is no longer valid' : 'Claude Code refused the request';
            $headers = [];
            if ($status === 429 && $outcome->resetsAt !== null && $outcome->resetsAt > microtime(true)) {
                // the CLI refused by a used-up window itself: the same headers the API refuses with
                $headers = [
                    'anthropic-ratelimit-unified-status' => 'rejected',
                    'anthropic-ratelimit-unified-reset' => (string) (int) round($outcome->resetsAt),
                    'retry-after' => (string) max(1, (int) ceil($outcome->resetsAt - microtime(true))),
                ];
            }
            return new CallFailed($status, self::TYPES[$status] ?? 'api_error', $text . ($outcome->nativeError === '' ? '' : ': ' . $outcome->nativeError), null, $headers);
        }
        return new CallFailed(502, 'api_error', 'Claude Code ended without a request to the model' . $said);
    }

    /** @param Future<mixed> $stderr */
    private static function said(Future $stderr): string {
        /** @var mixed $text */
        $text = $stderr->await();
        return is_string($text) ? $text : '';
    }

    /** The first of what the CLI wrote to stderr, in one line: the reason of a failure that left no other trace. */
    private static function head(ReadableStream $stream): string {
        $head = '';
        try {
            while (($chunk = $stream->read()) !== null) {
                if (strlen($head) < self::STDERR_BYTES) {
                    $head .= $chunk;
                }
            }
        } catch (Throwable) {
            // the process is gone: what was read is the answer
        }
        return substr(trim((string) preg_replace('~\s+~', ' ', $head)), 0, self::STDERR_BYTES);
    }

    private static function kill(Process $process): void {
        try {
            if ($process->isRunning()) {
                $process->kill();
            }
        } catch (Throwable) {
            // it exited between the check and the signal
        }
    }

    /** The files of a request hold the history: they do not outlive the call. */
    private function remove(string $dir): void {
        try {
            if (!isDirectory($dir)) {
                return;
            }
            foreach (listFiles($dir) as $file) {
                deleteFile($dir . '/' . $file);
            }
            deleteDirectory($dir);
        } catch (Throwable $e) {
            $this->logger->warning('claude-code: the files of a request were not removed', ['error' => $e::class]);
        }
    }
}
