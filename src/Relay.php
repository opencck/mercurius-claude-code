<?php

declare(strict_types=1);

namespace Mercurius\ClaudeCode;

use Amp\ByteStream\ReadableIterableStream;
use Amp\Http\Client\Connection\DefaultConnectionFactory;
use Amp\Http\Client\Connection\UnlimitedConnectionPool;
use Amp\Http\Client\HttpClient;
use Amp\Http\Client\HttpClientBuilder;
use Amp\Http\Client\Request as ClientRequest;
use Amp\Http\HttpStatus;
use Amp\Http\Server\DefaultErrorHandler;
use Amp\Http\Server\Driver\DefaultHttpDriverFactory;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler\ClosureRequestHandler;
use Amp\Http\Server\Response;
use Amp\Http\Server\SocketHttpServer;
use Amp\Socket\InternetAddress;
use Amp\Socket\SocketConnector;
use Generator;
use InvalidArgumentException;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The admission relay: the only address of the API a Claude Code child knows. It listens on the loopback, each call
 * has its own secret route, and of everything the CLI sends there only the model request of the call goes on to the
 * API — once. A repeat, a second request, a request of another kind are answered here and never leave: one call of
 * the application is one request of the subscription. The request of the CLI goes on as the CLI made it, with its own
 * sign-in; the headers pass through memory and are never written anywhere.
 *
 * spec: brief/models.md#claude-по-подписке
 * ref: references/hermes-official-plugins/claude-subscription-directsdk/admission.py@269ddcb:L880-L990 (do_POST) —
 *      the route of the call, the refusal of a request with Origin, the answer to a used permit, the headers that are
 *      not passed on, the answer streamed back as it comes. One server for all calls instead of a server per call;
 *      the body of the request is not rewritten and not compressed here
 * spec: brief/models.md#сеть-модуля-claude — the request leaves by the route of the service; TLS ends at the API, so
 *      a proxy on the way sees no sign-in
 */
final class Relay {
    public const CONSUMED = 'MERCURIUS_MODEL_ADMISSION_CONSUMED';
    private const MAX_BODY = 32 * 1024 * 1024;
    private const HOP = ['host', 'connection', 'content-length', 'transfer-encoding', 'proxy-authorization', 'proxy-connection', 'accept-encoding', 'keep-alive', 'upgrade', 'te'];
    private const NOT_RETURNED = ['connection', 'transfer-encoding', 'content-length', 'keep-alive', 'server', 'date'];

    private ?SocketHttpServer $server = null;
    private string $address = '';
    /** @var array<string, Admission> */
    private array $open = [];
    private readonly HttpClient $client;
    private readonly string $upstream;

    /**
     * @param string $upstream the API; anything but HTTPS is taken only on the loopback, for a stand-in in tests
     * @param float $idleSeconds how long the API may send nothing in the middle of an answer
     * @param ?SocketConnector $route how the request leaves: through VLESS or a proxy; null — directly
     */
    public function __construct(
        private readonly LoggerInterface $logger,
        string $upstream = 'https://api.anthropic.com',
        private readonly float $idleSeconds = 180.0,
        ?SocketConnector $route = null,
    ) {
        $parts = parse_url($upstream);
        $host = is_array($parts) ? ($parts['host'] ?? '') : '';
        $scheme = is_array($parts) ? ($parts['scheme'] ?? '') : '';
        $local = in_array($host, ['127.0.0.1', 'localhost', '[::1]'], true);
        if (!is_array($parts) || $host === '' || !($scheme === 'https' || $scheme === 'http' && $local) || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            throw new InvalidArgumentException('The address of the API must be HTTPS, or HTTP on the loopback for a stand-in');
        }
        $this->upstream = rtrim($upstream, '/');
        // no repeats, no redirects, no headers of its own: what leaves is the request of the CLI and nothing else
        $builder = (new HttpClientBuilder())->retry(0)->followRedirects(0)->skipDefaultUserAgent()->skipDefaultAcceptHeader()->skipAutomaticCompression();
        if ($route !== null) {
            $builder = $builder->usingPool(new UnlimitedConnectionPool(new DefaultConnectionFactory($route)));
        }
        $this->client = $builder->build();
    }

