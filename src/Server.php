<?php

declare(strict_types=1);

namespace Mercurius\ClaudeCode;

use Amp\Http\HttpStatus;
use Amp\Http\Server\DefaultErrorHandler;
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
 * enough (else 503), and how a request would sign in. The rest needs `Authorization: Bearer <token>`:
 * GET /auth/status, POST /auth/login (a link), POST /auth/login/code, POST /auth/logout; POST /v1/messages,
 * GET /v1/models and GET /usage are the Messages API of the subscription and are not there yet.
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

    /** @var array<string, array{float, CliStatus}> */
    private array $statuses = [];

    public function __construct(
        private readonly ClaudeCli $cli,
        private readonly Login $login,
        #[SensitiveParameter] private readonly string $token,
        private readonly LoggerInterface $logger,
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
                'detail' => $status->detail,
            ]);
        }
        $route = $method . ' ' . $path;
        if (!in_array($route, ['GET /auth/status', 'POST /auth/login', 'POST /auth/login/code', 'POST /auth/logout', 'POST /v1/messages', 'GET /v1/models', 'GET /usage'], true)) {
            return new Response(HttpStatus::NOT_FOUND, [], '');
        }
        if (!hash_equals('Bearer ' . $this->token, trim($request->getHeader('authorization') ?? ''))) {
            return new Response(HttpStatus::UNAUTHORIZED, [], '');
        }
        try {
            return match ($route) {
                'GET /auth/status' => $this->authStatus($request),
                'POST /auth/login' => $this->loginStart(),
                'POST /auth/login/code' => $this->loginCode($request),
                'POST /auth/logout' => $this->logout(),
                default => self::error(HttpStatus::NOT_IMPLEMENTED, 'api_error', 'This route of the service is not implemented yet.'),
            };
        } catch (Throwable $e) {
            $this->logger->error('claude-code failed', ['route' => $route, 'error' => $e::class]);
            return self::error(HttpStatus::INTERNAL_SERVER_ERROR, 'api_error', 'internal error');
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
        $server = SocketHttpServer::createForDirectAccess($this->logger, connectionLimit: 64, connectionLimitPerIp: 64);
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
