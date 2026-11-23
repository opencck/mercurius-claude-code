<?php

declare(strict_types=1);

namespace Mercurius\ClaudeCode;

use Closure;
use Generator;
use stdClass;

/**
 * The answer of the API to one call, started: either the events as they come, or the whole message at the end.
 *
 * spec: brief/models.md#claude-по-подписке
 */
final class Answer {
    public readonly ?string $requestId;

    /**
     * @param Closure(): void $finish the application has its answer: the CLI may be put away
     */
    public function __construct(
        private readonly Admission $admission,
        private readonly Closure $finish,
    ) {
        $this->requestId = $admission->requestId;
    }

    /**
     * The events of the answer. A stream cut in the middle ends with an error event, as the API itself reports an
     * error after the status went out.
     *
     * @return Generator<int, string>
     */
    public function stream(): Generator {
        try {
            while (($frame = $this->admission->next()) !== null) {
                yield $frame;
            }
            if ($this->admission->cut !== null) {
                yield sprintf("event: error\ndata: %s\n\n", json_encode(['type' => 'error', 'error' => ['type' => 'api_error', 'message' => $this->admission->cut->getMessage()]], MessagesRequest::JSON));
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
            while ($this->admission->next() !== null) {
                // the events are for a stream; here only the end matters
            }
        } finally {
            ($this->finish)();
        }
        if ($this->admission->cut !== null) {
            throw $this->admission->cut;
        }
        return $this->admission->capture->message() ?? throw new CallFailed(502, 'api_error', 'The API gave no message');
    }
}
