<?php

declare(strict_types=1);

namespace Mercurius\ClaudeCode;

use Amp\ByteStream\ReadableIterableStream;
use Amp\CancelledException;
use Amp\DeferredCancellation;
use Amp\Http\HttpStatus;
use Amp\Http\Server\DefaultErrorHandler;
use Amp\Http\Server\Driver\DefaultHttpDriverFactory;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler\ClosureRequestHandler;
use Amp\Http\Server\Response;
use Amp\Http\Server\SocketHttpServer;
use Amp\Socket\InternetAddress;
use Psr\Log\LoggerInterface;
use SensitiveParameter;
use Throwable;

/**
 * The HTTP side, behind the token of the module. GET /health is open and says what works: the CLI is found and new
 * enough (else 503), and how a request would sign in. The rest needs the token of the module, as
 * `Authorization: Bearer <token>` or as `x-api-key` — the header a client of the Messages API sends its key in:
 * GET /auth/status, POST /auth/login (a link), POST /auth/login/code, POST /auth/logout; POST /v1/messages is one
 * model call by the subscription, answered as the Messages API answers (a stream of events, or one message);
 * GET /v1/models lists the models the service knows by name. GET /usage is not there yet.
 *
 * spec: brief/models.md#claude-по-подписке
 * spec: brief/skills-mcp.md#здоровье-сервиса-модуля — health is a check of the work, not of the port
 */
final class Server {
    /** the application sends the token of `claude setup-token` with a request; the service never keeps it */
    public const OAUTH_HEADER = 'x-claude-oauth-token';
    /** seconds an answer of the CLI about itself is reused: a probe starts a process */
    private const STATUS_SECONDS = 30.0;
    private const MAX_BODY = 4096;
    /** a history with images is large; the API itself takes no more than this */
    private const MAX_REQUEST = 32 * 1024 * 1024;

    /** @var array<string, array{float, CliStatus}> */
    private array $statuses = [];

    public function __construct(
        private readonly ClaudeCli $cli,
        private readonly Login $login,
        private readonly ModelCall $calls,
        #[SensitiveParameter] private readonly string $token,
        private readonly LoggerInterface $logger,
        private readonly string $route = 'direct',
    ) {}

    public function respond(Request $request): Response {
        $path = $request->getUri()->getPath();
        $method = $request->getMethod();
        if ($path === '/health' && $method === 'GET') {
            $status = $this->status(null);
            return self::json($status->usable() ? HttpStatus::OK : HttpStatus::SERVICE_UNAVAILABLE, [
                'status' => $status->usable() ? 'ok' : 'unavailable',
                'module' => 'claude-code',
                'cli' => $status->version,
                'auth' => $status->auth,
                'route' => $this->route,
                'detail' => $status->detail,
            ]);
        }
        $route = $method . ' ' . $path;
        if (!in_array($route, ['GET /auth/status', 'POST /auth/login', 'POST /auth/login/code', 'POST /auth/logout', 'POST /v1/messages', 'GET /v1/models', 'GET /usage'], true)) {
            return new Response(HttpStatus::NOT_FOUND, [], '');
        }
        $bearer = hash_equals('Bearer ' . $this->token, trim($request->getHeader('authorization') ?? ''));
        $key = hash_equals($this->token, trim($request->getHeader('x-api-key') ?? ''));
        if (!$bearer && !$key) {
            return new Response(HttpStatus::UNAUTHORIZED, [], '');
        }
        try {
            return match ($route) {
                'GET /auth/status' => $this->authStatus($request),
                'POST /auth/login' => $this->loginStart(),
                'POST /auth/login/code' => $this->loginCode($request),
                'POST /auth/logout' => $this->logout(),
                'POST /v1/messages' => $this->messages($request),
                'GET /v1/models' => self::json(HttpStatus::OK, [
                    'data' => array_map(static fn(array $model): array => ['type' => 'model', 'id' => $model['id'], 'display_name' => $model['id'], 'context_window' => $model['context_window']], Models::offered()),
                    'has_more' => false,
                ]),
                default => self::error(HttpStatus::NOT_IMPLEMENTED, 'api_error', 'This route of the service is not implemented yet.'),
            };
        } catch (Throwable $e) {
            $this->logger->error('claude-code failed', ['route' => $route, 'error' => $e::class]);
            return self::error(HttpStatus::INTERNAL_SERVER_ERROR, 'api_error', 'internal error');
        }
    }