    /** Opens a permit for one call and gives the address the CLI of this call gets as the address of the API. */
    public function admit(Admission $admission): string {
        if ($this->server === null) {
            $server = SocketHttpServer::createForDirectAccess(
                $this->logger,
                enableCompression: false,
                connectionLimit: 256,
                connectionLimitPerIp: 256,
                concurrencyLimit: 256,
                httpDriverFactory: new DefaultHttpDriverFactory($this->logger, streamTimeout: 900, connectionTimeout: 900, bodySizeLimit: self::MAX_BODY, http2Enabled: false),
            );
            $server->expose(new InternetAddress('127.0.0.1', 0));
            $server->start(new ClosureRequestHandler($this->respond(...)), new DefaultErrorHandler());
            $this->address = 'http://' . $server->getServers()[0]->getAddress()->toString();
            $this->server = $server;
        }
        $this->open[$admission->route] = $admission;
        return $this->address . '/admit/' . $admission->route;
    }

    public function release(Admission $admission): void {
        unset($this->open[$admission->route]);
        $admission->close();
    }

    public function stop(): void {
        foreach ($this->open as $admission) {
            $admission->close();
        }
        $this->open = [];
        $this->server?->stop();
        $this->server = null;
    }

    private function respond(Request $request): Response {
        $path = $request->getUri()->getPath();
        if ($request->getMethod() !== 'POST' || preg_match('~^/admit/([0-9a-f]{48})/v1/messages$~', $path, $match) !== 1 || $request->hasHeader('origin')) {
            return new Response(HttpStatus::NOT_FOUND, [], '');
        }
        $admission = $this->open[$match[1]] ?? null;
        if ($admission === null) {
            return new Response(HttpStatus::NOT_FOUND, [], '');
        }
        if (!$admission->admit()) {
            return self::error(HttpStatus::BAD_REQUEST, 'invalid_request_error', self::CONSUMED);
        }
        try {
            $payload = $request->getBody()->buffer($admission->cancellation(), self::MAX_BODY);
            // spec: brief/models.md#claude-по-подписке — the prefix the next request repeats keeps its cache
            $payload = PromptCache::shape($payload, $admission->queried, $admission->oneShot, $admission->unrestored);
            $query = $request->getUri()->getQuery();
            $outgoing = new ClientRequest($this->upstream . '/v1/messages' . ($query === '' ? '' : '?' . $query), 'POST', $payload);
            foreach ($request->getHeaders() as $name => $values) {
                if (!in_array(strtolower($name), self::HOP, true)) {
                    $outgoing->setHeader($name, $values);
                }
            }
            // the sign-in and the body stay the CLI's own; only the transfer encoding is ours
            $outgoing->setHeader('accept-encoding', 'identity');
            $outgoing->setBodySizeLimit(self::MAX_BODY);
            $outgoing->setTransferTimeout(3600.0);
            $outgoing->setInactivityTimeout($this->idleSeconds);
            $response = $this->client->request($outgoing, $admission->cancellation());
        } catch (Throwable $e) {
            $admission->failure = self::kind($e);
            return self::error(HttpStatus::BAD_GATEWAY, 'api_error', 'The relay could not reach the API.');
        }
        $status = $response->getStatus();
        $admission->status = $status;
        $admission->requestId = $response->getHeader('request-id') ?? $response->getHeader('x-request-id');
        $headers = [];
        foreach ($response->getHeaders() as $name => $values) {
            $name = strtolower($name);
            if (!in_array($name, self::NOT_RETURNED, true)) {
                $headers[$name] = $values;
            }
            if ($name === 'retry-after' || str_starts_with($name, 'anthropic-ratelimit-')) {
                $admission->headers[$name] = implode(', ', $values);
            }
        }
        $headers['connection'] = ['close'];
        if ($status === HttpStatus::OK) {
            $admission->started();
        }
        $body = (function () use ($response, $admission, $status): Generator {
            try {
                while (($chunk = $response->getBody()->read($admission->cancellation())) !== null) {
                    if ($status === HttpStatus::OK) {
                        foreach ($admission->capture->feed($chunk) as $frame) {
                            $admission->push($frame);
                        }
                    } else {
                        $admission->error($chunk);
                    }
                    yield $chunk;
                }
            } catch (Throwable $e) {
                $admission->failure = self::kind($e);
            } finally {
                if ($status === HttpStatus::OK) {
                    $admission->ended();
                }
            }
        })();
        return new Response($status, $headers, new ReadableIterableStream($body));
    }

    /** The class of a failure without its text: the text may quote a header or an address. */
    private static function kind(Throwable $e): string {
        $name = $e::class;
        $cut = strrpos($name, '\\');
        return $cut === false ? $name : substr($name, $cut + 1);
    }

    private static function error(int $status, string $type, string $message): Response {
        return new Response($status, ['content-type' => 'application/json', 'connection' => 'close'], json_encode(['type' => 'error', 'error' => ['type' => $type, 'message' => $message]], JSON_THROW_ON_ERROR));
    }
}
