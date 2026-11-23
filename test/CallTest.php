<?php

declare(strict_types=1);

namespace Mercurius\ClaudeCode\Test;

use Amp\Http\Server\DefaultErrorHandler;
use Amp\Http\Server\Driver\Client;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler\ClosureRequestHandler;
use Amp\Http\Server\Response;
use Amp\Http\Server\SocketHttpServer;
use Amp\PHPUnit\AsyncTestCase;
use Amp\Socket\InternetAddress;
use League\Uri\Http;
use Mercurius\ClaudeCode\ChildEnv;
use Mercurius\ClaudeCode\ClaudeCli;
use Mercurius\ClaudeCode\Limits;
use Mercurius\ClaudeCode\Login;
use Mercurius\ClaudeCode\ModelCall;
use Mercurius\ClaudeCode\Relay;
use Mercurius\ClaudeCode\Server;
use Mercurius\ClaudeCode\Usage;
use Psr\Log\NullLogger;

use function Amp\async;
use function Amp\ByteStream\buffer;
use function Amp\delay;

/**
 * One model call from end to end against a stand-in CLI and a stand-in API: the application gets the answer of the
 * API, the API gets one request per call whatever the CLI does, and nothing of a call is left behind.
 */
final class CallTest extends AsyncTestCase {
    private const TOKEN = 'token-0123456789abcdef';
    private const LONG_TOOL = 'a_tool_with_a_name_longer_than_the_fifty_characters_cli_takes';

    /** @var non-empty-string */
    private string $dir = 'unset';
    private ?SocketHttpServer $api = null;
    private ?Relay $relay = null;
    /** @var list<array{status: int, body: string, headers?: array<non-empty-string, string>}> the answers the API gives, in order */
    private array $script = [];
    /** @var list<array{headers: array<string, list<string>>, query: string, body: array<string, mixed>, raw: string}> */
    private array $seen = [];

    protected function setUp(): void {
        parent::setUp();
        $this->setTimeout(60);
        $this->dir = str_replace('\\', '/', sys_get_temp_dir()) . '/claude-code-call-' . bin2hex(random_bytes(6));
        mkdir($this->dir . '/tmp', 0700, true);
        // somebody is signed in: the stand-in CLI reads this file as its sign-in
        file_put_contents($this->dir . '/login.json', '{}');
    }

    protected function tearDown(): void {
        $this->relay?->stop();
        $this->api?->stop();
        self::removeTree($this->dir);
        parent::tearDown();
    }

    private static function removeTree(string $dir): void {
        $entries = scandir($dir);
        foreach ($entries === false ? [] : $entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            is_dir($dir . '/' . $entry) ? self::removeTree($dir . '/' . $entry) : @unlink($dir . '/' . $entry);
        }
        @rmdir($dir);
    }

    /** @param positive-int $concurrent */
    private function server(float $idle = 30.0, int $concurrent = 4, float $queue = 30.0): Server {
        $api = SocketHttpServer::createForDirectAccess(new NullLogger(), enableCompression: false);
        $api->expose(new InternetAddress('127.0.0.1', 0));
        $api->start(new ClosureRequestHandler(function (Request $request): Response {
            $raw = $request->getBody()->buffer();
            /** @var array<string, mixed> $body */
            $body = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
            $this->seen[] = ['headers' => $request->getHeaders(), 'query' => $request->getUri()->getQuery(), 'body' => $body, 'raw' => $raw];
            $answer = array_shift($this->script) ?? ['status' => 500, 'body' => '{"type":"error","error":{"type":"api_error","message":"the script of the test is over"}}'];
            $sse = str_starts_with($answer['body'], 'event:');
            return new Response($answer['status'], ['content-type' => $sse ? 'text/event-stream' : 'application/json', 'request-id' => 'req_test_1', ...($answer['headers'] ?? [])], $answer['body']);
        }), new DefaultErrorHandler());
        $this->api = $api;
        $base = [];
        foreach (['PATH', 'SystemRoot', 'SYSTEMROOT', 'TEMP', 'TMP', 'PATHEXT', 'COMSPEC'] as $name) {
            $value = getenv($name);
            if (is_string($value) && $value !== '') {
                $base[$name] = $value;
            }
        }
        $cli = new ClaudeCli([PHP_BINARY, __DIR__ . '/fixtures/fake-claude.php'], new ChildEnv($base, $this->dir), '2.1.321');
        $this->relay = new Relay(new NullLogger(), 'http://' . $api->getServers()[0]->getAddress()->toString(), 10.0);
        $usage = new Usage($cli, $this->relay, $this->dir . '/work');
        $limits = new Limits($usage->fetch(...));
        $calls = new ModelCall($cli, $this->relay, $limits, $this->dir . '/tmp', $this->dir . '/work', new NullLogger(), $idle, $concurrent, $queue);
        return new Server($cli, new Login($cli), $calls, $usage, $limits, self::TOKEN, new NullLogger());
    }