    /**
     * One model call. Without a sign-in nothing is started: the answer says to sign in. A sign-in by an API key or a
     * cloud backend is refused the same way — it would bill API usage under the name of the subscription.
     */
    private function messages(Request $request): Response {
        $token = trim($request->getHeader(self::OAUTH_HEADER) ?? '');
        $token = $token === '' ? null : $token;
        $status = $this->status($token);
        if (!$status->usable()) {
            return self::error(HttpStatus::SERVICE_UNAVAILABLE, 'api_error', $status->detail);
        }
        if (!$status->signedIn()) {
            return self::error(HttpStatus::UNAUTHORIZED, 'authentication_error', $status->detail);
        }
        $request->getBody()->increaseSizeLimit(self::MAX_REQUEST);
        try {
            $call = MessagesRequest::parse($request->getBody()->buffer(limit: self::MAX_REQUEST));
        } catch (RequestRejected $e) {
            return self::error(HttpStatus::BAD_REQUEST, 'invalid_request_error', $e->getMessage());
        }
        // the application went away: the CLI of its call is stopped, the request is not paid for nothing
        $gone = new DeferredCancellation();
        $request->getClient()->onClose($gone->cancel(...));
        try {
            $answer = $this->calls->run($call, $token, $gone->getCancellation());
            $headers = $answer->requestId === null ? [] : ['request-id' => $answer->requestId];
            if ($call->stream) {
                // the source of the cancellation lives as long as the stream: destroyed, it would cancel the call
                $events = (static function () use ($answer, $gone): \Generator {
                    try {
                        yield from $answer->stream();
                    } finally {
                        unset($gone);
                    }
                })();
                return new Response(HttpStatus::OK, ['content-type' => 'text/event-stream', 'cache-control' => 'no-cache', ...$headers], new ReadableIterableStream($events));
            }
            return new Response(HttpStatus::OK, ['content-type' => 'application/json', ...$headers], json_encode($answer->message(), MessagesRequest::JSON));
        } catch (CallFailed $e) {
            if ($e->status === HttpStatus::UNAUTHORIZED) {
                // the sign-in is gone: the next status must not come from the cache
                $this->statuses = [];
            }
            $headers = ['content-type' => 'application/json', ...$e->headers];
            return $e->body !== null
                ? new Response($e->status, $headers, $e->body)
                : new Response($e->status, $headers, json_encode(['type' => 'error', 'error' => ['type' => $e->type, 'message' => $e->getMessage()]], MessagesRequest::JSON));
        } catch (CancelledException) {
            return self::error(HttpStatus::BAD_REQUEST, 'invalid_request_error', 'The request was cancelled.');
        }
    }

    private function authStatus(Request $request): Response {
        $token = trim($request->getHeader(self::OAUTH_HEADER) ?? '');
        $status = $this->status($token === '' ? null : $token);
        return self::json(HttpStatus::OK, [
            'cli' => $status->version,
            'usable' => $status->usable(),
            'auth' => $status->auth,
            'plan' => $status->plan,
            'detail' => $status->detail,
            'login_pending' => $this->login->pending(),
        ]);
    }

    private function loginStart(): Response {
        try {
            return self::json(HttpStatus::OK, ['url' => $this->login->start(), 'wait_seconds' => (int) Login::WAIT]);
        } catch (LoginFailed $e) {
            return self::error(HttpStatus::SERVICE_UNAVAILABLE, 'api_error', $e->getMessage());
        }
    }

    private function loginCode(Request $request): Response {
        /** @var mixed $body */
        $body = json_decode($request->getBody()->buffer(limit: self::MAX_BODY), true);
        /** @var mixed $given */
        $given = is_array($body) ? ($body['code'] ?? null) : null;
        $code = is_string($given) ? trim($given) : '';
        if ($code === '') {
            return self::error(HttpStatus::BAD_REQUEST, 'invalid_request_error', 'code is required');
        }
        try {
            $taken = $this->login->code($code);
        } catch (LoginFailed $e) {
            return self::error(HttpStatus::CONFLICT, 'invalid_request_error', $e->getMessage());
        }
        $this->statuses = [];
        if (!$taken) {
            return self::error(HttpStatus::UNPROCESSABLE_ENTITY, 'authentication_error', 'Claude Code did not take the code: start the sign-in again.');
        }
        $status = $this->status(null);
        return self::json(HttpStatus::OK, ['auth' => $status->auth, 'plan' => $status->plan]);
    }

    private function logout(): Response {
        $this->login->cancel();
        $this->cli->logout();
        $this->statuses = [];
        return self::json(HttpStatus::OK, ['auth' => $this->status(null)->auth]);
    }

    private function status(#[SensitiveParameter] ?string $oauthToken): CliStatus {
        $key = $oauthToken === null ? '' : hash('sha256', $oauthToken);
        $now = microtime(true);
        if (isset($this->statuses[$key]) && $now - $this->statuses[$key][0] < self::STATUS_SECONDS) {
            return $this->statuses[$key][1];
        }
        $status = $this->cli->status($oauthToken);
        if (count($this->statuses) > 16) {
            $this->statuses = [];
        }
        $this->statuses[$key] = [$now, $status];
        return $status;
    }

    /** @param int<1, 65535> $port */
    public function start(string $host, int $port): SocketHttpServer {
        // a model may be silent for minutes while it thinks, and a stream must not be held back for compression
        $server = SocketHttpServer::createForDirectAccess(
            $this->logger,
            enableCompression: false,
            connectionLimit: 64,
            connectionLimitPerIp: 64,
            httpDriverFactory: new DefaultHttpDriverFactory($this->logger, streamTimeout: 900, connectionTimeout: 900, bodySizeLimit: self::MAX_REQUEST),
        );
        $server->expose(new InternetAddress($host, $port));
        $server->start(new ClosureRequestHandler($this->respond(...)), new DefaultErrorHandler());
        return $server;
    }

    /** An error in the form of the Messages API, so the application reads it as it reads Anthropic's. */
    private static function error(int $status, string $type, string $message): Response {
        return self::json($status, ['type' => 'error', 'error' => ['type' => $type, 'message' => $message]]);
    }

    /** @param array<string, mixed> $data */
    private static function json(int $status, array $data): Response {
        return new Response($status, ['content-type' => 'application/json'], json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}
