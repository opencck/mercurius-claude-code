**English** | [Русский](README.ru.md) | [中文](README.cn.md)

# mercurius-claude-code

Claude by subscription for an agent. The service runs the unmodified official
[Claude Code](https://code.claude.com) CLI as a one-shot client of a single model call and answers with the Anthropic
Messages API, so an agent talks to it as it talks to Anthropic. The agent loop, tools, approvals and memory stay with
the agent; the CLI is only the transport, and the sign-in is the CLI's own.

It is an optional module of [Mercurius](https://github.com/opencck/mercurius) and can be used on its own.

**Status: in development.** The frame is here: health, sign-in and the environment of the CLI. Model calls
(`/v1/messages`) come next.

## API

Every route except `/health` needs `Authorization: Bearer <CLAUDE_CODE_TOKEN>`.

| Route | Answer |
|---|---|
| `GET /health` | `200` when the CLI is found and new enough, else `503`: `{"status","module","cli","auth","detail"}`; `auth` is `login`, `token`, `none` or `metered` |
| `GET /auth/status` | the same with the plan and whether a sign-in waits for its code |
| `POST /auth/login` | starts `claude auth login`, answers `{"url": "…"}` — the link the owner opens |
| `POST /auth/login/code` | body `{"code": "…"}` — the code from the page; `200` with the new status, `422` when the CLI did not take it |
| `POST /auth/logout` | removes the sign-in |
| `POST /v1/messages`, `GET /v1/models`, `GET /usage` | not implemented yet (`501`) |

A request may carry `X-Claude-OAuth-Token` — a token of `claude setup-token`. The service passes it to the CLI for
that request and never keeps it.

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

The tests run against a stand-in CLI (`test/fixtures/fake-claude.php`), without a sign-in and without paid calls.

## License

MIT. Claude Code is a product of Anthropic and is installed from its official package; this repository does not
contain or change it.
