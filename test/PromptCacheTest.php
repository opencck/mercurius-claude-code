<?php

declare(strict_types=1);

namespace Mercurius\ClaudeCode\Test;

use Mercurius\ClaudeCode\Mismatch;
use Mercurius\ClaudeCode\PromptCache;
use PHPUnit\Framework\TestCase;
use stdClass;

/**
 * The request of the CLI made fit for the cache: what the next request repeats keeps its prefix, and the content
 * the model reads changes only by the context of the CLI.
 *
 * ref: references/hermes-official-plugins/claude-subscription-directsdk/tests/test_admission_stable_context.py@269ddcb,
 *      test_admission_breakpoint.py, test_admission_restoration.py, test_admission_first_turn.py,
 *      test_admission_one_shot.py — the same cases, on the shapes Claude Code 2.1.330 sends
 */
final class PromptCacheTest extends TestCase {
    private const ENV = 'Fixture environment';
    private const DATE = "<system-reminder>\nToday's date is 2031-01-01.\n</system-reminder>";
    private const MARK = ['type' => 'ephemeral', 'ttl' => '1h'];
    private const FRAME = ['type' => 'text', 'text' => 'Next user question'];
    private const REMINDER = ['type' => 'text', 'text' => 'Native context reminder'];
    private const ASSISTANT = ['role' => 'assistant', 'content' => [['type' => 'thinking', 'thinking' => 'r', 'signature' => 's'], ['type' => 'text', 'text' => 'Previous answer']]];
    private const RESULT = ['type' => 'tool_result', 'tool_use_id' => 't1', 'content' => 'real output'];
    private const CALL = ['role' => 'assistant', 'content' => [['type' => 'tool_use', 'id' => 't1', 'name' => 'mcp__mercurius__probe', 'input' => []]]];

