<?php

declare(strict_types=1);

namespace Mercurius\ClaudeCode;

use RuntimeException;

/**
 * A model call that gave no answer, with the HTTP status the application gets: the answer of the API as it was, or
 * the status that stands for what Claude Code said itself.
 *
 * spec: brief/models.md#claude-по-подписке
 */
final class CallFailed extends RuntimeException {
    /**
     * @param int $status the HTTP status for the application
     * @param string $type the error type of the Messages API
     * @param ?string $body the answer of the API as it was; null — the service words the error itself
     * @param array<non-empty-string, string> $headers what the API said about limits (retry-after, the state of the windows)
     */
    public function __construct(
        public readonly int $status,
        public readonly string $type,
        string $message,
        public readonly ?string $body = null,
        public readonly array $headers = [],
    ) {
        parent::__construct($message);
    }
}
