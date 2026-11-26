<?php

declare(strict_types=1);

namespace Mercurius\ClaudeCode\Test;

use Amp\Http\Server\Driver\Client;
use Amp\Http\Server\Request;
use Amp\Http\Server\Response;
use Amp\PHPUnit\AsyncTestCase;
use League\Uri\Http;
use Mercurius\ClaudeCode\ChildEnv;
use Mercurius\ClaudeCode\ClaudeCli;
use Mercurius\ClaudeCode\Limits;
use Mercurius\ClaudeCode\Login;
use Mercurius\ClaudeCode\ModelCall;
use Mercurius\ClaudeCode\Relay;
use Mercurius\ClaudeCode\RefusedEnvironment;
use Mercurius\ClaudeCode\Server;
use Mercurius\ClaudeCode\Usage;
use Psr\Log\NullLogger;

use function Amp\ByteStream\buffer;

/**
 * The frame of the service against a stand-in CLI: health says what works, sign-in goes through the CLI, and the
 * child never gets a key that would turn the subscription into API billing.
 */
final class ServiceTest extends AsyncTestCase {
    private const TOKEN = 'token-0123456789abcdef';
    /** @var non-empty-string */
    private string $dir = 'unset';

    protected function setUp(): void {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/claude-code-test-' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0700, true);
    }

    protected function tearDown(): void {
        $files = glob($this->dir . '/*');
        foreach ($files === false ? [] : $files as $file) {
            @unlink($file);
        }
        @rmdir($this->dir);
        parent::tearDown();
    }

    /**
     * @param array<string, string> $parent
     * @param non-empty-string $min
     */
    private function cli(array $parent = [], string $min = '2.1.321'): ClaudeCli {
        return new ClaudeCli([PHP_BINARY, __DIR__ . '/fixtures/fake-claude.php'], new ChildEnv($parent + self::base(), $this->dir), $min);
    }

    /** @return array<string, string> what a child needs to start at all */
    private static function base(): array {
        $base = [];
        foreach (['PATH', 'SystemRoot', 'SYSTEMROOT', 'TEMP', 'TMP', 'PATHEXT', 'COMSPEC'] as $name) {
            $value = getenv($name);
            if (is_string($value) && $value !== '') {
                $base[$name] = $value;
            }
        }
        return $base;
    }

    private function server(?ClaudeCli $cli = null): Server {
        $cli ??= $this->cli();
        // no call is made here: the relay never starts and the address of the API is never used
        $relay = new Relay(new NullLogger());
        $usage = new Usage($cli, $relay, $this->dir . '/work');
        $limits = new Limits($usage->fetch(...));
        $calls = new ModelCall($cli, $relay, $limits, $this->dir, $this->dir . '/work', new NullLogger());
        return new Server($cli, new Login($cli), $calls, $usage, $limits, self::TOKEN, new NullLogger());
    }

    /**
     * @param non-empty-string $method
     * @param array<non-empty-string, string> $headers
     */
    private function request(string $method, string $path, string $body = '', ?string $token = self::TOKEN, array $headers = []): Request {
        $request = new Request($this->createMock(Client::class), $method, Http::new('http://claude-code' . $path), $headers, $body);
        if ($token !== null) {
            $request->setHeader('authorization', 'Bearer ' . $token);
        }
        return $request;
    }

    /** @return array<string, mixed> */
    private static function json(Response $response): array {
        /** @var array<string, mixed> $data */
        $data = json_decode(buffer($response->getBody()), true, 16, JSON_THROW_ON_ERROR);
        return $data;
    }

    /** A status probe that times out ends the probe, not the service: no future of it is left unawaited. */
    public function testAStatusProbeThatTimesOutLeavesTheServiceStanding(): void {
        touch($this->dir . '/hang');
        $cli = new ClaudeCli([PHP_BINARY, __DIR__ . '/fixtures/fake-claude.php'], new ChildEnv(self::base(), $this->dir), '2.1.321', 0.5);

        $status = $cli->status(null);
        gc_collect_cycles();
        \Amp\delay(0.2);

        self::assertStringContainsString('did not answer', $status->detail);
        self::assertSame('2.1.330', $cli->version(), 'the service still answers after it');
    }

    public function testHealthIsOpenAndSaysTheCliWorksAndNobodyIsSignedIn(): void {
        $response = $this->server()->respond($this->request('GET', '/health', '', null));

        self::assertSame(200, $response->getStatus());
        $health = self::json($response);
        self::assertSame(['ok', 'claude-code', '2.1.330', 'none'], [$health['status'], $health['module'], $health['cli'], $health['auth']]);
    }

    public function testACliOlderThanTheMinimumOrMissingIsNotHealthy(): void {
        file_put_contents($this->dir . '/version', '2.1.100');
        $old = $this->server($this->cli([], '2.1.321'))->respond($this->request('GET', '/health', '', null));
        self::assertSame(503, $old->getStatus());
        self::assertSame('2.1.100', self::json($old)['cli']);

        $missing = new ClaudeCli(['definitely-no-such-claude-binary'], new ChildEnv(self::base(), $this->dir), '2.1.321');
        $response = $this->server($missing)->respond($this->request('GET', '/health', '', null));
        self::assertSame(503, $response->getStatus());
        self::assertNull(self::json($response)['cli']);
    }