    /**
     * @param array<string, mixed> $body
     * @param array<non-empty-string, string> $headers
     */
    private function call(Server $server, array $body, array $headers = []): Response {
        $request = new Request($this->createMock(Client::class), 'POST', Http::new('http://claude-code/v1/messages'), ['x-api-key' => self::TOKEN, ...$headers], json_encode($body, JSON_THROW_ON_ERROR));
        return $server->respond($request);
    }

    /** @param list<array<string, mixed>> $events */
    private static function sse(array $events): string {
        $out = '';
        foreach ($events as $event) {
            /** @var mixed $type */
            $type = $event['type'] ?? null;
            $out .= sprintf("event: %s\ndata: %s\n\n", is_string($type) ? $type : '', json_encode($event, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        }
        return $out;
    }

    /** @return list<array<string, mixed>> the events of a text answer */
    private static function text(string $text, bool $whole = true): array {
        $events = [
            ['type' => 'message_start', 'message' => ['id' => 'msg_1', 'type' => 'message', 'role' => 'assistant', 'model' => 'claude-sonnet-5-5', 'content' => [], 'stop_reason' => null, 'usage' => ['input_tokens' => 12, 'cache_read_input_tokens' => 3, 'output_tokens' => 1]]],
            ['type' => 'content_block_start', 'index' => 0, 'content_block' => ['type' => 'text', 'text' => '']],
            ['type' => 'ping'],
            ['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'text_delta', 'text' => substr($text, 0, 3)]],
            ['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'text_delta', 'text' => substr($text, 3)]],
        ];
        return $whole ? [
            ...$events,
            ['type' => 'content_block_stop', 'index' => 0],
            ['type' => 'message_delta', 'delta' => ['stop_reason' => 'end_turn', 'stop_sequence' => null], 'usage' => ['output_tokens' => 7]],
            ['type' => 'message_stop'],
        ] : $events;
    }

    /** @return array<string, mixed> */
    private static function json(Response $response): array {
        /** @var array<string, mixed> $data */
        $data = json_decode(buffer($response->getBody()), true, 64, JSON_THROW_ON_ERROR);
        return $data;
    }

    /** @return array{type: string, message: string} the error of an answer, in the form of the Messages API */
    private static function error(Response $response): array {
        /** @var mixed $error */
        $error = self::json($response)['error'] ?? null;
        self::assertIsArray($error);
        /** @var mixed $type */
        $type = $error['type'] ?? null;
        /** @var mixed $message */
        $message = $error['message'] ?? null;
        self::assertIsString($type);
        self::assertIsString($message);
        return ['type' => $type, 'message' => $message];
    }

    /** The text of the first block of a whole message. */
    private static function firstText(Response $response): string {
        /** @var mixed $content */
        $content = self::json($response)['content'] ?? null;
        self::assertIsArray($content);
        /** @var mixed $block */
        $block = $content[0] ?? null;
        self::assertIsArray($block);
        /** @var mixed $text */
        $text = $block['text'] ?? null;
        self::assertIsString($text);
        return $text;
    }

    /** @return array<array-key, mixed> */
    private function file(string $name): array {
        /** @var array<array-key, mixed> $data */
        $data = json_decode((string) file_get_contents($this->dir . '/' . $name), true, 64, JSON_THROW_ON_ERROR);
        return $data;
    }

    public function testATextAnswerStreamsToTheApplicationAsTheApiSentIt(): void {
        $server = $this->server();
        $sse = self::sse(self::text('Hello there'));
        $this->script = [['status' => 200, 'body' => $sse]];

        $response = $this->call($server, ['model' => 'claude-sonnet-5-5', 'max_tokens' => 512, 'stream' => true, 'system' => [['type' => 'text', 'text' => 'You are Mercurius.', 'cache_control' => ['type' => 'ephemeral']]], 'messages' => [['role' => 'user', 'content' => 'Hi']]]);

        self::assertSame(200, $response->getStatus());
        self::assertSame('text/event-stream', $response->getHeader('content-type'));
        self::assertSame('req_test_1', $response->getHeader('request-id'));
        self::assertSame($sse, buffer($response->getBody()), 'the events of the API, byte for byte');

        self::assertCount(1, $this->seen);
        $sent = $this->seen[0];
        self::assertSame(['Bearer fake-oauth-token'], $sent['headers']['authorization'], 'the sign-in is the CLI\'s own');
        self::assertSame('beta=true', $sent['query']);
        self::assertSame(['claude-sonnet-5-5[1m]', 512, 'You are Mercurius.', []], [$sent['body']['model'], $sent['body']['max_tokens'], $sent['body']['system'], $sent['body']['tools']]);
        self::assertSame([['role' => 'user', 'content' => [['type' => 'text', 'text' => 'Hi']]]], $sent['body']['messages']);
        self::assertFileDoesNotExist($this->dir . '/mcp.json', 'no tools — no inert server');
    }

    public function testTheChildOfACallKnowsOnlyTheRelayAndLeavesNothingBehind(): void {
        $server = $this->server();
        $this->script = [['status' => 200, 'body' => self::sse(self::text('Done'))]];

        $response = $this->call($server, ['model' => 'claude-sonnet-5-5', 'max_tokens' => 64, 'output_config' => ['effort' => 'high'], 'messages' => [['role' => 'user', 'content' => 'Hi']]]);
        $message = self::json($response);

        self::assertSame(['message', 'end_turn', [['type' => 'text', 'text' => 'Done']]], [$message['type'], $message['stop_reason'], $message['content']]);
        self::assertSame(['input_tokens' => 12, 'cache_read_input_tokens' => 3, 'output_tokens' => 7], $message['usage']);
        $env = $this->file('env.json');
        self::assertIsString($env['ANTHROPIC_BASE_URL']);
        self::assertMatchesRegularExpression('~^http://127\.0\.0\.1:\d+/admit/[0-9a-f]{48}$~', $env['ANTHROPIC_BASE_URL']);
        self::assertSame(['false', '0', '1', '1', 'off', '1', '1', '64', 'mercurius'], [
            $env['ENABLE_TOOL_SEARCH'], $env['CLAUDE_CODE_MAX_RETRIES'], $env['DISABLE_AUTO_COMPACT'], $env['DISABLE_COMPACT'],
            $env['CLAUDE_CODE_TOTAL_TOKENS_REMINDER'], $env['CLAUDE_CODE_DISABLE_AUTO_MEMORY'], $env['CLAUDE_CODE_DISABLE_CLAUDE_MDS'],
            $env['CLAUDE_CODE_MAX_OUTPUT_TOKENS'], $env['CLAUDE_CODE_ENTRYPOINT'],
        ]);
        self::assertArrayNotHasKey('CLAUDE_CODE_TOKEN', $env);
        $args = $this->file('args.json');
        foreach (['-p', '--strict-mcp-config', '--disable-slash-commands', '--no-session-persistence', '--include-partial-messages', 'dontAsk', 'high'] as $flag) {
            self::assertContains($flag, $args);
        }
        self::assertSame(['effort' => 'high'], $this->seen[0]['body']['output_config']);

        for ($i = 0; $i < 100 && count((array) scandir($this->dir . '/tmp')) > 2; $i++) {
            delay(0.1);
        }
        self::assertSame(['.', '..'], scandir($this->dir . '/tmp'), 'the files of the request held the history: they are gone');
    }

    public function testACallOfAToolComesBackUnderTheNameOfTheApplication(): void {
        $server = $this->server();
        $short = substr(self::LONG_TOOL, 0, 41) . '_' . substr(hash('sha256', self::LONG_TOOL), 0, 8);
        $this->script = [['status' => 200, 'body' => self::sse([
            ['type' => 'message_start', 'message' => ['id' => 'msg_2', 'type' => 'message', 'role' => 'assistant', 'model' => 'claude-sonnet-5-5', 'content' => [], 'stop_reason' => null, 'usage' => ['input_tokens' => 5, 'output_tokens' => 1]]],
            ['type' => 'content_block_start', 'index' => 0, 'content_block' => ['type' => 'tool_use', 'id' => 'toolu_01', 'name' => 'mcp__mercurius__' . $short, 'input' => new \stdClass()]],
            ['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'input_json_delta', 'partial_json' => '{"city":']],
            ['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'input_json_delta', 'partial_json' => '"Riga","opts":{}}']],
            ['type' => 'content_block_stop', 'index' => 0],
            // the model lost the prefix of the server, and this call has no arguments
            ['type' => 'content_block_start', 'index' => 1, 'content_block' => ['type' => 'tool_use', 'id' => 'toolu_02', 'name' => 'clock', 'input' => new \stdClass()]],
            ['type' => 'content_block_delta', 'index' => 1, 'delta' => ['type' => 'input_json_delta', 'partial_json' => '']],
            ['type' => 'content_block_stop', 'index' => 1],
            ['type' => 'message_delta', 'delta' => ['stop_reason' => 'tool_use'], 'usage' => ['output_tokens' => 20]],
            ['type' => 'message_stop'],
        ])]];
        $tools = [
            ['name' => self::LONG_TOOL, 'description' => 'Weather', 'input_schema' => ['type' => 'object', 'properties' => ['city' => ['anyOf' => [['type' => 'string'], ['type' => 'null']], 'description' => 'Where']], 'oneOf' => [['required' => ['city']]]], 'cache_control' => ['type' => 'ephemeral']],
            ['name' => 'clock', 'description' => 'Time', 'input_schema' => ['type' => 'object']],
        ];

        $response = $this->call($server, ['model' => 'sonnet', 'max_tokens' => 256, 'tools' => $tools, 'messages' => [['role' => 'user', 'content' => 'Weather?']]]);
        $raw = buffer($response->getBody());
        /** @var array{stop_reason: string, content: list<array<string, mixed>>} $message */
        $message = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);

        self::assertSame(200, $response->getStatus());
        self::assertSame('tool_use', $message['stop_reason']);
        self::assertSame([self::LONG_TOOL, ['city' => 'Riga', 'opts' => []]], [$message['content'][0]['name'], $message['content'][0]['input']]);
        self::assertSame('clock', $message['content'][1]['name']);
        self::assertStringContainsString('"opts":{}', $raw, 'an empty object stays an object');
        self::assertStringContainsString('"name":"clock","input":{}', $raw);

        /** @var list<array{name: string, input_schema: array<string, mixed>}> $offered */
        $offered = $this->seen[0]['body']['tools'];
        self::assertSame(['mcp__mercurius__' . $short, 'mcp__mercurius__clock'], array_column($offered, 'name'));
        self::assertSame(['type' => 'object', 'properties' => ['city' => ['type' => 'string', 'description' => 'Where']]], $offered[0]['input_schema'], 'no union with null and no combinator at the top');
        self::assertStringContainsString('"input_schema":{"type":"object","properties":{}}', $this->seen[0]['raw'], 'an object schema always has properties');

        /** @var array{array{result: array{serverInfo: array{name: string}}}, array{result: array{tools: list<array{name: string}>}}, array{result: array{isError: bool}}} $mcp */
        $mcp = $this->file('mcp.json');
        self::assertSame('mercurius-inert-inventory', $mcp[0]['result']['serverInfo']['name']);
        self::assertSame([$short, 'clock'], array_column($mcp[1]['result']['tools'], 'name'), 'the inert server lists the same tools');
        self::assertTrue($mcp[2]['result']['isError'], 'and runs none of them');
    }

    public function testTheHistoryIsReplayedAndAForeignIdIsReplacedInTheCallAndItsResult(): void {
        $server = $this->server();
        $this->script = [['status' => 200, 'body' => self::sse(self::text('It is sunny'))]];
        $foreign = 'call:weather/1';
        $mapped = 'toolu_mercurius_' . substr(hash('sha256', $foreign), 0, 32);

        $response = $this->call($server, ['model' => 'claude-haiku-4-5', 'tools' => [['name' => 'weather', 'description' => 'Weather', 'input_schema' => ['type' => 'object']]], 'thinking' => ['type' => 'adaptive'], 'messages' => [
            ['role' => 'user', 'content' => 'Weather in Riga?'],
            ['role' => 'assistant', 'content' => [['type' => 'text', 'text' => 'Let me look.'], ['type' => 'tool_use', 'id' => $foreign, 'name' => 'weather', 'input' => []]]],
            ['role' => 'user', 'content' => [['type' => 'tool_result', 'tool_use_id' => $foreign, 'content' => 'Sunny', 'cache_control' => ['type' => 'ephemeral']]]],
            ['role' => 'user', 'content' => 'And tomorrow?'],
        ]]);

        self::assertSame(200, $response->getStatus());
        self::assertSame('It is sunny', self::firstText($response));
        self::assertCount(1, $this->seen, 'the replies of the history asked the model nothing');
        $body = $this->seen[0]['body'];
        self::assertSame('claude-haiku-4-5-20251001', $body['model'], 'a model with the short window gets no [1m]');
        self::assertArrayNotHasKey('thinking', $body, 'this model refuses adaptive thinking');
        self::assertSame([
            ['role' => 'user', 'content' => [['type' => 'text', 'text' => 'Weather in Riga?']]],
            ['role' => 'assistant', 'content' => [['type' => 'text', 'text' => 'Let me look.'], ['type' => 'tool_use', 'id' => $mapped, 'name' => 'mcp__mercurius__weather', 'input' => []]]],
            ['role' => 'user', 'content' => [['type' => 'tool_result', 'tool_use_id' => $mapped, 'content' => 'Sunny'], ['type' => 'text', 'text' => 'And tomorrow?']]],
        ], $body['messages']);
        self::assertStringContainsString('"input":{}', $this->seen[0]['raw']);
    }

    public function testARequestTheServiceCannotCarryIsRefusedBeforeTheCliStarts(): void {
        $server = $this->server();
        $user = [['role' => 'user', 'content' => 'Hi']];
        $bad = [
            'temperature' => ['model' => 'sonnet', 'temperature' => 0.2, 'messages' => $user],
            'model is required' => ['messages' => $user],
            'not a name of a model' => ['model' => '--dangerously-skip-permissions', 'messages' => $user],
            'prefilled' => ['model' => 'sonnet', 'messages' => [...$user, ['role' => 'assistant', 'content' => 'Sure']]],
            'unique ASCII' => ['model' => 'sonnet', 'messages' => $user, 'tools' => [['name' => 'bad name', 'input_schema' => ['type' => 'object']]]],
            'Unsupported content block' => ['model' => 'sonnet', 'messages' => [['role' => 'user', 'content' => [['type' => 'server_tool_use']]]]],
            'adaptive and disabled' => ['model' => 'sonnet', 'thinking' => ['type' => 'enabled', 'budget_tokens' => 1000], 'messages' => $user],
            'no 1M context' => ['model' => 'claude-haiku-4-5[1m]', 'messages' => $user],
        ];
        foreach ($bad as $reason => $body) {
            $response = $this->call($server, $body);
            self::assertSame(400, $response->getStatus(), $reason);
            $error = self::error($response);
            self::assertSame('invalid_request_error', $error['type']);
            self::assertStringContainsString($reason, $error['message']);
        }
        self::assertSame([], $this->seen);
        self::assertFileDoesNotExist($this->dir . '/args.json', 'the CLI was not started for a call');
    }

    public function testALimitOfTheApiComesBackAsItWasAndARepeatOfTheCliNeverLeaves(): void {
        $server = $this->server();
        file_put_contents($this->dir . '/mode', 'retry');
        $limit = '{"type":"error","error":{"type":"rate_limit_error","message":"This request would exceed your account\'s rate limit."}}';
        $this->script = [['status' => 429, 'body' => $limit, 'headers' => ['retry-after' => '1800', 'anthropic-ratelimit-unified-5h-reset' => '1790000000']], ['status' => 200, 'body' => self::sse(self::text('never'))]];

        $response = $this->call($server, ['model' => 'sonnet', 'stream' => true, 'messages' => [['role' => 'user', 'content' => 'Hi']]]);

        self::assertSame(429, $response->getStatus());
        self::assertSame($limit, buffer($response->getBody()));
        self::assertSame(['1800', '1790000000'], [$response->getHeader('retry-after'), $response->getHeader('anthropic-ratelimit-unified-5h-reset')]);
        self::assertCount(1, $this->seen, 'the CLI repeated the request; the relay answered the repeat itself');
    }

    public function testARefusedSignInBuysOneRepeatSoTheCliCanRenewIt(): void {
        $server = $this->server();
        $refused = ['status' => 401, 'body' => '{"type":"error","error":{"type":"authentication_error","message":"OAuth token has expired."}}'];
        $this->script = [$refused, ['status' => 200, 'body' => self::sse(self::text('Renewed'))]];

        $response = $this->call($server, ['model' => 'sonnet', 'messages' => [['role' => 'user', 'content' => 'Hi']]]);

        self::assertSame(200, $response->getStatus());
        self::assertSame('Renewed', self::firstText($response));
        self::assertCount(2, $this->seen);

        $this->seen = [];
        $this->script = [$refused, $refused, ['status' => 200, 'body' => self::sse(self::text('never'))]];
        $again = $this->call($server, ['model' => 'sonnet', 'messages' => [['role' => 'user', 'content' => 'Hi']]]);
        self::assertSame(401, $again->getStatus());
        self::assertSame('authentication_error', self::error($again)['type']);
        self::assertCount(2, $this->seen, 'one repeat, not a loop');
    }

    public function testWithoutASignInNothingIsStartedAndTheCliSayingSoIsTheSameAnswer(): void {
        $server = $this->server();
        file_put_contents($this->dir . '/mode', 'logged-out');
        $told = $this->call($server, ['model' => 'sonnet', 'messages' => [['role' => 'user', 'content' => 'Hi']]]);
        self::assertSame(401, $told->getStatus());
        $error = self::error($told);
        self::assertSame('authentication_error', $error['type']);
        self::assertStringContainsString('Not logged in', $error['message']);

        unlink($this->dir . '/login.json');
        unlink($this->dir . '/args.json');
        $none = $this->call($server, ['model' => 'sonnet', 'messages' => [['role' => 'user', 'content' => 'Hi']]]);
        self::assertSame(401, $none->getStatus());
        self::assertFileDoesNotExist($this->dir . '/args.json', 'the status was not taken from the cache, and no call was started');
        self::assertSame([], $this->seen);
    }

    public function testACutAnswerIsAnErrorNotAnAnswer(): void {
        $server = $this->server();
        $cut = self::sse(self::text('Half of an ans', false));
        $this->script = [['status' => 200, 'body' => $cut], ['status' => 200, 'body' => $cut]];

        $whole = $this->call($server, ['model' => 'sonnet', 'messages' => [['role' => 'user', 'content' => 'Hi']]]);
        self::assertSame(502, $whole->getStatus());
        self::assertSame('api_error', self::error($whole)['type']);

        $stream = $this->call($server, ['model' => 'sonnet', 'stream' => true, 'messages' => [['role' => 'user', 'content' => 'Hi']]]);
        self::assertSame(200, $stream->getStatus(), 'the status went out before the cut');
        $events = buffer($stream->getBody());
        self::assertStringStartsWith($cut, $events);
        self::assertStringContainsString("event: error\ndata: {\"type\":\"error\"", substr($events, strlen($cut)));
    }

    public function testOutputThatIsNotEventsAndSilenceFailTheCall(): void {
        $server = $this->server(1.0);
        file_put_contents($this->dir . '/mode', 'garbage');
        $garbage = $this->call($server, ['model' => 'sonnet', 'messages' => [['role' => 'user', 'content' => 'Hi']]]);
        self::assertSame(502, $garbage->getStatus());
        self::assertStringContainsString('prints a banner', self::error($garbage)['message']);

        file_put_contents($this->dir . '/mode', 'silent');
        $silent = $this->call($server, ['model' => 'sonnet', 'messages' => [['role' => 'user', 'content' => 'Hi']]]);
        self::assertSame(504, $silent->getStatus());
        self::assertSame('timeout_error', self::error($silent)['type']);
        self::assertSame([], $this->seen);
    }

    public function testCallsWaitForAPlaceAndACallThatWaitedTooLongIsRefused(): void {
        $server = $this->server(30.0, 1, 0.4);
        file_put_contents($this->dir . '/mode', 'slow');
        $this->script = [['status' => 200, 'body' => self::sse(self::text('First'))], ['status' => 200, 'body' => self::sse(self::text('Third'))]];
        $body = ['model' => 'sonnet', 'messages' => [['role' => 'user', 'content' => 'Hi']]];

        // the answer of the CLI about its sign-in is taken once, so both calls go straight for a place
        $server->respond(new Request($this->createMock(Client::class), 'GET', Http::new('http://claude-code/auth/status'), ['x-api-key' => self::TOKEN]));
        $first = async(fn(): Response => $this->call($server, $body));
        delay(0.1);
        $second = $this->call($server, $body);

        self::assertSame(529, $second->getStatus());
        self::assertSame('overloaded_error', self::error($second)['type']);
        $answered = $first->await();
        self::assertInstanceOf(Response::class, $answered);
        self::assertSame('First', self::firstText($answered));
        self::assertCount(1, $this->seen, 'the refused call sent nothing');

        // the place is free when the process of the first call is gone
        $third = $this->call($server, $body);
        for ($i = 0; $i < 50 && $third->getStatus() === 529; $i++) {
            delay(0.1);
            $third = $this->call($server, $body);
        }
        self::assertSame('Third', self::firstText($third));
    }

    public function testTheUsageOfThePlanIsAskedFromTheCliWithoutAModelCall(): void {
        $server = $this->server();
        file_put_contents($this->dir . '/usage.json', json_encode(['limits' => [['kind' => 'session', 'percent' => 100, 'resets_at' => '2030-01-01T00:00:00Z'], ['kind' => 'weekly_all', 'percent' => 40, 'resets_at' => '2030-01-05T00:00:00Z']], 'extra_usage' => ['is_enabled' => true]], JSON_THROW_ON_ERROR));

        $response = $server->respond(new Request($this->createMock(Client::class), 'GET', Http::new('http://claude-code/usage'), ['x-api-key' => self::TOKEN]));

        self::assertSame(200, $response->getStatus());
        /** @var array{rate_limits: array{limits: list<array{kind: string, percent: int}>}, extra_usage: array{using: bool, resets_at: ?int}} $usage */
        $usage = self::json($response);
        self::assertSame(['session', 100], [$usage['rate_limits']['limits'][0]['kind'], $usage['rate_limits']['limits'][0]['percent']]);
        self::assertSame(['using' => true, 'resets_at' => 1893456000], $usage['extra_usage'], 'extra usage is on and a window of the account is full');
        $env = $this->file('usage-env.json');
        self::assertIsString($env['ANTHROPIC_BASE_URL']);
        self::assertStringContainsString('/admit/', $env['ANTHROPIC_BASE_URL'], 'the CLI ran behind a permit nobody uses');
        self::assertArrayNotHasKey('CLAUDE_CODE_DISABLE_NONESSENTIAL_TRAFFIC', $env, 'with that switch the CLI skips the fetch of the limits');
        self::assertSame([], $this->seen);
    }

    public function testPaidUseBeyondThePlanIsRefusedUnlessTheOwnerAllowedIt(): void {
        $server = $this->server();
        $body = ['model' => 'sonnet', 'messages' => [['role' => 'user', 'content' => 'Hi']]];
        file_put_contents($this->dir . '/usage.json', json_encode(['limits' => [['kind' => 'session', 'percent' => 100, 'resets_at' => '2030-01-01T00:00:00Z']], 'extra_usage' => ['is_enabled' => true]], JSON_THROW_ON_ERROR));
        $this->script = [['status' => 200, 'body' => self::sse(self::text('Paid'))], ['status' => 200, 'body' => self::sse(self::text('Paid again'))]];

        $refused = $this->call($server, $body);

        self::assertSame(429, $refused->getStatus());
        self::assertSame(['rejected', '1893456000'], [$refused->getHeader('anthropic-ratelimit-unified-status'), $refused->getHeader('anthropic-ratelimit-unified-reset')]);
        self::assertGreaterThan(0, (int) $refused->getHeader('retry-after'));
        /** @var array{error: array{type: string, message: string, resets_at: int}} $error */
        $error = self::json($refused);
        self::assertSame(['rate_limit_error', 1893456000], [$error['error']['type'], $error['error']['resets_at']]);
        self::assertStringContainsString('2030-01-01T00:00:00Z', $error['error']['message']);
        self::assertSame([], $this->seen, 'nothing was sent and nothing was billed');
        self::assertFileDoesNotExist($this->dir . '/args.json', 'the CLI was not started for the call');

        $this->call($server, $body);
        self::assertSame('1', file_get_contents($this->dir . '/usage-count'), 'the state stands until the window reopens: the CLI is not asked again');

        self::assertSame('Paid', self::firstText($this->call($server, $body, [Server::EXTRA_USAGE_HEADER => 'allow'])));
        self::assertSame('Paid again', self::firstText($this->call($server, $body, [Server::EXTRA_USAGE_HEADER => 'notify'])));
        self::assertSame(429, $this->call($server, $body, [Server::EXTRA_USAGE_HEADER => 'alow'])->getStatus(), 'a word the service does not know is stop: a typo must not spend');
    }

    public function testAReserveStopsBeforeTheFirstPaidRequestAndAnUnknownStateIsARefusal(): void {
        $server = $this->server();
        $body = ['model' => 'sonnet', 'messages' => [['role' => 'user', 'content' => 'Hi']]];
        file_put_contents($this->dir . '/usage.json', json_encode(['five_hour' => ['utilization' => 0.85, 'resets_at' => '2030-01-01T00:00:00Z'], 'seven_day' => ['utilization' => 0.2], 'extra_usage' => ['is_enabled' => true]], JSON_THROW_ON_ERROR));
        $this->script = [['status' => 200, 'body' => self::sse(self::text('Within the plan'))]];

        $early = $this->call($server, $body, [Server::RESERVE_HEADER => '80']);
        self::assertSame(429, $early->getStatus());
        self::assertStringContainsString('80%', self::error($early)['message']);

        // another sign-in has its own state: the same windows without a reserve are within the plan
        self::assertSame('Within the plan', self::firstText($this->call($server, $body, [Server::OAUTH_HEADER => 'sk-ant-oat01-another'])));

        file_put_contents($this->dir . '/usage.json', 'null');
        $unknown = $this->call($server, $body, [Server::OAUTH_HEADER => 'sk-ant-oat01-third']);
        self::assertSame(429, $unknown->getStatus());
        self::assertNull($unknown->getHeader('anthropic-ratelimit-unified-reset'));
        self::assertStringContainsString('unknown', self::error($unknown)['message']);
    }

    public function testWhatTheCliSaysAboutTheWindowsDuringACallIsRemembered(): void {
        $server = $this->server();
        $body = ['model' => 'sonnet', 'messages' => [['role' => 'user', 'content' => 'Hi']]];
        $this->script = [['status' => 200, 'body' => self::sse(self::text('The one that crossed the limit'))]];
        file_put_contents($this->dir . '/rate-limit.json', json_encode(['rateLimitType' => 'five_hour', 'overageStatus' => 'allowed', 'isUsingOverage' => true, 'resetsAt' => 1893456000], JSON_THROW_ON_ERROR));

        self::assertSame('The one that crossed the limit', self::firstText($this->call($server, $body)));
        // the CLI of the first call is put away in the background: its word is taken when it ends
        $next = $this->call($server, $body);
        for ($i = 0; $i < 50 && $next->getStatus() !== 429; $i++) {
            delay(0.1);
            $next = $this->call($server, $body);
        }
        self::assertSame(429, $next->getStatus(), 'the request that crossed the limit was billed; the next one is refused');
        self::assertSame('1893456000', $next->getHeader('anthropic-ratelimit-unified-reset'));
        self::assertSame('1', file_get_contents($this->dir . '/usage-count'), 'the event of the call was enough: no second question to the CLI');

        file_put_contents($this->dir . '/mode', 'limit');
        $local = $this->call($server, $body, [Server::EXTRA_USAGE_HEADER => 'allow']);
        self::assertSame(429, $local->getStatus());
        self::assertSame(['rejected', '1893456000'], [$local->getHeader('anthropic-ratelimit-unified-status'), $local->getHeader('anthropic-ratelimit-unified-reset')]);
        self::assertStringContainsString('session limit', self::error($local)['message']);
        self::assertCount(1, $this->seen);
    }

    public function testTheModelsTheServiceKnowsAreListed(): void {
        $request = new Request($this->createMock(Client::class), 'GET', Http::new('http://claude-code/v1/models'), ['authorization' => 'Bearer ' . self::TOKEN]);
        /** @var array{data: list<array{id: string, context_window: int}>} $models */
        $models = self::json($this->server()->respond($request));
        $ids = array_column($models['data'], 'context_window', 'id');
        self::assertSame(1_000_000, $ids['claude-sonnet-5-5'] ?? null);
        self::assertSame(200_000, $ids['claude-haiku-4-5-20251001'] ?? null);
        self::assertArrayNotHasKey('claude-mythos-5-2', $ids, 'a model given to accounts one by one is not offered from a table');
    }
}
