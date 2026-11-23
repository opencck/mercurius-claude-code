[English](README.md) | [Русский](README.ru.md) | **中文**

# mercurius-claude-code

为代理提供订阅版 Claude。该服务把未经修改的官方 [Claude Code](https://code.claude.com) CLI 当作单次模型调用的一次性客户端来运行，并以
Anthropic Messages API 的形式应答，因此代理与它对话的方式和与 Anthropic 对话相同。代理循环、工具、确认和记忆都留在代理一侧；CLI
只是传输通道，登录归 CLI 自己所有。

它是 [Mercurius](https://github.com/opencck/mercurius) 的可选模块，也可以单独使用。

**状态：开发中。** 健康检查、登录和单次模型调用（`/v1/messages`）已能在替身 CLI 和替身 API 上运行。经代理访问 API 的路由、订阅的限额和提示缓存随后提交。

## API

除 `/health` 外，所有路由都需要模块令牌：`Authorization: Bearer <CLAUDE_CODE_TOKEN>` 或
`x-api-key: <CLAUDE_CODE_TOKEN>` — Messages API 客户端用来发送密钥的请求头。

| 路由 | 响应 |
|---|---|
| `GET /health` | CLI 已找到且版本足够新时为 `200`，否则 `503`：`{"status","module","cli","auth","route","detail"}`；`auth` 为 `login`、`token`、`none` 或 `metered` |
| `GET /auth/status` | 同上，另有套餐以及是否有登录在等待验证码 |
| `POST /auth/login` | 启动 `claude auth login`，返回 `{"url": "…"}` — 由主人打开的链接 |
| `POST /auth/login/code` | 请求体 `{"code": "…"}` — 页面上的验证码；成功 `200` 并返回新状态，CLI 未接受时 `422` |
| `POST /auth/logout` | 移除登录 |
| `POST /v1/messages` | 按订阅进行的一次模型调用，形式与 Messages API 相同：`"stream": true` 时为事件流，否则为一条消息。错误的形式和状态码与 API 一致 |
| `GET /v1/models` | 服务按名称识别的模型及其上下文窗口 |
| `GET /usage` | 套餐用量，不调用模型而向 CLI 查询：`{"rate_limits": …, "extra_usage": {"using", "resets_at"}}`；CLI 无法回答时为 `503` |

请求可以带 `X-Claude-OAuth-Token` — `claude setup-token` 的令牌。服务只在该请求中把它交给 CLI，不做任何保存。

### 模型调用

`POST /v1/messages` 接受 `model`、`system`、`messages`、`tools`、`max_tokens`、`stop_sequences`、`output_config.effort`、
`thinking`（`adaptive` 或 `disabled`）和 `stream`。其他任何字段都以 `400` 拒绝：不会悄悄丢弃任何内容。`cache_control`
标记会被接受并丢弃 — CLI 自己放置标记。工具的 `eager_input_streaming` 会原样传递。

每次调用，服务都会启动一个没有自带工具、只走一轮、不保存会话的 CLI。CLI 把请求发给 loopback 上的中继，中继只放行该次调用的一个请求到
API（仅当 API 拒绝登录时再放行一次，以便 CLI 续期）。应用拿到的是 API 自己的应答：同样的事件，工具名称还原为应用自己的名称。工具只由一个惰性
MCP 服务器向 CLI 列出；服务不执行其中任何一个。

| 状态码 | 何时 |
|---|---|
| `400` | 请求中有服务不传递的字段或块 |
| `401` | 无人登录，或 CLI 会按 API 密钥而非订阅计费 |
| `429`、`529` 及 API 的其他状态码 | 与 API 的应答一致，带其响应体和 `retry-after` |
| 服务返回的 `429` | 将开始套餐之外的付费使用，而它未被允许（见下文） |
| `502` | 应答被截断，或 CLI 未发出请求就结束 |
| `529` | 调用等待期间，CLI 的所有空位一直被占用 |
| `503` | 没有 CLI 或版本过旧 |
| `504` | CLI 沉默过久 |

`auth: metered` 表示 CLI 会用 API 密钥或云后端登录：那是按 API 计费，服务不把它算作订阅。

### 套餐限额与超额付费

当套餐的某个窗口已用满，且账户开启了额外用量时，Anthropic 会在订阅之外对后续请求计费。此时该怎么做，由调用通过请求头
`X-Claude-Extra-Usage` 指定：

| 取值 | 服务的行为 |
|---|---|
| `stop`（默认值，任何未知取值也按它处理） | 在启动 CLI 之前拒绝：`429`，带 `anthropic-ratelimit-unified-status: rejected`、`anthropic-ratelimit-unified-reset`、`retry-after` 和 `error.resets_at` — 与 API 在窗口用尽时拒绝所用的请求头相同。无法得知的状态同样拒绝 |
| `allow` | 发送请求 |
| `notify` | 发送请求；是否正在超额付费见 `GET /usage` |

`X-Claude-Extra-Usage-Reserve: 80` 让 `stop` 在窗口达到 80% 时就拒绝 — 早于第一个付费请求，因为越过限额的那个请求已经计费。服务从调用期间
CLI 的事件得知状态；没有状态时向 CLI 询问一次（不调用模型）。状态保存在内存中，按登录分别记录。

## 设置

| 变量 | 默认值 | 含义 |
|---|---|---|
| `CLAUDE_CODE_TOKEN` | — | 必填，16 个字符以上 |
| `CLAUDE_CODE_PORT` | `8094` | 端口 |
| `CLAUDE_CODE_HOST` | `0.0.0.0` | 地址 |
| `CLAUDE_CONFIG_DIR` | `/data/claude` | CLI 保存登录的位置；数据卷 |
| `CLAUDE_CODE_COMMAND` | `claude` | CLI |
| `CLAUDE_CODE_MIN_VERSION` | `2.1.321` | 服务可用的最低 CLI 版本 |
| `CLAUDE_CODE_IDLE_SECONDS` | `300` | 一次调用中 CLI 可以沉默多久，以及调用等待空位多久 |
| `VLESS_URI` | — | 经 VLESS 出站：用于发往 API 的请求以及 CLI 自己发出的一切 |
| `CLAUDE_CODE_PROXY` | — | `VLESS_URI` 为空时经代理出站（`socks5h://user:pass@host:1080`、`http://host:3128`）；两者都为空则直连 |
| `CLAUDE_CODE_MAX_CONCURRENT` | `4` | 同时运行多少个 CLI 进程（每个约 250 MB）；其余调用按顺序等待 |

有路由时，服务会在 loopback 上启动自己的代理，并以 `HTTPS_PROXY` 交给 CLI。该代理只接受 `CONNECT`，且只通往 Anthropic 的主机
（`anthropic.com`、`claude.ai`、`claude.com` 及其子域）的 443 端口。TLS 在 Anthropic 处终止，途中的代理看不到登录信息。`/health` 在
`route` 中给出路由：`vless`、`proxy` 或 `direct`。无法解析的路由会让服务拒绝启动。

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

测试使用替身 CLI（`test/fixtures/fake-claude.php`）和替身 API，无需登录，也不产生付费调用。

## 许可证

MIT。Claude Code 是 Anthropic 的产品，从其官方软件包安装；本仓库不包含也不修改它。
