<?php

declare(strict_types=1);

namespace Mercurius\ClaudeCode;

use Amp\DeferredCancellation;
use Amp\DeferredFuture;
use Amp\Future;

/**
 * The permit of one model call: Claude Code started for this call may send one request to the API, and what came
 * back is kept here — the status, the limits the API named, the events of the answer.
 *
 * spec: brief/models.md#claude-по-подписке
 * ref: references/hermes-official-plugins/claude-subscription-directsdk/admission.py@269ddcb:L703-L732 (Admission)
 *      — used, denied, the status, the id of the request, the text of an error, a random route per call
 * ref: references/hermes-official-plugins/claude-subscription-directsdk/admission.py@269ddcb:L857-L878
 *      (_allows_auth_retry) — one more request only after the API refused the sign-in: the CLI renews it and repeats
 */
final class Admission {
    private const ERROR_BYTES = 65536;

    /** the secret part of the route: only the CLI of this call knows it */
    public readonly string $route;
    public readonly Capture $capture;
    /** @var list<string> the events of the answer the application has not taken yet */
    private array $frames = [];
    private bool $ended = false;
    /** @var ?DeferredFuture<null> the application waits for the next event */
    private ?DeferredFuture $waiting = null;
    public bool $used = false;
    public int $denied = 0;
    public ?int $status = null;
    public ?string $requestId = null;
    /** @var array<non-empty-string, string> what the API said about limits */
    public array $headers = [];
    /** the kind of a failure between the relay and the API, when there was one */
    public ?string $failure = null;
    /** @var list<\stdClass> the reply of the application the CLI was asked about: the cache keeps it as it was */
    public array $queried = [];
    /** nothing will repeat the messages of this call: they carry no cache marks */
    public bool $oneShot = false;
    /** why the tool results were not restored for the cache, when they were not */
    public ?string $unrestored = null;
    /** the answer ended before it was complete: said after the events that did come, never instead of them */
    public ?CallFailed $cut = null;
    private string $errorBody = '';
    private bool $authRetryUsed = false;
    private bool $closed = false;
    /** @var DeferredFuture<true> */
    private readonly DeferredFuture $answering;
    private readonly DeferredCancellation $cancellation;

    public function __construct(ToolNames $names) {
        $this->route = bin2hex(random_bytes(24));
        $this->capture = new Capture($names);
        $this->answering = new DeferredFuture();
        $this->cancellation = new DeferredCancellation();
    }

    /** @return Future<true> completes when the API started an answer */
    public function answering(): Future {
        return $this->answering->getFuture();
    }

    public function cancellation(): \Amp\Cancellation {
        return $this->cancellation->getCancellation();
    }

    public function closed(): bool {
        return $this->closed;
    }

    /** May this request go to the API: the first one, or the one repeat after a refused sign-in. */
    public function admit(): bool {
        if ($this->closed) {
            $this->denied++;
            return false;
        }
        if ($this->used) {
            $error = null;
            if (!$this->authRetryUsed && $this->status === 401) {
                /** @var mixed $answer */
                $answer = json_decode(trim($this->errorBody), true);
                /** @var mixed $error */
                $error = is_array($answer) ? ($answer['error'] ?? null) : null;
            }
            if (!is_array($error) || ($error['type'] ?? null) !== 'authentication_error') {
                $this->denied++;
                return false;
            }
            $this->authRetryUsed = true;
            // the answer to the repeat is the one the application gets
            $this->errorBody = '';
            $this->status = null;
            $this->headers = [];
        }
        $this->used = true;
        return true;
    }

    public function started(): void {
        if (!$this->answering->isComplete()) {
            $this->answering->complete(true);
        }
    }

    public function error(string $chunk): void {
        if (strlen($this->errorBody) < self::ERROR_BYTES) {
            $this->errorBody .= $chunk;
        }
    }

    public function errorBody(): string {
        return $this->errorBody;
    }

    /** An event of the answer, for the application. */
    public function push(string $frame): void {
        if ($this->ended) {
            return;
        }
        $this->frames[] = $frame;
        $this->wake();
    }

    /**
     * The next event of the answer; null — the answer ended. The events that came before the end are all given
     * first: a cut answer loses nothing of what the API did send.
     */
    public function next(): ?string {
        while ($this->frames === [] && !$this->ended) {
            $this->waiting ??= new DeferredFuture();
            $this->waiting->getFuture()->await();
        }
        return array_shift($this->frames);
    }

    /** The answer of the API ended: whole, or cut. */
    public function ended(): void {
        if ($this->ended) {
            return;
        }
        $this->ended = true;
        if (!$this->capture->complete()) {
            $this->cut = new CallFailed(502, 'api_error', 'The answer of the API ended before it was complete' . ($this->failure === null ? '' : ' (' . $this->failure . ')'));
        }
        $this->wake();
    }

    private function wake(): void {
        $waiting = $this->waiting;
        $this->waiting = null;
        $waiting?->complete();
    }

    /** The call is over: nothing more goes to the API by this permit, a request in flight is dropped. */
    public function close(): void {
        if ($this->closed) {
            return;
        }
        $this->closed = true;
        $this->cancellation->cancel();
        $this->ended();
    }
}
