<?php

declare(strict_types=1);

namespace Mercurius\ClaudeCode;

use Amp\DeferredFuture;
use Amp\Future;
use Closure;
use DateTimeImmutable;
use SensitiveParameter;
use Throwable;

/**
 * What the service knows about the limits of the plan, and the owner's word about paid use beyond it. When a window
 * of the plan is full and extra usage is on, Anthropic bills the next requests on top of the subscription. The
 * application says with each call what to do then: `stop` (the default) — refuse before the CLI starts, with the
 * time the window reopens; `allow` and `notify` — send. Under `stop` a state that cannot be learned is a refusal too:
 * the mode exists so that nothing is spent without the owner's word. The request that first crosses the limit is
 * already billed; a reserve below 100 percent stops before it.
 *
 * The state lives in memory, per sign-in; nothing is written.
 *
 * spec: brief/models.md#лимиты-подписки-и-переход-на-модель-категории
 * ref: references/hermes-official-plugins/claude-subscription-directsdk/extra_usage.py@269ddcb:L65-L208 (_epoch,
 *      _record, observe, usage_percent, reserve_percent, observe_usage, admit) — the same state, the same reading of
 *      an event and of the usage answer, the same refusal when the state is unknown. The mode `ask` is not taken:
 *      here the application moves to another model of the category instead; an unknown mode is `stop`
 * ref: references/hermes-official-plugins/claude-subscription-directsdk/directsdk.py@269ddcb:L668-L691
 *      (_rate_limit_summary) — paid use is isUsingOverage or the window `overage`; `allowed` only permits it
 */
final class Limits {
    public const STOP = 'stop';
    public const ALLOW = 'allow';
    public const NOTIFY = 'notify';
    /** seconds an answer stands when it names no time of a reset */
    private const RECHECK = 900.0;

    /** @var array<string, array{using: bool, resetsAt: ?float, until: float}> */
    private array $state = [];
    /** @var Closure(): float */
    private readonly Closure $clock;
    /** @var array<string, Future<array{?array<array-key, mixed>, string}>> the questions to the CLI under way */
    private array $asking = [];

    /**
     * @param Closure(?string): array{?array<array-key, mixed>, string} $probe asks the CLI for the usage of the plan
     *        without a model call: the limits, or null and why not
     * @param ?Closure(): float $clock
     */
    public function __construct(
        private readonly Closure $probe,
        ?Closure $clock = null,
    ) {
        $this->clock = $clock ?? static fn(): float => microtime(true);
    }

    /** The owner's word as the application sent it; a word the service does not know is `stop`: a typo must not spend. */
    public static function policy(?string $value): string {
        $value = strtolower(trim((string) $value));
        return in_array($value, [self::ALLOW, self::NOTIFY], true) ? $value : self::STOP;
    }

    /** The percent of a window at which `stop` refuses; unset or invalid — 100. */
    public static function reserve(?string $value): float {
        $percent = is_numeric($value) ? (float) $value : 100.0;
        return is_finite($percent) && $percent > 0 && $percent <= 100 ? $percent : 100.0;
    }

    /** The sign-in a state belongs to: the login of the CLI, or the token the application sent. */
    public static function key(#[SensitiveParameter] ?string $oauthToken): string {
        return $oauthToken === null ? '' : substr(hash('sha256', $oauthToken), 0, 16);
    }

    /**
     * Refuses a call the owner did not allow to be paid on top of the plan.
     *
     * @throws CallFailed
     */
    public function admit(#[SensitiveParameter] ?string $oauthToken, string $policy, float $reserve): void {
        if ($policy !== self::STOP) {
            return;
        }
        $key = self::key($oauthToken);
        $now = ($this->clock)();
        $known = $this->state[$key] ?? null;
        if ($known === null || $now >= $known['until']) {
            // an old answer must not outlive a probe that could not tell
            unset($this->state[$key]);
            [$limits, $reason] = $this->ask($key, $oauthToken);
            if ($limits === null) {
                throw self::refused('The state of the plan limits is unknown (' . $reason . '), and paid use beyond the plan is not allowed, so the request was not sent.', null, $now);
            }
            $known = $this->observeUsage($key, $limits, $reserve);
        }
        if ($known['using']) {
            $what = $reserve < 100
                ? sprintf('a window of the plan has reached %s%%, so the next request may be billed as extra usage', rtrim(rtrim(number_format($reserve, 2, '.', ''), '0'), '.'))
                : 'the account is drawing paid extra usage';
            throw self::refused('The limit of the Claude plan is reached: ' . $what . ', and paid use beyond the plan is not allowed, so the request was not sent.', $known['resetsAt'], $now);
        }
    }

    /**
     * An event of the CLI about the windows of the plan, seen during a call.
     *
     * @param array<array-key, mixed> $event
     * @return ?array{using: bool, resetsAt: ?float} what it says; null — not such an event
     */
    public function observeEvent(#[SensitiveParameter] ?string $oauthToken, array $event, string $policy, float $reserve): ?array {
        /** @var mixed $info */
        $info = $event['rate_limit_info'] ?? null;
        if (!is_array($info)) {
            return null;
        }
        /** @var mixed $window */
        $window = $info['rateLimitType'] ?? null;
        $using = ($info['isUsingOverage'] ?? false) === true || $window === 'overage';
        $resetsAt = self::epoch($info['resetsAt'] ?? null);
        if (!$using && $policy === self::STOP && $reserve < 100 && ($info['overageStatus'] ?? null) === 'allowed') {
            // a nearly full window counts as spilling: the next request would be the first paid one
            $used = [];
            /** @var mixed $windows */
            $windows = $info['unifiedWindows'] ?? null;
            foreach (['five_hour', 'seven_day'] as $name) {
                /** @var mixed $utilization */
                $utilization = is_array($windows) && is_array($windows[$name] ?? null) ? ($windows[$name]['utilization'] ?? null) : null;
                $percent = self::percent($utilization, true);
                if ($percent !== null) {
                    $used[] = $percent;
                }
            }
            $using = $used !== [] && max($used) >= $reserve;
        }
        $this->record(self::key($oauthToken), $using, $resetsAt);
        return ['using' => $using, 'resetsAt' => $resetsAt];
    }

