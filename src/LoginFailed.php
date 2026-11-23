<?php

declare(strict_types=1);

namespace Mercurius\ClaudeCode;

use RuntimeException;

/** A sign-in could not start or there is none to continue; the message is safe to show to the owner. */
final class LoginFailed extends RuntimeException {}
