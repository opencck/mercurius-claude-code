**English** | [Русский](README.ru.md) | [中文](README.cn.md)

# mercurius-claude-code

Claude by subscription for an agent. The service runs the unmodified official
[Claude Code](https://code.claude.com) CLI as a one-shot client of a single model call and answers with the Anthropic
Messages API, so an agent talks to it as it talks to Anthropic. The agent loop, tools, approvals and memory stay with
the agent; the CLI is only the transport, and the sign-in is the CLI's own.

It is an optional module of [Mercurius](https://github.com/opencck/mercurius) and can be used on its own.

**Status: in development.** Health, sign-in and one model call (`/v1/messages`) work against a stand-in CLI and a
stand-in API. The route to the API through a proxy, the limits of the subscription and the prompt cache come next.

## API

Every route except `/health` needs the token of the module: `Authorization: Bearer <CLAUDE_CODE_TOKEN>` or
`x-api-key: <CLAUDE_CODE_TOKEN>` — the header a client of the Messages API sends its key in.

| Route | Answer |
|---|---|
| `GET /health` | `200` when the CLI is found and new enough, else `503`: `{"status","module","cli","auth","detail"}`; `auth` is `login`, `token`, `none` or `metered` |
| `GET /auth/status` | the same with the plan and whether a sign-in waits for its code |
| `POST /auth/login` | starts `claude auth login`, answers `{"url": "…"}` — the link the owner opens |
| `POST /auth/login/code` | body `{"code": "…"}` — the code from the page; `200` with the new status, `422` when the CLI did not take it |
| `POST /auth/logout` | removes the sign-in |
| `POST /v1/messages` | one model call by the subscription, in the form of the Messages API: a stream of events with `"stream": true`, else one message. Errors have the form and the status of the API |
| `GET /v1/models` | the models the service knows by name, with their context windows |
| `GET /usage` | not implemented yet (`501`) |

A request may carry `X-Claude-OAuth-Token` — a token of `claude setup-token`. The service passes it to the CLI for
that request and never keeps it.

### A model call

`POST /v1/messages` takes `model`, `system`, `messages`, `tools`, `max_tokens`, `stop_sequences`, `output_config.effort`,
`thinking` (`adaptive` or `disabled`) and `stream`. Any other field is refused with `400`: nothing is dropped silently.
`cache_control` marks are taken and dropped — the CLI places its own.

For every call the service starts the CLI with no tools of its own, one turn and no saved session. The CLI sends its
request to a relay on the loopback, and the relay lets exactly one request of the call through to the API (one more
only after the API refused the sign-in, so the CLI can renew it). The application gets the answer of the API itself:
the same events, with its own names of tools. The tools are only listed to the CLI by an inert MCP server; the service
runs none of them.

| Status | When |
|---|---|
| `400` | the request has a field or a block the service does not carry |
| `401` | nobody is signed in, or the CLI would bill an API key instead of the subscription |
| `429`, `529` and other statuses of the API | as the API answered, with its body and `retry-after` |
| `502` | the answer was cut, or the CLI ended without a request |
| `529` | every place for the CLI stayed busy while the call waited |
| `503` | the CLI is missing or too old |
| `504` | the CLI was silent for too long |

`auth: metered` means the CLI would sign in with an API key or a cloud backend: that bills API usage, so the service
does not count it as the subscription.

## Settings

| Variable | Default | Meaning |
|---|---|---|
| `CLAUDE_CODE_TOKEN` | — | required, 16+ characters |
| `CLAUDE_CODE_PORT` | `8094` | port |
| `CLAUDE_CODE_HOST` | `0.0.0.0` | address |
| `CLAUDE_CONFIG_DIR` | `/data/claude` | where the CLI keeps its sign-in; a volume |
| `CLAUDE_CODE_COMMAND` | `claude` | the CLI |
| `CLAUDE_CODE_MIN_VERSION` | `2.1.321` | the oldest CLI the service works with |
| `CLAUDE_CODE_IDLE_SECONDS` | `300` | how long the CLI may be silent in a call, and how long a call waits for a place |
| `CLAUDE_CODE_MAX_CONCURRENT` | `4` | how many processes of the CLI run at once (about 250 MB each); other calls wait in order |

The service refuses to start when `ANTHROPIC_BASE_URL` or a Bedrock, Vertex or Foundry switch is set in its
environment. `ANTHROPIC_API_KEY` and `ANTHROPIC_AUTH_TOKEN` never reach the CLI.

## Run

```sh
docker build -t mercurius-claude-code .
docker run --rm -e CLAUDE_CODE_TOKEN=$(openssl rand -hex 32) -v claude-login:/data/claude -p 8094:8094 mercurius-claude-code
```

The version of Claude Code is pinned by the build argument `CLAUDE_CODE_VERSION`.

In Mercurius the service is in the compose profile `claude-code`: add it to `COMPOSE_PROFILES`, set
`CLAUDE_CODE_TOKEN` and `CLAUDE_CODE_URL=http://claude-code:8094` in `.env`.

## Development

```sh
composer install
composer check   # PHPUnit, PHPStan, Psalm
```

The tests run against a stand-in CLI (`test/fixtures/fake-claude.php`) and a stand-in API, without a sign-in and
without paid calls.

## License

MIT. Claude Code is a product of Anthropic and is installed from its official package; this repository does not
contain or change it.
