<?php

declare(strict_types=1);

namespace Mercurius\ClaudeCode;

use Amp\Pipeline\ConcurrentIterator;
use Closure;
use Generator;
use stdClass;
use Throwable;

/**
 * The answer of the API to one call, started: either the events as they come, or the whole message at the end.
 *
 * spec: brief/models.md#claude-по-подписке
 */
final class Answer {
    /**
     * @param ConcurrentIterator<string> $frames
     * @param Closure(): void $finish the application has its answer: the CLI may be put away
     */
    public function __construct(
        public readonly ?string $requestId,
        private readonly ConcurrentIterator $frames,
        private readonly Capture $capture,
        private readonly Closure $finish,
    ) {}

    /**
     * The events of the answer. A stream cut in the middle ends with an error event, as the API itself reports an
     * error after the status went out.
     *
     * @return Generator<int, string>
     */
    public function stream(): Generator {
        try {
            while ($this->frames->continue()) {
                yield $this->frames->getValue();
            }
        } catch (Throwable $e) {
            $message = $e instanceof CallFailed ? $e->getMessage() : 'The answer of the API was cut';
            yield sprintf("event: error\ndata: %s\n\n", json_encode(['type' => 'error', 'error' => ['type' => 'api_error', 'message' => $message]], MessagesRequest::JSON));
        } finally {
            ($this->finish)();
        }
    }

    /**
     * The whole message, when the stream reached its end.
     *
     * @throws CallFailed
     */
    public function message(): stdClass {
        try {
            while ($this->frames->continue()) {
                // the events are for a stream; here only the end matters
            }
        } catch (CallFailed $e) {
            throw $e;
        } catch (Throwable) {
            throw new CallFailed(502, 'api_error', 'The answer of the API was cut');
        } finally {
            ($this->finish)();
        }
        return $this->capture->message() ?? throw new CallFailed(502, 'api_error', 'The API gave no message');
    }
}
