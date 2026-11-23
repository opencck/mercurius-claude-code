[English](README.md) | [Русский](README.ru.md) | **中文**

# mercurius-claude-code

为代理提供订阅版 Claude。该服务把未经修改的官方 [Claude Code](https://code.claude.com) CLI 当作单次模型调用的一次性客户端来运行，并以
Anthropic Messages API 的形式应答，因此代理与它对话的方式和与 Anthropic 对话相同。代理循环、工具、确认和记忆都留在代理一侧；CLI
只是传输通道，登录归 CLI 自己所有。

它是 [Mercurius](https://github.com/opencck/mercurius) 的可选模块，也可以单独使用。

**状态：开发中。** 框架已就绪：健康检查、登录和 CLI 的环境。模型调用（`/v1/messages`）随后提交。

## API

除 `/health` 外，所有路由都需要 `Authorization: Bearer <CLAUDE_CODE_TOKEN>`。

| 路由 | 响应 |
|---|---|
| `GET /health` | CLI 已找到且版本足够新时为 `200`，否则 `503`：`{"status","module","cli","auth","detail"}`；`auth` 为 `login`、`token`、`none` 或 `metered` |
| `GET /auth/status` | 同上，另有套餐以及是否有登录在等待验证码 |
| `POST /auth/login` | 启动 `claude auth login`，返回 `{"url": "…"}` — 由主人打开的链接 |
| `POST /auth/login/code` | 请求体 `{"code": "…"}` — 页面上的验证码；成功 `200` 并返回新状态，CLI 未接受时 `422` |
| `POST /auth/logout` | 移除登录 |
| `POST /v1/messages`、`GET /v1/models`、`GET /usage` | 尚未实现（`501`） |

请求可以带 `X-Claude-OAuth-Token` — `claude setup-token` 的令牌。服务只在该请求中把它交给 CLI，不做任何保存。

`auth: metered` 表示 CLI 会用 API 密钥或云后端登录：那是按 API 计费，服务不把它算作订阅。

## 设置

| 变量 | 默认值 | 含义 |
|---|---|---|
| `CLAUDE_CODE_TOKEN` | — | 必填，16 个字符以上 |
| `CLAUDE_CODE_PORT` | `8094` | 端口 |
| `CLAUDE_CODE_HOST` | `0.0.0.0` | 地址 |
| `CLAUDE_CONFIG_DIR` | `/data/claude` | CLI 保存登录的位置；数据卷 |
| `CLAUDE_CODE_COMMAND` | `claude` | CLI |
| `CLAUDE_CODE_MIN_VERSION` | `2.1.321` | 服务可用的最低 CLI 版本 |

如果服务的环境中设置了 `ANTHROPIC_BASE_URL` 或 Bedrock、Vertex、Foundry 开关，服务拒绝启动。`ANTHROPIC_API_KEY` 和
`ANTHROPIC_AUTH_TOKEN` 永远不会传给 CLI。

## 运行

```sh
docker build -t mercurius-claude-code .
docker run --rm -e CLAUDE_CODE_TOKEN=$(openssl rand -hex 32) -v claude-login:/data/claude -p 8094:8094 mercurius-claude-code
```

Claude Code 的版本由构建参数 `CLAUDE_CODE_VERSION` 固定。

在 Mercurius 中，该服务位于 compose 配置 `claude-code`：把它加入 `COMPOSE_PROFILES`，并在 `.env` 中设置 `CLAUDE_CODE_TOKEN` 和
`CLAUDE_CODE_URL=http://claude-code:8094`。

## 开发

```sh
composer install
composer check   # PHPUnit、PHPStan、Psalm
```

测试使用替身 CLI（`test/fixtures/fake-claude.php`），无需登录，也不产生付费调用。

## 许可证

MIT。Claude Code 是 Anthropic 的产品，从其官方软件包安装；本仓库不包含也不修改它。
