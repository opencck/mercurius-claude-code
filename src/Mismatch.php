<?php

declare(strict_types=1);

namespace Mercurius\ClaudeCode;

use RuntimeException;

/** The request of the CLI does not match what the application sent closely enough to be restored. */
final class Mismatch extends RuntimeException {}
