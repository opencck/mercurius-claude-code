<?php

declare(strict_types=1);

namespace Mercurius\ClaudeCode;

/**
 * The models of the subscription the service knows by name: the window of each and the name Claude Code takes. Behind
 * a local address the CLI gives the long window only to a model named with `[1m]`, so the suffix is added to the
 * models pinned here; a model that is not pinned goes to the CLI as it was named and fails there, not by a guess.
 *
 * spec: brief/models.md#claude-по-подписке
 * ref: references/hermes-official-plugins/claude-subscription-directsdk/model_catalog.py@269ddcb:L2-L92
 *      (CONTEXT_WINDOWS, ACCESS_GATED, MANDATORY_THINKING, ALIASES, _route_base, native_model,
 *      accepts_thinking_disable, supports_adaptive_thinking) — the same table and rules; the vendor prefix of
 *      OpenRouter is not stripped: the application names the model itself
 */
final class Models {
    private const LONG = 1_000_000;
    private const WINDOWS = [
        'claude-sonnet-5-5' => 1_000_000,
        'claude-sonnet-5' => 1_000_000,
        'claude-haiku-5-5' => 1_000_000,
        'claude-haiku-4-5-20251001' => 200_000,
        'claude-opus-5-5' => 1_000_000,
        'claude-opus-5' => 1_000_000,
        'claude-opus-4-8' => 1_000_000,
        'claude-fable-5-2' => 1_000_000,
        'claude-fable-5-1' => 1_000_000,
        'claude-mythos-5-2' => 1_000_000,
        'claude-mythos-5-1' => 1_000_000,
    ];
    /** given to an account by Anthropic one by one: never offered from this table */
    private const GATED = ['claude-mythos-5-1', 'claude-mythos-5-2'];
    /** families that answer 400 to `thinking: disabled` */
    private const MANDATORY_THINKING = ['claude-fable', 'claude-opus-5-5', 'claude-sonnet-5-5', 'claude-haiku-5-5', 'claude-mythos'];
    private const NO_ADAPTIVE = ['claude-haiku-4-5-20251001'];
    private const ALIASES = [
        'sonnet' => 'claude-sonnet-5-5',
        'haiku' => 'claude-haiku-5-5',
        'claude-haiku-4-5' => 'claude-haiku-4-5-20251001',
        'opus' => 'claude-opus-5-5',
        'fable' => 'claude-fable-5-2',
    ];

    /**
     * The name Claude Code takes for a model the application named.
     *
     * @throws RequestRejected
     */
    public static function native(string $model): string {
        $canonical = self::canonical($model);
        $window = self::WINDOWS[$canonical] ?? null;
        if ($window === self::LONG) {
            return $canonical . '[1m]';
        }
        if ($window !== null) {
            if (str_ends_with($model, '[1m]')) {
                throw new RequestRejected(sprintf('%s has no 1M context window', $canonical));
            }
            return $canonical;
        }
        return $model;
    }

    public static function acceptsThinkingDisable(string $model): bool {
        $canonical = self::canonical($model);
        foreach (self::MANDATORY_THINKING as $family) {
            if (str_starts_with($canonical, $family)) {
                return false;
            }
        }
        return true;
    }

    public static function supportsAdaptiveThinking(string $model): bool {
        return !in_array(self::canonical($model), self::NO_ADAPTIVE, true);
    }

    /** @return list<array{id: string, context_window: int}> the models every account may be offered */
    public static function offered(): array {
        $list = [];
        foreach (self::WINDOWS as $id => $window) {
            if (!in_array($id, self::GATED, true)) {
                $list[] = ['id' => $id, 'context_window' => $window];
            }
        }
        return $list;
    }

    private static function canonical(string $model): string {
        $base = self::base(str_ends_with($model, '[1m]') ? substr($model, 0, -4) : $model);
        return self::ALIASES[$base] ?? $base;
    }

    /** `sonnet-5-5` and `sonnet-5.5` both name `claude-sonnet-5-5`; a name that matches nothing stays as typed. */
    private static function base(string $base): string {
        if (isset(self::ALIASES[$base]) || isset(self::WINDOWS[$base])) {
            return $base;
        }
        $dashed = str_replace('.', '-', $base);
        if ($dashed !== $base) {
            return self::base($dashed);
        }
        $full = 'claude-' . (str_starts_with($base, 'claude-') ? substr($base, 7) : $base);
        return isset(self::WINDOWS[$full]) || isset(self::ALIASES[$full]) ? $full : $base;
    }
}