    /**
     * The usage of the plan as the CLI reports it: paid use is drawn when extra usage is on and a window of the
     * whole account is full.
     *
     * @param array<array-key, mixed> $limits
     * @return array{using: bool, resetsAt: ?float, until: float}
     */
    public function observeUsage(string $key, array $limits, float $threshold = 100.0): array {
        $full = [];
        /** @var mixed $entries */
        $entries = $limits['limits'] ?? null;
        if (is_array($entries) && $entries !== []) {
            /** @var mixed $entry */
            foreach ($entries as $entry) {
                if (is_array($entry) && in_array($entry['kind'] ?? null, ['session', 'weekly_all'], true) && (self::percent($entry['percent'] ?? null, false) ?? 0.0) >= $threshold) {
                    $full[] = self::time($entry['resets_at'] ?? null);
                }
            }
        } else {
            foreach (['five_hour', 'seven_day'] as $name) {
                /** @var mixed $window */
                $window = $limits[$name] ?? null;
                if (is_array($window) && (self::percent($window['utilization'] ?? null, true) ?? 0.0) >= $threshold) {
                    $full[] = self::time($window['resets_at'] ?? null);
                }
            }
        }
        /** @var mixed $extra */
        $extra = $limits['extra_usage'] ?? null;
        $times = array_filter($full, static fn(?float $time): bool => $time !== null);
        $resetsAt = $times === [] ? null : max($times);
        return $this->record($key, is_array($extra) && ($extra['is_enabled'] ?? false) === true && $full !== [], $resetsAt);
    }

    /**
     * One question to the CLI per sign-in at a time: calls that wait for it share its answer instead of starting a
     * process each.
     *
     * @return array{?array<array-key, mixed>, string}
     */
    private function ask(string $key, #[SensitiveParameter] ?string $oauthToken): array {
        $asking = $this->asking[$key] ?? null;
        if ($asking !== null) {
            return $asking->await();
        }
        /** @var DeferredFuture<array{?array<array-key, mixed>, string}> $deferred */
        $deferred = new DeferredFuture();
        $this->asking[$key] = $deferred->getFuture();
        try {
            try {
                $answer = ($this->probe)($oauthToken);
            } catch (Throwable) {
                $answer = [null, 'Claude Code gave no usage answer'];
            }
            $deferred->complete($answer);
            return $answer;
        } finally {
            unset($this->asking[$key]);
        }
    }

    /** @return ?array{using: bool, resetsAt: ?float, until: float} the state that still stands */
    public function known(#[SensitiveParameter] ?string $oauthToken): ?array {
        $known = $this->state[self::key($oauthToken)] ?? null;
        return $known !== null && ($this->clock)() < $known['until'] ? $known : null;
    }

    /** @return array{using: bool, resetsAt: ?float, until: float} */
    private function record(string $key, bool $using, ?float $resetsAt): array {
        $now = ($this->clock)();
        if (count($this->state) > 64) {
            $this->state = [];
        }
        return $this->state[$key] = ['using' => $using, 'resetsAt' => $resetsAt, 'until' => $using && $resetsAt !== null && $resetsAt > $now ? $resetsAt : $now + self::RECHECK];
    }

    /**
     * A refusal in the form the API itself refuses a used-up window, so the application reads both the same way.
     */
    private static function refused(string $message, ?float $resetsAt, float $now): CallFailed {
        $headers = ['anthropic-ratelimit-unified-status' => 'rejected'];
        if ($resetsAt !== null && $resetsAt > $now) {
            $headers['anthropic-ratelimit-unified-reset'] = (string) (int) round($resetsAt);
            $headers['retry-after'] = (string) max(1, (int) ceil($resetsAt - $now));
            $message .= ' The limit resets at ' . gmdate('Y-m-d\TH:i:s\Z', (int) round($resetsAt)) . '.';
        }
        return new CallFailed(429, 'rate_limit_error', $message, null, $headers);
    }

    /** Seconds of the epoch; the CLI sends seconds, milliseconds are taken too. */
    private static function epoch(mixed $value): ?float {
        if (!is_int($value) && !is_float($value) || $value <= 0) {
            return null;
        }
        $value = (float) $value;
        return $value > 1e11 ? $value / 1000.0 : $value;
    }

    private static function time(mixed $value): ?float {
        if (!is_string($value) || trim($value) === '') {
            return self::epoch($value);
        }
        try {
            return (float) (new DateTimeImmutable(trim($value)))->format('U.u');
        } catch (Throwable) {
            return null;
        }
    }

    /** A number as a percent of 0–100; the older windows send a fraction of 0–1. */
    private static function percent(mixed $value, bool $fraction): ?float {
        if (!is_int($value) && !is_float($value) || !is_finite((float) $value)) {
            return null;
        }
        $value = (float) $value;
        return $fraction && $value <= 1.0 ? $value * 100.0 : $value;
    }
}
