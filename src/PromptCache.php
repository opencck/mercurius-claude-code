<?php

declare(strict_types=1);

namespace Mercurius\ClaudeCode;

use RuntimeException;
use stdClass;
use Throwable;

/**
 * The request of the CLI, made fit for the prompt cache before it leaves. Claude Code puts its per-request context
 * where the next request does not have it: today's date in a trailing system message, a reminder before the newest
 * reply of the user, a note inside the last tool result — and the cache mark on that changing tail. Each request
 * then writes the whole history again and reads none of it. Here the date goes back into the environment message,
 * the reminders before the reply are dropped, the tool results are restored to what the application sent, and the
 * mark moves to the last block the next request repeats. What the model reads changes only by that context of the
 * CLI; a request whose shape is not the one expected goes as the CLI built it.
 *
 * spec: brief/models.md#claude-по-подписке
 * ref: references/hermes-official-plugins/claude-subscription-directsdk/admission.py@269ddcb:L128-L563
 *      (_plain, _breakpoints, _frame_first, stable_request_context, _opens_turn, _keep_turn_mark,
 *      pin_message_breakpoint, drop_message_breakpoints, restore_queried_turn) — the same transforms in the same
 *      order; the body is kept as JSON objects so `{}` stays `{}`, and the blocks are compared by their canonical
 *      form, as Python compares dicts
 *
 * @psalm-suppress MixedAssignment the request is JSON of any shape: every value is checked where it is used
 */
final class PromptCache {
    private const UNCACHEABLE = ['thinking', 'redacted_thinking'];
    /** how far back the API looks from a breakpoint for an entry */
    private const LOOKBACK = 20;
    private const MAX_BREAKPOINTS = 4;
    /** the only trailing system text moved: the date line, bare or in a reminder, as the CLI ends its environment */
    private const DATE_LINE = "~^(?:<system-reminder>\n)?Today's date is \\d{4}-\\d{2}-\\d{2}\\.(?:\n</system-reminder>)?$~";

