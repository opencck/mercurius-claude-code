<?php

declare(strict_types=1);

namespace Mercurius\ClaudeCode;

use InvalidArgumentException;

/** A request the service cannot serve, refused before Claude Code starts: the same input fails every time. */
final class RequestRejected extends InvalidArgumentException {}
