<?php

declare(strict_types=1);

namespace Mercurius\ClaudeCode;

use Amp\Cancellation;
use Amp\Socket\ConnectContext;
use Amp\Socket\ResourceServerSocket;
use Amp\Socket\Socket;
use Amp\Socket\SocketConnector;
use Amp\TimeoutCancellation;
use Psr\Log\LoggerInterface;
use Throwable;

use function Amp\async;
use function Amp\ByteStream\pipe;
use function Amp\Socket\listen;

/**
 * The way out for what Claude Code sends by itself. Only the model request of a call goes to the relay; the renewal
 * of the sign-in, the usage of the plan and the start of the CLI go to the hosts of Anthropic directly. When the
 * service has a route (VLESS or a proxy), those must take it too: the child gets this proxy as HTTPS_PROXY, and the
 * proxy opens tunnels through the same route. It takes CONNECT only, and only to the hosts of Anthropic on port
 * 443 — a child cannot use it to reach anything else. TLS ends at Anthropic: the proxy sees no token.
 *
 * spec: brief/models.md#сеть-модуля-claude
 * ref: references/hermes-official-plugins/claude-subscription-directsdk/directsdk_setup.py@269ddcb:L756-L770
 *      (egress_env) — the CLI's own calls go the way the relay goes, and only the loopback stays direct. The plugin
 *      hands the child the address of the organisation's proxy; here the route may be VLESS, which the CLI cannot
 *      speak, so the service puts its own proxy in front of the route
 */
final class Egress {
    /** the hosts of Anthropic and their subdomains */
    private const HOSTS = ['anthropic.com', 'claude.ai', 'claude.com'];
    private const HEAD_BYTES = 8192;
    private const HEAD_SECONDS = 10.0;
    private const CONNECT_SECONDS = 20.0;

    private ?ResourceServerSocket $server = null;

    public function __construct(
        private readonly SocketConnector $route,
        private readonly LoggerInterface $logger,
    ) {}

    /** Starts to listen on the loopback and gives the address for HTTPS_PROXY. */
    public function start(): string {
        if ($this->server === null) {
            $server = listen('127.0.0.1:0');
            $this->server = $server;
            async(function () use ($server): void {
                while (($client = $server->accept()) !== null) {
                    async($this->serve(...), $client)->ignore();
                }
            })->ignore();
        }
        return 'http://' . $this->server->getAddress()->toString();
    }

    public function stop(): void {
        $this->server?->close();
        $this->server = null;
    }

    private function serve(Socket $client): void {
        $remote = null;
        try {
            $head = '';
            $wait = new TimeoutCancellation(self::HEAD_SECONDS);
            while (!str_contains($head, "\r\n\r\n")) {
                $chunk = $client->read($wait);
                if ($chunk === null || strlen($head) + strlen($chunk) > self::HEAD_BYTES) {
                    return;
                }
                $head .= $chunk;
            }
            $rest = substr($head, (int) strpos($head, "\r\n\r\n") + 4);
            $first = substr($head, 0, (int) strpos($head, "\r\n"));
            if (preg_match('~^CONNECT ([A-Za-z0-9.-]+):(\d+) HTTP/1\.[01]$~', $first, $match) !== 1) {
                $client->write("HTTP/1.1 405 Method Not Allowed\r\nConnection: close\r\n\r\n");
                return;
            }
            $host = strtolower($match[1]);
            if ($match[2] !== '443' || !self::allowed($host)) {
                // the name of a host is no secret, and the list is tuned by it
                $this->logger->warning('claude-code: a tunnel to a host outside Anthropic was refused', ['host' => $host, 'port' => $match[2]]);
                $client->write("HTTP/1.1 403 Forbidden\r\nConnection: close\r\n\r\n");
                return;
            }
            try {
                $remote = $this->route->connect('tcp://' . $host . ':443', (new ConnectContext())->withConnectTimeout(self::CONNECT_SECONDS), new TimeoutCancellation(self::CONNECT_SECONDS));
            } catch (Throwable $e) {
                $this->logger->warning('claude-code: the route did not open a tunnel', ['host' => $host, 'error' => $e::class]);
                $client->write("HTTP/1.1 502 Bad Gateway\r\nConnection: close\r\n\r\n");
                return;
            }
            $client->write("HTTP/1.1 200 Connection Established\r\n\r\n");
            if ($rest !== '') {
                $remote->write($rest);
            }
            $up = async(self::carry(...), $client, $remote, null);
            self::carry($remote, $client, null);
            $up->await();
        } catch (Throwable) {
            // one side went away: the other is closed below
        } finally {
            $client->close();
            $remote?->close();
        }
    }

    /** Carries bytes one way until the source ends, then closes the other side so its reader ends too. */
    private static function carry(Socket $from, Socket $to, ?Cancellation $cancellation): void {
        try {
            pipe($from, $to, $cancellation);
        } catch (Throwable) {
            // a closed side ends the tunnel
        } finally {
            $to->close();
            $from->close();
        }
    }

    private static function allowed(string $host): bool {
        foreach (self::HOSTS as $allowed) {
            if ($host === $allowed || str_ends_with($host, '.' . $allowed)) {
                return true;
            }
        }
        return false;
    }
}
