<?php

declare(strict_types=1);

namespace Mercurius\ClaudeCode\Test;

use Mercurius\ClaudeCode\Capture;
use Mercurius\ClaudeCode\MessagesRequest;
use Mercurius\ClaudeCode\Models;
use Mercurius\ClaudeCode\RequestRejected;
use Mercurius\ClaudeCode\Schema;
use Mercurius\ClaudeCode\ToolNames;
use PHPUnit\Framework\TestCase;
use stdClass;

/** The parts of a call that need no process: names of models and tools, schemas, the request, the capture. */
final class RequestTest extends TestCase {
    /** @param array<string, mixed> $body */
    private static function parse(array $body): MessagesRequest {
        return MessagesRequest::parse(json_encode($body, JSON_THROW_ON_ERROR));
    }

    public function testAModelGetsTheLongWindowOnlyWhenItIsPinnedWithOne(): void {
        self::assertSame('claude-sonnet-5-5[1m]', Models::native('sonnet'));
        self::assertSame('claude-sonnet-5-5[1m]', Models::native('sonnet-5.5'));
        self::assertSame('claude-opus-5-5[1m]', Models::native('claude-opus-5-5[1m]'));
        self::assertSame('claude-haiku-4-5-20251001', Models::native('haiku-4-5'));
        self::assertSame('claude-next-9', Models::native('claude-next-9'), 'an unknown model goes as it was named');
        self::assertFalse(Models::acceptsThinkingDisable('fable'));
        self::assertTrue(Models::acceptsThinkingDisable('claude-opus-5'));
        self::assertFalse(Models::supportsAdaptiveThinking('claude-haiku-4-5'));
    }

    public function testThinkingThatCannotBeTurnedOffBecomesTheLowestEffort(): void {
        $user = [['role' => 'user', 'content' => 'Hi']];
        $fable = self::parse(['model' => 'claude-fable-5-2', 'thinking' => ['type' => 'disabled'], 'messages' => $user]);
        self::assertSame('low', $fable->effort);
        self::assertSame('{"output_config":{"effort":"low"},"tools":[]}', $fable->body);

        $asked = self::parse(['model' => 'claude-fable-5-2', 'thinking' => ['type' => 'disabled'], 'output_config' => ['effort' => 'medium'], 'messages' => $user]);
        self::assertSame('medium', $asked->effort, 'an effort the application named stays');

        $opus = self::parse(['model' => 'claude-opus-5', 'thinking' => ['type' => 'disabled'], 'max_tokens' => 100, 'stop_sequences' => ['END'], 'messages' => $user]);
        self::assertNull($opus->effort);
        self::assertSame('{"max_tokens":100,"stop_sequences":["END"],"thinking":{"type":"disabled"},"context_management":{"edits":[]},"tools":[]}', $opus->body);

        $adaptive = self::parse(['model' => 'sonnet', 'thinking' => ['type' => 'adaptive', 'display' => 'summarized'], 'messages' => $user]);
        self::assertStringContainsString('"thinking":{"type":"adaptive","display":"summarized"}', $adaptive->body);
    }

    public function testAToolKeepsTheWishForStreamedArgumentsAndLosesItsCacheMark(): void {
        $request = self::parse(['model' => 'sonnet', 'messages' => [['role' => 'user', 'content' => 'Hi']], 'tools' => [
            ['name' => 'write', 'description' => 'Write', 'eager_input_streaming' => true, 'input_schema' => ['type' => 'object'], 'cache_control' => ['type' => 'ephemeral']],
            ['name' => 'read', 'description' => 'Read', 'input_schema' => ['type' => 'object']],
        ]]);
        self::assertSame(
            '{"tools":[{"name":"mcp__mercurius__write","description":"Write","input_schema":{"type":"object","properties":{}},"eager_input_streaming":true},'
            . '{"name":"mcp__mercurius__read","description":"Read","input_schema":{"type":"object","properties":{}}}]}',
            $request->body,
        );
    }

    public function testOneConversationIsOneSessionOfTheCli(): void {
        $first = self::parse(['model' => 'sonnet', 'system' => 'S', 'messages' => [['role' => 'user', 'content' => 'Hi']]]);
        $later = self::parse(['model' => 'sonnet', 'system' => 'S', 'messages' => [['role' => 'user', 'content' => 'Hi'], ['role' => 'assistant', 'content' => 'Hello'], ['role' => 'user', 'content' => 'More']]]);
        $other = self::parse(['model' => 'sonnet', 'system' => 'S', 'messages' => [['role' => 'user', 'content' => 'Bye']]]);

        self::assertMatchesRegularExpression('~^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$~', $first->sessionId());
        self::assertSame($first->sessionId(), $later->sessionId());
        self::assertNotSame($first->sessionId(), $other->sessionId());
        self::assertCount(3, $later->frames);
    }