    /**
     * @param list<stdClass> $queried the reply the application sent last: the frame the CLI was asked about
     * @param bool $oneShot no tools and one reply: nothing will repeat the messages, so they carry no marks
     * @param ?string $note why the tool results were not restored, when they were not
     * @return string the body to send
     */
    public static function shape(string $payload, array $queried, bool $oneShot, ?string &$note = null): string {
        try {
            /** @var mixed $body */
            $body = json_decode($payload, false, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return $payload;
        }
        if (!$body instanceof stdClass || !is_array(self::get($body, 'messages'))) {
            return $payload;
        }
        $changed = false;
        $steps = [];
        if ($oneShot) {
            $steps[] = static fn(stdClass $b): bool => self::dropMessageMarks($b);
        }
        $steps[] = static function (stdClass $b) use ($queried, &$note): bool {
            try {
                return self::restoreQueriedTurn($b, $queried);
            } catch (Mismatch $e) {
                // restoration is hygiene, not a gate: the turn goes as the CLI built it, with an uncached tail
                $note = $e->getMessage();
                return false;
            }
        };
        $steps[] = static fn(stdClass $b): bool => self::stableRequestContext($b, $queried);
        $steps[] = static fn(stdClass $b): bool => self::pinMessageBreakpoint($b, $queried);
        foreach ($steps as $step) {
            // each step works on a copy: a step that fails halfway leaves nothing of itself
            $copy = self::copy($body);
            if (!$copy instanceof stdClass) {
                continue;
            }
            try {
                if ($step($copy)) {
                    $body = $copy;
                    $changed = true;
                }
            } catch (Mismatch | RuntimeException | \TypeError | \ValueError) {
                continue;
            }
        }
        return $changed ? json_encode($body, MessagesRequest::JSON) : $payload;
    }

    /** Message marks of a request nothing will repeat; marks of the system and the tools stay. */
    public static function dropMessageMarks(stdClass $body): bool {
        $dropped = false;
        foreach (self::messages($body) as $message) {
            foreach (self::items($message) ?? [] as $block) {
                if ($block instanceof stdClass && property_exists($block, 'cache_control')) {
                    unset($block->cache_control);
                    $dropped = true;
                }
            }
        }
        return $dropped;
    }

    /**
     * The date back into the environment message, the CLI's reminders off the newest reply of the user.
     *
     * @param list<stdClass> $queried
     */
    public static function stableRequestContext(stdClass $body, array $queried): bool {
        $messages = self::messages($body);
        foreach ($messages as $message) {
            $content = self::get($message, 'content');
            if (!in_array(self::str($message, 'role'), ['system', 'user', 'assistant'], true) || !(is_string($content) || is_array($content))) {
                return false;
            }
            foreach (self::items($message) ?? [] as $block) {
                if (!$block instanceof stdClass || self::type($block) === null || (self::type($block) === 'text' && self::str($block, 'text') === null)) {
                    return false;
                }
            }
        }
        $count = count($messages);
        if ($count === 0) {
            return false;
        }
        $changed = false;
        $last = $messages[$count - 1];
        $env = null;
        foreach (array_slice($messages, 0, -1) as $message) {
            if (self::str($message, 'role') === 'system') {
                $env = $message;
                break;
            }
        }
        $content = self::get($last, 'content');
        $items = self::items($last);
        $tail = is_string($content) ? self::text($content) : ($items !== null && count($items) === 1 ? $items[0] : null);
        $tailText = self::type($tail) === 'text' ? self::str($tail, 'text') : null;
        $first = $items !== null && count($items) > 1 ? $items[0] : null;
        $firstText = self::type($first) === 'text' ? self::str($first, 'text') : null;
        $system = self::str($last, 'role') === 'system';
        if ($system && $env !== null && $tail instanceof stdClass && $tailText !== null && self::isDate($tailText)) {
            if (self::appendToEnvironment($env, $tailText)) {
                $mark = self::get($tail, 'cache_control') ?? self::get($last, 'cache_control');
                array_pop($messages);
                $body->messages = $messages;
                $previous = $messages === [] ? null : $messages[array_key_last($messages)];
                if ($previous instanceof stdClass) {
                    self::setMark(self::items($previous), $mark);
                }
                $changed = true;
            }
        } elseif ($system && $env !== null && $items !== null && $first instanceof stdClass && $firstText !== null && self::isDate($firstText)) {
            // the date first in the same trailing message as a reminder of the step: only the date moves
            if (self::appendToEnvironment($env, $firstText)) {
                $last->content = array_slice($items, 1);
                $previous = $count >= 2 ? $messages[$count - 2] : null;
                if ($previous instanceof stdClass) {
                    self::setMark(self::items($previous), self::get($first, 'cache_control'));
                }
                $changed = true;
            }
        }
        $messages = self::messages($body);
        $newest = null;
        foreach (array_reverse($messages) as $message) {
            if (self::str($message, 'role') === 'user') {
                $newest = $message;
                break;
            }
        }
        $blocks = $newest === null ? null : self::items($newest);
        $hasAssistant = array_filter($messages, static fn(stdClass $m): bool => self::str($m, 'role') === 'assistant') !== [];
        $notOnlyResults = array_filter($queried, static fn(stdClass $b): bool => self::type($b) !== 'tool_result') !== [];
        if ($newest !== null && $blocks !== null && $queried !== [] && $notOnlyResults && $hasAssistant) {
            $k = self::findOnce($blocks, $queried);
            if ($k !== null && $k > 0 && self::allPlainText(array_slice($blocks, 0, $k))) {
                $mark = self::firstMark(array_slice($blocks, 0, $k));
                $blocks = array_slice($blocks, $k);
                self::setMark($blocks, $mark, true);
                $newest->content = $blocks;
                $changed = true;
            }
        }
        return $changed;
    }

    /**
     * The recurring message mark on content the next request repeats; the opening turn without the context the CLI
     * put before it; one more mark where a long loop of tools would lose the start of the turn.
     *
     * @param list<stdClass> $queried
     */
    public static function pinMessageBreakpoint(stdClass $body, array $queried): bool {
        if ($queried === []) {
            return false;
        }
        $messages = self::messages($body);
        $reordered = self::frameFirst($messages, $queried);
        $blocks = self::blocks($messages);
        $marked = array_values(array_filter($blocks, static fn(array $p): bool => property_exists($p[2], 'cache_control')));
        if ($marked === [] && !$reordered) {
            return false;
        }
        $last = self::lastAssistant($messages);
        $stable = array_values(array_filter($blocks, static fn(array $p): bool => $p[0] <= $last));
        $newest = $messages[max(0, $last + 1)] ?? null;
        if ($last + 1 >= count($messages)) {
            $newest = null;
        }
        $newestItems = $newest === null ? null : self::items($newest);
        if ($newest !== null && $newestItems !== null && self::str($newest, 'role') === 'user') {
            $prefix = [];
            foreach ($newestItems as $j => $sent) {
                if (!$sent instanceof stdClass || !isset($queried[$j]) || !self::same($sent, $queried[$j])) {
                    break;
                }
                $prefix[] = [$last + 1, $j, $sent];
            }
            $rest = array_slice($newestItems, count($prefix));
            if (!($rest !== [] && self::type($rest[0]) === 'tool_result')) {
                $stable = [...$stable, ...$prefix];
            }
        }
        $target = null;
        foreach (array_reverse($stable) as $p) {
            if (!in_array(self::type($p[2]), self::UNCACHEABLE, true)) {
                $target = $p;
                break;
            }
        }
        if ($target === null) {
            return $reordered;
        }
        $after = array_values(array_filter($marked, static fn(array $p): bool => [$target[0], $target[1]] < [$p[0], $p[1]]));
        $folded = $after !== [];
        if ($folded) {
            $moved = null;
            foreach ($after as $p) {
                $mark = self::get($p[2], 'cache_control');
                unset($p[2]->cache_control);
                $moved ??= $mark;
            }
            if (!property_exists($target[2], 'cache_control')) {
                $target[2]->cache_control = $moved;
            }
        }
        $turn = self::keepTurnMark($body, $messages, $last, $target);
        return $reordered || $folded || $turn;
    }

    /**
     * The newest turn of tool results as the application sent them: the CLI adds its notes to the last result and
     * may join or split text. Only a frame anchored by the same ids of tool calls, in order, is restored.
     *
     * @param list<stdClass> $queried
     * @throws Mismatch
     */
    public static function restoreQueriedTurn(stdClass $body, array $queried): bool {
        if (array_filter($queried, static fn(stdClass $b): bool => self::type($b) === 'tool_result') === []) {
            return false;
        }
        $messages = self::messages($body);
        $next = self::lastAssistant($messages) + 1;
        $newest = $next < count($messages) ? $messages[$next] : null;
        $native = $newest !== null && self::str($newest, 'role') === 'user' ? self::items($newest) : null;
        if ($newest === null || $native === null) {
            throw new Mismatch('The request of Claude Code does not uniquely match the tool results of the application');
        }
        $resultIds = self::resultIds($queried);
        foreach ($resultIds as $id) {
            if ($id === null || $id === '') {
                throw new Mismatch('A tool result without an id');
            }
        }
        if (count(array_unique($resultIds)) !== count($resultIds) || $resultIds !== self::resultIds($native)) {
            throw new Mismatch('The tool results of Claude Code are not the ones the application sent');
        }
        $restored = self::restoreSequence($native, $queried);
        if (self::canon($native) === self::canon($restored)) {
            return false;
        }
        $newest->content = $restored;
        return true;
    }

    /**
     * @param list<mixed> $sent
     * @param list<mixed> $host
     * @return list<mixed>
     * @throws Mismatch
     */
    private static function restoreSequence(array $sent, array $host): array {
        $restored = [];
        $i = 0;
        $j = 0;
        while ($i < count($host)) {
            if ($j >= count($sent)) {
                throw new Mismatch('The CLI dropped a block of the tool results');
            }
            if (self::bareText($host[$i]) && self::bareText($sent[$j])) {
                $hostEnd = $i + 1;
                $sentEnd = $j + 1;
                while ($hostEnd < count($host) && self::bareText($host[$hostEnd])) {
                    $hostEnd++;
                }
                while ($sentEnd < count($sent) && self::bareText($sent[$sentEnd])) {
                    $sentEnd++;
                }
                $original = self::joined(array_slice($host, $i, $hostEnd - $i));
                $actual = self::joined(array_slice($sent, $j, $sentEnd - $j));
                // an addition before a protected block of the application is an insertion, not a suffix
                if (!($hostEnd === count($host) ? str_starts_with($actual, $original) : $actual === $original)) {
                    throw new Mismatch('The text of the tool results changed');
                }
                foreach (array_slice($host, $i, $hostEnd - $i) as $block) {
                    $restored[] = self::copy($block);
                }
                $i = $hostEnd;
                $j = $sentEnd;
            } else {
                $restored[] = self::restoreBlock($sent[$j], $host[$i]);
                $i++;
                $j++;
            }
        }
        foreach (array_slice($sent, $j) as $extra) {
            if (!self::bareText($extra)) {
                throw new Mismatch('The CLI added a block that is not text');
            }
        }
        return $restored;
    }

    /** @throws Mismatch */
    private static function restoreBlock(mixed $sent, mixed $host): stdClass {
        if (!$sent instanceof stdClass || !$host instanceof stdClass) {
            throw new Mismatch('A block of the tool results is not an object');
        }
        $restored = self::copy($host);
        if (!$restored instanceof stdClass) {
            throw new Mismatch('A block of the tool results could not be copied');
        }
        if (self::type($host) === 'tool_result') {
            $left = self::get($sent, 'is_error') ?? false;
            $right = self::get($host, 'is_error') ?? false;
            if (!is_bool($left) || !is_bool($right) || $left !== $right) {
                throw new Mismatch('The error flag of a tool result changed');
            }
            $strip = static function (stdClass $b): stdClass {
                $c = clone $b;
                unset($c->content, $c->is_error, $c->cache_control);
                return $c;
            };
            if (self::canon($strip($sent)) !== self::canon($strip($host))) {
                throw new Mismatch('A tool result changed');
            }
            if (property_exists($sent, 'content') !== property_exists($host, 'content')) {
                throw new Mismatch('The content of a tool result appeared or disappeared');
            }
            if (property_exists($host, 'content')) {
                $restored->content = self::restoreContent(self::get($sent, 'content'), self::get($host, 'content'));
            }
        } elseif (!self::same($sent, $host)) {
            throw new Mismatch('A block of the tool results changed');
        }
        if (property_exists($sent, 'cache_control')) {
            if (property_exists($host, 'cache_control') && self::canon(self::get($sent, 'cache_control')) !== self::canon(self::get($host, 'cache_control'))) {
                throw new Mismatch('A cache mark of the tool results changed');
            }
            $restored->cache_control = self::copy(self::get($sent, 'cache_control'));
        }
        return $restored;
    }

    /** @throws Mismatch */
    private static function restoreContent(mixed $sent, mixed $host): mixed {
        if (is_string($host) || is_string($sent)) {
            $left = self::flatText($sent);
            $right = self::flatText($host);
            if ($left === null || $right === null || !str_starts_with($left, $right)) {
                throw new Mismatch('The text of a tool result changed');
            }
            return self::copy($host);
        }
        if (is_array($sent) && is_array($host)) {
            return self::restoreSequence(array_values($sent), array_values($host));
        }
        if (self::canon($sent) !== self::canon($host)) {
            throw new Mismatch('The content of a tool result changed');
        }
        return self::copy($host);
    }

    /** Text with no metadata and no marks, as one string; null — not such text. */
    private static function flatText(mixed $value): ?string {
        if (is_string($value)) {
            return $value;
        }
        if (!is_array($value)) {
            return null;
        }
        foreach ($value as $block) {
            if (!self::bareText($block)) {
                return null;
            }
        }
        return self::joined(array_values($value));
    }

    /**
     * The first turn of a conversation as every later request repeats it: without the context the CLI put before
     * the frame of the application.
     *
     * @param list<stdClass> $messages
     * @param list<stdClass> $queried
     */
    private static function frameFirst(array $messages, array $queried): bool {
        if (self::lastAssistant($messages) >= 0) {
            return false;
        }
        $first = null;
        foreach ($messages as $message) {
            if (self::str($message, 'role') === 'user') {
                $first = $message;
                break;
            }
        }
        $content = $first === null ? null : self::items($first);
        if ($first === null || $content === null || $queried === [] || count($content) <= count($queried)) {
            return false;
        }
        $k = self::findOnce($content, $queried);
        if ($k === null || $k === 0 || !self::allPlainText(array_slice($content, 0, $k))) {
            return false;
        }
        $mark = self::firstMark(array_slice($content, 0, $k));
        $content = array_slice($content, $k);
        self::setMark($content, $mark, true);
        $first->content = $content;
        return true;
    }

    /**
     * One more mark where the open turn's thinking starts, when a loop of tools is longer than the lookback of the
     * API: the next turn still reads that entry.
     *
     * @param list<stdClass> $messages
     * @param array{int, int, stdClass} $target
     */
    private static function keepTurnMark(stdClass $body, array $messages, int $last, array $target): bool {
        $opening = null;
        for ($i = 0; $i < $last; $i++) {
            if (self::opensTurn($messages[$i])) {
                $opening = $i;
            }
        }
        if ($opening === null) {
            return false;
        }
        $thought = null;
        for ($i = $opening + 1; $i <= $last; $i++) {
            $m = $messages[$i];
            if (self::str($m, 'role') === 'assistant'
                && array_filter(self::items($m) ?? [], static fn(mixed $b): bool => in_array(self::type($b), self::UNCACHEABLE, true)) !== []) {
                $thought = $i;
                break;
            }
        }
        if ($thought === null) {
            return false;
        }
        $frame = $opening;
        for ($i = $thought - 1; $i > $opening; $i--) {
            if (self::str($messages[$i] ?? null, 'role') === 'user') {
                $frame = $i;
                break;
            }
        }
        $holder = $messages[$frame] ?? null;
        $content = $holder === null ? null : self::items($holder);
        if ($content === null) {
            return false;
        }
        $j = null;
        for ($k = count($content) - 1; $k >= 0; $k--) {
            if ($content[$k] instanceof stdClass && !in_array(self::type($content[$k]), self::UNCACHEABLE, true)) {
                $j = $k;
                break;
            }
        }
        $block = $j === null ? null : $content[$j];
        if ($j === null || !$block instanceof stdClass || property_exists($block, 'cache_control') || [$frame, $j] >= [$target[0], $target[1]]) {
            return false;
        }
        $span = 0;
        foreach (array_slice($messages, $frame, $target[0] - $frame) as $m) {
            $span += count(self::items($m) ?? [null]);
        }
        if ($span - $j + $target[1] + 1 <= self::LOOKBACK) {
            return false;
        }
        $later = array_values(array_filter(self::blocks($messages), static fn(array $p): bool => property_exists($p[2], 'cache_control') && [$frame, $j] < [$p[0], $p[1]]));
        if ($later === []) {
            return false;
        }
        if (self::breakpoints($body) < self::MAX_BREAKPOINTS) {
            $block->cache_control = self::copy(self::get($later[0][2], 'cache_control'));
            return true;
        }
        $spare = null;
        foreach ($later as $p) {
            if ([$p[0], $p[1]] < [$target[0], $target[1]]) {
                $spare = $p[2];
                break;
            }
        }
        if ($spare === null || !property_exists($target[2], 'cache_control')) {
            return false;
        }
        $block->cache_control = self::get($spare, 'cache_control');
        unset($spare->cache_control);
        return true;
    }

    private static function opensTurn(stdClass $message): bool {
        if (self::str($message, 'role') !== 'user') {
            return false;
        }
        if (is_string(self::get($message, 'content'))) {
            return true;
        }
        return array_filter(self::items($message) ?? [], static fn(mixed $b): bool => self::type($b) !== 'tool_result') !== [];
    }

    /** Appends the date to the environment message, unless it ends with it already. */
    private static function appendToEnvironment(stdClass $env, string $line): bool {
        $original = self::get($env, 'content');
        if (is_string($original)) {
            if (str_ends_with($original, $line)) {
                return false;
            }
            $env->content = $original . "\n\n" . $line;
            return true;
        }
        $items = self::items($env);
        $target = $items === null || $items === [] ? null : $items[count($items) - 1];
        $text = self::type($target) === 'text' ? self::str($target, 'text') : null;
        if (!$target instanceof stdClass || $text === null || str_ends_with($text, $line)) {
            return false;
        }
        $target->text = $text . "\n\n" . $line;
        return true;
    }

    private static function isDate(string $text): bool {
        return preg_match(self::DATE_LINE, $text) === 1;
    }

    /**
     * Where the frame of the application starts in the blocks, when it is there exactly once.
     *
     * @param list<mixed> $blocks
     * @param list<stdClass> $frame
     */
    private static function findOnce(array $blocks, array $frame): ?int {
        $want = array_map(static fn(stdClass $b): string => self::canon(self::plain($b)), $frame);
        $hits = [];
        for ($i = 0; $i <= count($blocks) - count($frame); $i++) {
            $have = array_map(static fn(mixed $b): string => self::canon(self::plain($b)), array_slice($blocks, $i, count($frame)));
            if ($have === $want) {
                $hits[] = $i;
            }
        }
        return count($hits) === 1 ? $hits[0] : null;
    }

    /** @param list<mixed> $blocks */
    private static function allPlainText(array $blocks): bool {
        foreach ($blocks as $b) {
            if (!$b instanceof stdClass || self::type($b) !== 'text' || array_diff(array_keys(get_object_vars($b)), ['type', 'text', 'cache_control']) !== []) {
                return false;
            }
        }
        return true;
    }

    /** @param list<mixed> $blocks */
    private static function firstMark(array $blocks): mixed {
        foreach ($blocks as $b) {
            if ($b instanceof stdClass && property_exists($b, 'cache_control')) {
                return self::get($b, 'cache_control');
            }
        }
        return null;
    }

    /**
     * A mark onto the last (or the first) block, unless it has one: marks move, they never multiply.
     *
     * @param ?list<mixed> $content
     */
    private static function setMark(?array $content, mixed $mark, bool $first = false): void {
        if ($mark === null || $content === null || $content === []) {
            return;
        }
        $block = $first ? $content[0] : $content[count($content) - 1];
        if ($block instanceof stdClass && !property_exists($block, 'cache_control')) {
            $block->cache_control = $mark;
        }
    }

    private static function bareText(mixed $b): bool {
        if (!$b instanceof stdClass) {
            return false;
        }
        $keys = array_keys(get_object_vars($b));
        sort($keys);
        return $keys === ['text', 'type'] && self::type($b) === 'text' && self::str($b, 'text') !== null;
    }

    /** @param list<mixed> $blocks */
    private static function joined(array $blocks): string {
        $text = '';
        foreach ($blocks as $b) {
            $text .= self::str($b, 'text') ?? '';
        }
        return $text;
    }

    /**
     * @param list<mixed> $blocks
     * @return list<?string>
     */
    private static function resultIds(array $blocks): array {
        $ids = [];
        foreach ($blocks as $b) {
            if (self::type($b) === 'tool_result') {
                $ids[] = self::str($b, 'tool_use_id');
            }
        }
        return $ids;
    }

    /** How many cache marks the body carries, nested ones included. */
    private static function breakpoints(mixed $value): int {
        $count = 0;
        $pending = [$value];
        while ($pending !== []) {
            $node = array_pop($pending);
            if ($node instanceof stdClass) {
                foreach (array_keys(get_object_vars($node)) as $key) {
                    if ($key === 'cache_control') {
                        $count++;
                    } else {
                        $pending[] = self::get($node, $key);
                    }
                }
            } elseif (is_array($node)) {
                foreach (array_values($node) as $child) {
                    $pending[] = $child;
                }
            }
        }
        return $count;
    }

    /**
     * @param list<stdClass> $messages
     * @return list<array{int, int, stdClass}> every object block of the messages with list content, with its place
     */
    private static function blocks(array $messages): array {
        $blocks = [];
        foreach ($messages as $i => $message) {
            foreach (self::items($message) ?? [] as $j => $block) {
                if ($block instanceof stdClass) {
                    $blocks[] = [$i, $j, $block];
                }
            }
        }
        return $blocks;
    }

    /** @param list<stdClass> $messages */
    private static function lastAssistant(array $messages): int {
        $last = -1;
        foreach ($messages as $i => $message) {
            if (self::str($message, 'role') === 'assistant') {
                $last = $i;
            }
        }
        return $last;
    }

    /** @return list<stdClass> */
    private static function messages(stdClass $body): array {
        $messages = self::get($body, 'messages');
        if (!is_array($messages)) {
            return [];
        }
        $list = [];
        foreach (array_values($messages) as $message) {
            if (!$message instanceof stdClass) {
                throw new RuntimeException('A message is not an object');
            }
            $list[] = $message;
        }
        return $list;
    }

    /** @return ?list<mixed> the blocks of a message (or of a tool result); null — its content is not a list */
    private static function items(stdClass $holder): ?array {
        $content = self::get($holder, 'content');
        return is_array($content) ? array_values($content) : null;
    }

    private static function get(mixed $object, string $key): mixed {
        if (!$object instanceof stdClass || !property_exists($object, $key)) {
            return null;
        }
        /** @psalm-suppress MixedAssignment a field of JSON is any of its types; the callers check it */
        $value = $object->{$key};
        return $value;
    }

    private static function str(mixed $object, string $key): ?string {
        $value = self::get($object, $key);
        return is_string($value) ? $value : null;
    }

    private static function type(mixed $block): ?string {
        return self::str($block, 'type');
    }

    private static function text(string $text): stdClass {
        $block = new stdClass();
        $block->type = 'text';
        $block->text = $text;
        return $block;
    }

    private static function same(mixed $a, mixed $b): bool {
        return self::canon(self::plain($a)) === self::canon(self::plain($b));
    }

    /** A block without its cache mark (the top level only, as the reference does). */
    private static function plain(mixed $block): mixed {
        if (!$block instanceof stdClass) {
            return $block;
        }
        $copy = clone $block;
        unset($copy->cache_control);
        return $copy;
    }

    /** The JSON of a value with the keys of every object sorted: equal values, equal strings. */
    private static function canon(mixed $value): string {
        return json_encode(self::sorted($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
    }

    private static function sorted(mixed $value): mixed {
        if ($value instanceof stdClass) {
            $fields = [];
            foreach (array_keys(get_object_vars($value)) as $key) {
                $fields[$key] = self::sorted(self::get($value, $key));
            }
            ksort($fields, SORT_STRING);
            return ['{}' => $fields];
        }
        if (is_array($value)) {
            $list = [];
            foreach ($value as $key => $child) {
                $list[$key] = self::sorted($child);
            }
            return $list;
        }
        return $value;
    }

    private static function copy(mixed $value): mixed {
        return json_decode(json_encode($value, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION), false, 512, JSON_THROW_ON_ERROR);
    }
}
