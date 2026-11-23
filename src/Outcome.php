<?php

declare(strict_types=1);

namespace Mercurius\ClaudeCode;

/** What the CLI said about a call when it gave no answer of the API. */
final class Outcome {
    /** the text of an error the CLI answered itself, without a request to the API */
    public ?string $nativeError = null;
    /** the CLI's own code of that error */
    public string $nativeCode = '';
    public bool $timedOut = false;
    /** why the service stopped the CLI */
    public ?string $detail = null;
}