    public function testNamesThatBecomeTheSameForTheCliAreRefused(): void {
        $names = new ToolNames();
        $long = str_repeat('a', 41) . str_repeat('b', 20);
        $short = $names->add($long);
        self::assertSame(50, strlen($short));
        self::assertSame($long, $names->restored('mcp__mercurius__' . $short));
        self::assertSame($long, $names->restored('mcp__' . $short), 'the model lost the name of the server');
        self::assertSame($long, $names->restored($short), 'the model lost the whole prefix');
        self::assertSame('not_offered', $names->restored('mcp__mercurius__not_offered'), 'a tool that was not offered stays the model\'s');
        self::assertSame('gone_tool', $names->sent('gone_tool'), 'a call of a tool that is not offered now is replayed as it was');
        $names->add('x');
        $names->add('mcp__x');
        self::assertSame('mcp__x', $names->restored('mcp__x'), 'an exact name is never taken for a lost prefix of another tool');

        $this->expectException(RequestRejected::class);
        $names->add($short);
    }

    public function testASchemaKeepsRealUnionsAndLosesOnlyWhatTheApiRefuses(): void {
        /** @var stdClass $schema */
        $schema = json_decode('{"anyOf":[{"required":["a"]},{"required":["b"]}],"properties":{"a":{"oneOf":[{"type":"null"},{"type":"array","items":{}}],"default":null},"b":{"anyOf":[{"type":"string"},{"type":"integer"}]},"c":{"anyOf":[{"type":"null"},{"$ref":"#/$defs/x"}],"default":1}}}');
        $before = json_encode($schema, JSON_THROW_ON_ERROR);

        self::assertSame(
            '{"properties":{"a":{"type":"array","items":{},"default":null},"b":{"anyOf":[{"type":"string"},{"type":"integer"}]},"c":{"$ref":"#/$defs/x"}},"type":"object"}',
            json_encode(Schema::normalize($schema), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
        );
        self::assertSame($before, json_encode($schema, JSON_THROW_ON_ERROR), 'the schema of the application is not changed');
    }

    public function testACaptureTakesEventsCutAnywhere(): void {
        $capture = new Capture(new ToolNames());
        $stream = "event: message_start\r\ndata: {\"type\":\"message_start\",\"message\":{\"id\":\"m\",\"content\":[],\"usage\":{\"input_tokens\":2}}}\r\n\r\n"
            . ": keep-alive\n\n"
            . "event: content_block_start\ndata: {\"type\":\"content_block_start\",\"index\":0,\"content_block\":{\"type\":\"thinking\",\"thinking\":\"\"}}\n\n"
            . "event: content_block_delta\ndata: {\"type\":\"content_block_delta\",\"index\":0,\"delta\":{\"type\":\"thinking_delta\",\"thinking\":\"Хм\"}}\n\n"
            . "event: content_block_delta\ndata: {\"type\":\"content_block_delta\",\"index\":0,\"delta\":{\"type\":\"signature_delta\",\"signature\":\"sig\"}}\n\n"
            . "event: content_block_stop\ndata: {\"type\":\"content_block_stop\",\"index\":0}\n\n"
            . "event: message_delta\ndata: {\"type\":\"message_delta\",\"delta\":{\"stop_reason\":\"end_turn\"},\"usage\":{\"output_tokens\":4}}\n\n";
        $frames = [];
        foreach (str_split($stream, 7) as $piece) {
            $frames = [...$frames, ...$capture->feed($piece)];
        }
        self::assertCount(7, $frames);
        self::assertSame(": keep-alive\n\n", $frames[1]);
        self::assertFalse($capture->complete(), 'no end of the message yet');

        $capture->feed("event: message_stop\ndata: {\"type\":\"message_stop\"}\n\n");
        self::assertTrue($capture->complete());
        self::assertSame(
            '{"id":"m","content":[{"type":"thinking","thinking":"Хм","signature":"sig"}],"usage":{"input_tokens":2,"output_tokens":4},"stop_reason":"end_turn"}',
            json_encode($capture->message(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
        );
    }
}