    /** @param list<array<string, mixed>> $messages */
    private static function wire(array $messages): string {
        return (string) json_encode(['model' => 'm', 'messages' => $messages], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * @param list<array<string, mixed>> $blocks
     * @return list<stdClass>
     */
    private static function objects(array $blocks): array {
        $out = [];
        /** @var mixed $block */
        foreach ((array) json_decode((string) json_encode($blocks), false) as $block) {
            if ($block instanceof stdClass) {
                $out[] = $block;
            }
        }
        return $out;
    }

    /** @return list<array<string, mixed>> */
    private static function messages(string $payload): array {
        /** @var array{messages: list<array<string, mixed>>} $body */
        $body = json_decode($payload, true);
        return $body['messages'];
    }

    /** @return list<array{int, int}> */
    private static function marks(string $payload): array {
        $found = [];
        foreach (self::messages($payload) as $i => $m) {
            /** @var mixed $content */
            $content = $m['content'] ?? null;
            /** @var mixed $b */
            foreach (is_array($content) ? array_values($content) : [] as $j => $b) {
                if (is_array($b) && isset($b['cache_control'])) {
                    $found[] = [$i, $j];
                }
            }
        }
        return $found;
    }

    /**
     * @param list<array<string, mixed>> $messages
     * @param list<array<string, mixed>> $queried
     */
    private static function stable(array $messages, array $queried): string {
        $body = json_decode(self::wire($messages), false);
        assert($body instanceof stdClass);
        PromptCache::stableRequestContext($body, self::objects($queried));
        return (string) json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    public function testTheDateReturnsToTheEnvironmentAndTheReminderLeavesTheNewestTurn(): void {
        foreach ([self::DATE, [['type' => 'text', 'text' => self::DATE]], "Today's date is 2031-01-01."] as $tail) {
            $line = is_string($tail) ? $tail : $tail[0]['text'];
            $out = self::messages(self::stable([
                ['role' => 'system', 'content' => self::ENV], self::ASSISTANT,
                ['role' => 'user', 'content' => [self::REMINDER, self::FRAME]],
                ['role' => 'system', 'content' => $tail],
            ], [self::FRAME]));
            self::assertCount(3, $out);
            self::assertSame(self::ENV . "\n\n" . $line, $out[0]['content']);
            self::assertSame([self::FRAME], $out[2]['content']);
        }
        $list = self::messages(self::stable([
            ['role' => 'system', 'content' => [['type' => 'text', 'text' => 'Earlier'], ['type' => 'text', 'text' => self::ENV]]], self::ASSISTANT,
            ['role' => 'user', 'content' => [self::FRAME]], ['role' => 'system', 'content' => self::DATE],
        ], [self::FRAME]));
        self::assertSame([['type' => 'text', 'text' => 'Earlier'], ['type' => 'text', 'text' => self::ENV . "\n\n" . self::DATE]], $list[0]['content']);
    }

    public function testOnlyTheDateMovesAndTheMarkMovesWithItOnce(): void {
        $reminder = ['type' => 'text', 'text' => 'First privately list what you need next'];
        $out = self::messages(self::stable([
            ['role' => 'system', 'content' => self::ENV], self::ASSISTANT, ['role' => 'user', 'content' => [self::FRAME]],
            ['role' => 'system', 'content' => [['type' => 'text', 'text' => self::DATE], $reminder]],
        ], [self::FRAME]));
        self::assertSame(self::ENV . "\n\n" . self::DATE, $out[0]['content']);
        self::assertSame(['role' => 'system', 'content' => [$reminder]], $out[3]);

        foreach (["Today's date is 2031-01-01. Now 09:30.", 'Per-request note'] as $other) {
            $kept = self::messages(self::stable([['role' => 'system', 'content' => self::ENV], self::ASSISTANT, ['role' => 'user', 'content' => [self::FRAME]], ['role' => 'system', 'content' => $other]], [self::FRAME]));
            self::assertSame(self::ENV, $kept[0]['content'], 'other trailing text stays where it is');
        }

        $marked = self::messages(self::stable([
            ['role' => 'system', 'content' => self::ENV], self::ASSISTANT,
            ['role' => 'user', 'content' => [[...self::REMINDER, 'cache_control' => self::MARK], self::FRAME]],
            ['role' => 'system', 'content' => [['type' => 'text', 'text' => self::DATE, 'cache_control' => self::MARK]]],
        ], [self::FRAME]));
        self::assertSame([[...self::FRAME, 'cache_control' => self::MARK]], $marked[2]['content']);
        self::assertSame(1, substr_count((string) json_encode($marked), 'cache_control'), 'marks move, they never multiply');

        $twice = self::stable([['role' => 'system', 'content' => self::ENV . "\n\n" . self::DATE], self::ASSISTANT, ['role' => 'user', 'content' => [self::FRAME]], ['role' => 'system', 'content' => self::DATE]], [self::FRAME]);
        self::assertSame(self::ENV . "\n\n" . self::DATE, self::messages($twice)[0]['content'], 'an environment that has the date does not get it twice');
    }

    public function testConsecutiveRequestsShareTheirPrefixByteForByte(): void {
        $first = PromptCache::shape(self::wire([
            ['role' => 'system', 'content' => self::ENV], self::ASSISTANT,
            ['role' => 'user', 'content' => [self::REMINDER, self::FRAME]], ['role' => 'system', 'content' => self::DATE],
        ]), self::objects([self::FRAME]), false);
        $next = ['type' => 'text', 'text' => 'Follow-up question'];
        $second = PromptCache::shape(self::wire([
            ['role' => 'system', 'content' => self::ENV], self::ASSISTANT, ['role' => 'user', 'content' => [self::FRAME]], self::ASSISTANT,
            ['role' => 'user', 'content' => [self::REMINDER, $next]], ['role' => 'system', 'content' => self::DATE],
        ]), self::objects([$next]), false);
        $a = self::messages($first);
        self::assertSame(json_encode($a), json_encode(array_slice(self::messages($second), 0, count($a))));
    }

    public function testTheMarkMovesToTheLastBlockTheApplicationItselfSent(): void {
        $date = ['role' => 'system', 'content' => [['type' => 'text', 'text' => "Today's date is 2026-09-23.", 'cache_control' => ['type' => 'ephemeral']]]];
        $question = ['type' => 'text', 'text' => 'the real question'];
        $cases = [
            [[$question], [self::CALL, ['role' => 'user', 'content' => [$question, ['type' => 'text', 'text' => 'Session context', 'cache_control' => ['type' => 'ephemeral']]]]], [1, 0]],
            [[$question], [self::CALL, ['role' => 'user', 'content' => [['type' => 'text', 'text' => 'Any preamble'], [...$question, 'cache_control' => ['type' => 'ephemeral']]]]], [0, 0]],
            [[self::RESULT], [self::CALL, ['role' => 'user', 'content' => [self::RESULT]], $date], [1, 0]],
            [[self::RESULT], [self::CALL, ['role' => 'user', 'content' => [self::RESULT, ['type' => 'text', 'text' => 'Session context', 'cache_control' => ['type' => 'ephemeral']]]]], [1, 0]],
        ];
        foreach ($cases as $n => [$queried, $messages, $expected]) {
            $body = json_decode(self::wire($messages), false);
            assert($body instanceof stdClass);
            PromptCache::pinMessageBreakpoint($body, self::objects($queried));
            self::assertSame([$expected], self::marks((string) json_encode($body)), 'case ' . $n);
        }
    }

    public function testTheToolResultsAreRestoredToWhatTheApplicationSent(): void {
        $noted = ['type' => 'tool_result', 'tool_use_id' => 't1', 'content' => "real output\n<system-reminder>userEmail</system-reminder>"];
        $body = json_decode(self::wire([self::CALL, ['role' => 'user', 'content' => [$noted]], ['role' => 'system', 'content' => [['type' => 'text', 'text' => "Today's date is 2026-09-30."]]]]), false);
        assert($body instanceof stdClass);
        self::assertTrue(PromptCache::restoreQueriedTurn($body, self::objects([self::RESULT])));
        self::assertSame([self::RESULT], self::messages((string) json_encode($body))[1]['content']);

        $split = json_decode(self::wire([self::CALL, ['role' => 'user', 'content' => [['type' => 'tool_result', 'tool_use_id' => 't1', 'content' => [['type' => 'text', 'text' => 'real '], ['type' => 'text', 'text' => 'output and a note']]]]]]), false);
        assert($split instanceof stdClass);
        PromptCache::restoreQueriedTurn($split, self::objects([self::RESULT]));
        self::assertSame([self::RESULT], self::messages((string) json_encode($split))[1]['content'], 'the representation of the application comes back');

        foreach ([[...self::RESULT, 'is_error' => true], ['type' => 'tool_result', 'tool_use_id' => 'other', 'content' => 'real output'], ['type' => 'tool_result', 'tool_use_id' => 't1', 'content' => [['type' => 'text', 'text' => 'real output', 'unknown' => 'x']]]] as $changed) {
            $wrong = json_decode(self::wire([self::CALL, ['role' => 'user', 'content' => [$changed]]]), false);
            assert($wrong instanceof stdClass);
            $refused = false;
            try {
                PromptCache::restoreQueriedTurn($wrong, self::objects([self::RESULT]));
            } catch (Mismatch) {
                $refused = true;
            }
            self::assertTrue($refused, 'a changed tool result is not restored');
        }
    }

    public function testTheOpeningTurnLosesTheContextBeforeTheFrame(): void {
        $reminder = ['type' => 'text', 'text' => "<system-reminder>\nAs you answer the user's questions, you can use the following context\n</system-reminder>\n"];
        $out = PromptCache::shape(self::wire([
            ['role' => 'user', 'content' => [$reminder, self::FRAME]],
            ['role' => 'system', 'content' => [['type' => 'text', 'text' => self::ENV . "\n\nToday's date is 2026-09-30.", 'cache_control' => self::MARK]]],
        ]), self::objects([self::FRAME]), false);
        $messages = self::messages($out);
        self::assertSame([[...self::FRAME, 'cache_control' => self::MARK]], $messages[0]['content'], 'the frame alone, with the mark it now ends');
        self::assertStringNotContainsString('cache_control', (string) json_encode($messages[1]));
    }

    public function testACallNothingRepeatsCarriesNoMessageMarksAndBadInputGoesAsItWas(): void {
        $payload = (string) json_encode(['model' => 'm', 'system' => [['type' => 'text', 'text' => 'S', 'cache_control' => self::MARK]], 'messages' => [['role' => 'user', 'content' => [[...self::FRAME, 'cache_control' => self::MARK]]]]]);
        $out = PromptCache::shape($payload, self::objects([self::FRAME]), true);
        self::assertSame(1, substr_count($out, 'cache_control'), 'the mark of the system stays');
        self::assertStringContainsString('"system":[{"type":"text","text":"S","cache_control"', $out);

        foreach (['not json', '{}', 'null', '{"messages":[null]}'] as $bad) {
            self::assertSame($bad, PromptCache::shape($bad, self::objects([self::FRAME]), false));
        }
        $empty = self::wire([self::CALL, ['role' => 'user', 'content' => [self::RESULT]]]);
        self::assertStringContainsString('"input":{}', PromptCache::shape(str_replace('"input":[]', '"input":{}', $empty), self::objects([self::RESULT]), true), 'an empty object stays an object');
    }
}
