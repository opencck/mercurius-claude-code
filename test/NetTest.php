<?php

declare(strict_types=1);

namespace Mercurius\ClaudeCode\Test;

use Amp\Http\Client\HttpClientBuilder;
use Amp\Http\Client\Request as ClientRequest;
use Amp\Http\Server\DefaultErrorHandler;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler\ClosureRequestHandler;
use Amp\Http\Server\Response;
use Amp\Http\Server\SocketHttpServer;
use Amp\PHPUnit\AsyncTestCase;
use Amp\Socket\InternetAddress;
use Mercurius\ClaudeCode\Admission;
use Mercurius\ClaudeCode\ChildEnv;
use Mercurius\ClaudeCode\Egress;
use Mercurius\ClaudeCode\Relay;
use Mercurius\ClaudeCode\ToolNames;
use Psr\Log\NullLogger;

use function Amp\async;
use function Amp\Socket\connect;
use function Amp\Socket\listen;

/**
 * Both legs of the service leave by its route: the relay's request to the API and what the CLI sends by itself,
 * which goes through the service's own proxy — and that proxy opens tunnels to Anthropic only.
 */
final class NetTest extends AsyncTestCase {
    /** A route that leads every connection to one local address and remembers where it was asked to go. */
    private static function route(string $target): RecordingRoute {
        return new RecordingRoute($target);
    }

    /** @return list<string> */
    private static function asked(RecordingRoute $route): array {
        return $route->asked;
    }

    public function testTheProxyOfTheServiceOpensTunnelsThroughTheRouteAndOnlyToAnthropic(): void {
        $this->setTimeout(20);
        $target = listen('127.0.0.1:0');
        async(static function () use ($target): void {
            while (($peer = $target->accept()) !== null) {
                async(static function () use ($peer): void {
                    while (($chunk = $peer->read()) !== null) {
                        $peer->write('echo:' . $chunk);
                    }
                })->ignore();
            }
        })->ignore();
        $route = self::route($target->getAddress()->toString());
        $egress = new Egress($route, new NullLogger());
        $address = substr($egress->start(), strlen('http://'));
        try {
            $client = connect($address);
            $client->write("CONNECT api.anthropic.com:443 HTTP/1.1\r\nHost: api.anthropic.com:443\r\n\r\n");
            self::assertSame("HTTP/1.1 200 Connection Established\r\n\r\n", $client->read());
            $client->write('hello');
            self::assertSame('echo:hello', $client->read());
            $client->close();
            self::assertSame(['tcp://api.anthropic.com:443'], self::asked($route));

            foreach (["CONNECT example.com:443 HTTP/1.1\r\n\r\n" => '403', "CONNECT api.anthropic.com:80 HTTP/1.1\r\n\r\n" => '403', "CONNECT evil-anthropic.com:443 HTTP/1.1\r\n\r\n" => '403', "GET http://api.anthropic.com/ HTTP/1.1\r\n\r\n" => '405'] as $head => $status) {
                $refused = connect($address);
                $refused->write($head);
                self::assertStringStartsWith('HTTP/1.1 ' . $status, (string) $refused->read(), $head);
                $refused->close();
            }
            $sub = connect($address);
            $sub->write("CONNECT console.anthropic.com:443 HTTP/1.1\r\n\r\n");
            self::assertStringStartsWith('HTTP/1.1 200', (string) $sub->read());
            $sub->close();
            self::assertCount(2, self::asked($route), 'a refused host never reached the route');
        } finally {
            $egress->stop();
            $target->close();
        }
    }

    public function testTheRelaySendsItsRequestThroughTheRoute(): void {
        $this->setTimeout(20);
        $api = SocketHttpServer::createForDirectAccess(new NullLogger(), enableCompression: false);
        $api->expose(new InternetAddress('127.0.0.1', 0));
        $api->start(new ClosureRequestHandler(static fn(Request $request): Response => new Response(429, ['content-type' => 'application/json'], '{"type":"error","error":{"type":"rate_limit_error","message":"limit"}}')), new DefaultErrorHandler());
        $address = $api->getServers()[0]->getAddress()->toString();
        $route = self::route($address);
        // the address of the API is not the one the route leads to: only a request that took the route arrives
        $relay = new Relay(new NullLogger(), 'http://127.0.0.1:9', 5.0, $route);
        try {
            $admission = new Admission(new ToolNames());
            $url = $relay->admit($admission);
            $response = (new HttpClientBuilder())->build()->request(new ClientRequest($url . '/v1/messages', 'POST', '{}'));
            self::assertSame(429, $response->getStatus());
            $response->getBody()->buffer();
            self::assertSame(429, $admission->status);
            self::assertSame(['tcp://127.0.0.1:9'], self::asked($route));
            $relay->release($admission);
        } finally {
            $relay->stop();
            $api->stop();
        }
    }

    public function testAChildGetsTheProxyOfTheServiceAndKeepsTheLoopbackDirect(): void {
        $env = (new ChildEnv(['PATH' => '/usr/bin', 'HTTPS_PROXY' => 'http://proxy.of.the.host:3128', 'NO_PROXY' => '*'], '/data/claude', 'http://127.0.0.1:4321'))->call(null, 'http://127.0.0.1:5555/admit/x', null);
        self::assertSame(['http://127.0.0.1:4321', 'http://127.0.0.1:4321', '127.0.0.1,localhost'], [$env['HTTPS_PROXY'], $env['HTTP_PROXY'], $env['NO_PROXY']]);

        $direct = (new ChildEnv(['PATH' => '/usr/bin', 'HTTPS_PROXY' => 'http://proxy.of.the.host:3128'], '/data/claude'))->build(null);
        self::assertArrayNotHasKey('HTTPS_PROXY', $direct, 'a proxy of the host is not a route the service chose');
    }
}