    public function testEverythingButHealthNeedsTheToken(): void {
        foreach ([['GET', '/auth/status'], ['POST', '/auth/login'], ['POST', '/v1/messages'], ['GET', '/v1/models'], ['GET', '/usage']] as [$method, $path]) {
            self::assertSame(401, $this->server()->respond($this->request($method, $path, '', null))->getStatus(), $path);
            self::assertSame(401, $this->server()->respond($this->request($method, $path, '', 'wrong-token-0123456789'))->getStatus(), $path);
        }
        self::assertSame(404, $this->server()->respond($this->request('GET', '/shell'))->getStatus());
    }

    public function testTheOwnerSignsInWithALinkAndACodeAndSignsOut(): void {
        $server = $this->server();

        $started = self::json($server->respond($this->request('POST', '/auth/login')));
        self::assertIsString($started['url']);
        self::assertStringStartsWith('https://claude.ai/oauth/authorize?', $started['url']);
        self::assertSame($started['url'], self::json($server->respond($this->request('POST', '/auth/login')))['url'], 'one sign-in at a time: the same link');

        $done = $server->respond($this->request('POST', '/auth/login/code', '{"code":"good-code"}'));
        self::assertSame(200, $done->getStatus());
        self::assertSame('login', self::json($done)['auth']);
        $status = self::json($server->respond($this->request('GET', '/auth/status')));
        self::assertSame(['login', 'max'], [$status['auth'], $status['plan']]);
        self::assertSame('login', self::json($server->respond($this->request('GET', '/health', '', null)))['auth']);

        self::assertSame('none', self::json($server->respond($this->request('POST', '/auth/logout')))['auth']);
    }

    public function testAWrongCodeFailsAndACodeWithoutASignInIsRefused(): void {
        $server = $this->server();
        self::assertSame(409, $server->respond($this->request('POST', '/auth/login/code', '{"code":"good-code"}'))->getStatus());

        $server->respond($this->request('POST', '/auth/login'));
        $failed = $server->respond($this->request('POST', '/auth/login/code', '{"code":"bad"}'));
        self::assertSame(422, $failed->getStatus());
        self::assertSame('none', self::json($server->respond($this->request('GET', '/auth/status')))['auth']);
        self::assertSame(400, $server->respond($this->request('POST', '/auth/login/code', '{}'))->getStatus());
    }

    public function testATokenOfTheApplicationWinsOverTheSignInAndIsNotKept(): void {
        $server = $this->server();
        $withToken = self::json($server->respond($this->request('GET', '/auth/status', '', self::TOKEN, [Server::OAUTH_HEADER => 'sk-ant-oat01-test-token'])));
        self::assertSame('token', $withToken['auth']);
        self::assertSame('none', self::json($server->respond($this->request('GET', '/auth/status')))['auth'], 'the token lived for one request');
    }

    public function testTheChildNeverGetsAKeyAndASwitchOfBackendIsRefused(): void {
        $cli = $this->cli(['ANTHROPIC_API_KEY' => 'sk-ant-api03-x', 'ANTHROPIC_AUTH_TOKEN' => 'y', 'SECRET_OF_THE_HOST' => 'z', 'USER' => 'claude']);
        self::assertSame('none', $cli->status(null)->auth, 'the key did not reach the child, so it is not a metered sign-in');
        /** @var array<string, string> $env */
        $env = json_decode((string) file_get_contents($this->dir . '/env.json'), true, 4, JSON_THROW_ON_ERROR);
        self::assertArrayNotHasKey('ANTHROPIC_API_KEY', $env);
        self::assertArrayNotHasKey('ANTHROPIC_AUTH_TOKEN', $env);
        self::assertArrayNotHasKey('SECRET_OF_THE_HOST', $env);
        self::assertSame([$this->dir, 'claude', 'mercurius', '1'], [$env['CLAUDE_CONFIG_DIR'], $env['USER'], $env['CLAUDE_CODE_ENTRYPOINT'], $env['DISABLE_AUTOUPDATER']]);

        foreach ([['ANTHROPIC_BASE_URL' => 'https://elsewhere.example'], ['CLAUDE_CODE_USE_BEDROCK' => '1']] as $bad) {
            try {
                (new ChildEnv($bad + self::base(), $this->dir))->build(null);
                self::fail('started with a refused setting');
            } catch (RefusedEnvironment $e) {
                self::assertStringContainsString(array_keys($bad)[0], $e->getMessage());
            }
        }
        self::assertArrayNotHasKey('CLAUDE_CODE_USE_VERTEX', (new ChildEnv(['CLAUDE_CODE_USE_VERTEX' => '0'] + self::base(), $this->dir))->build(null), 'a switch that is off is no switch');
    }
}
