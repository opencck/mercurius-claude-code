<?php

declare(strict_types=1);

namespace Mercurius\ClaudeCode;

/**
 * What the CLI is and how a request would sign in right now.
 *
 * spec: brief/models.md#вход-в-подписку-claude
 */
final class CliStatus {
    /** the token of `claude setup-token` sent by the application */
    public const TOKEN = 'token';
    /** the sign-in the CLI keeps in its folder (`claude auth login`) */
    public const LOGIN = 'login';
    public const NONE = 'none';
    /** an API key or a cloud backend: it would bill API usage, so it is not the subscription */
    public const METERED = 'metered';

    public function __construct(
        /** null — the CLI was not found or did not answer */
        public readonly ?string $version,
        public readonly bool $versionOk,
        public readonly string $auth,
        public readonly string $plan = '',
        public readonly string $detail = '',
    ) {}

    /** The CLI is there and new enough: the service can work, signed in or not. */
    public function usable(): bool {
        return $this->version !== null && $this->versionOk;
    }

    public function signedIn(): bool {
        return $this->auth === self::TOKEN || $this->auth === self::LOGIN;
    }
}
