<?php

declare(strict_types=1);

namespace Mercurius\ClaudeCode;

use RuntimeException;

/** The environment of the service would make Claude Code talk to something else than the subscription. */
final class RefusedEnvironment extends RuntimeException {}
