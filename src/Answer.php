<?php

declare(strict_types=1);

namespace Mercurius\ClaudeCode;

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
    /** @var \Amp\Pipeline\ConcurrentIterator<string> */
    private readonly \Amp\Pipeline\ConcurrentIterator $frames;
    public readonly ?string $requestId;

    /**
     * @param Closure(): void $finish the application has its answer: the CLI may be put away
     */
    public function __construct(
        private readonly Admission $admission,
        private readonly Closure $finish,
    ) {
        $this->requestId = $admission->requestId;
        $this->frames = $admission->frames->iterate();
    }

    /**
     * The events of the answer. A stream cut in the middle ends with an error event, as the API itself reports an
     * error after the status went out.
     *
     * @return Generator<int, string>
     */
    public function stream(): Generator {
        try {
            $cut = null;
            try {
                while ($this->frames->continue()) {
                    yield $this->frames->getValue();
                }
                $cut = $this->admission->cut?->getMessage();
            } catch (Throwable) {
                $cut = 'The answer of the API was cut';
            }
            if ($cut !== null) {
                yield sprintf("event: error\ndata: %s\n\n", json_encode(['type' => 'error', 'error' => ['type' => 'api_error', 'message' => $cut]], MessagesRequest::JSON));
            }
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
        } catch (Throwable) {
            throw new CallFailed(502, 'api_error', 'The answer of the API was cut');
        } finally {
            ($this->finish)();
        }
        if ($this->admission->cut !== null) {
            throw $this->admission->cut;
        }
        return $this->admission->capture->message() ?? throw new CallFailed(502, 'api_error', 'The API gave no message');
    }
}
