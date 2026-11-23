[English](README.md) | **Русский** | [中文](README.cn.md)

# mercurius-claude-code

Claude по подписке для агента. Сервис запускает неизменённый официальный CLI
[Claude Code](https://code.claude.com) как одноразовый клиент одного вызова модели и отвечает по Messages API
Anthropic, поэтому агент говорит с ним так же, как с Anthropic. Цикл агента, инструменты, подтверждения и память
остаются у агента; CLI — только транспорт, а вход принадлежит самому CLI.

Это необязательный модуль [Меркурия](https://github.com/opencck/mercurius), но работает и сам по себе.

**Состояние: в разработке.** Здоровье, вход и один вызов модели (`/v1/messages`) работают на подставном CLI и
подставном API. Маршрут к API через прокси, лимиты подписки и кеш промпта — следующими коммитами.

## API

Все маршруты, кроме `/health`, требуют токен модуля: `Authorization: Bearer <CLAUDE_CODE_TOKEN>` или
`x-api-key: <CLAUDE_CODE_TOKEN>` — заголовок, в котором клиент Messages API шлёт свой ключ.

| Маршрут | Ответ |
|---|---|
| `GET /health` | `200`, когда CLI найден и достаточно новый, иначе `503`: `{"status","module","cli","auth","detail"}`; `auth` — `login`, `token`, `none` или `metered` |
| `GET /auth/status` | то же с планом и признаком, ждёт ли вход своего кода |
| `POST /auth/login` | запускает `claude auth login`, отвечает `{"url": "…"}` — ссылку открывает владелец |
| `POST /auth/login/code` | тело `{"code": "…"}` — код со страницы; `200` с новым состоянием, `422`, если CLI код не принял |
| `POST /auth/logout` | убирает вход |
| `POST /v1/messages` | один вызов модели по подписке в форме Messages API: поток событий при `"stream": true`, иначе одно сообщение. Ошибки — в форме и со статусом API |
| `GET /v1/models` | модели, которые сервис знает по имени, с их окнами контекста |
| `GET /usage` | ещё не сделан (`501`) |

Запрос может нести `X-Claude-OAuth-Token` — токен `claude setup-token`. Сервис передаёт его CLI на этот запрос и
нигде не хранит.

### Вызов модели

`POST /v1/messages` принимает `model`, `system`, `messages`, `tools`, `max_tokens`, `stop_sequences`,
`output_config.effort`, `thinking` (`adaptive` или `disabled`) и `stream`. Любое другое поле — отказ `400`: молча ничего
не отбрасывается. Метки `cache_control` принимаются и отбрасываются — CLI ставит свои.

На каждый вызов сервис запускает CLI без собственных инструментов, на один ход и без сохранения сессии. CLI шлёт свой
запрос шлюзу на loopback, а шлюз пропускает к API ровно один запрос вызова (ещё один — только после отказа API во
входе, чтобы CLI обновил его). Приложение получает ответ самого API: те же события, со своими именами инструментов.
Инструменты CLI только перечисляет инертный MCP-сервер; сервис ни одного из них не исполняет.

| Статус | Когда |
|---|---|
| `400` | в запросе поле или блок, которые сервис не переносит |
| `401` | никто не вошёл, либо CLI списал бы с ключа API, а не с подписки |
| `429`, `529` и другие статусы API | как ответил API, с его телом и `retry-after` |
| `502` | ответ оборвался или CLI завершился, не сделав запроса |
| `503` | CLI нет или он слишком старый |
| `504` | CLI слишком долго молчал |

`auth: metered` значит, что CLI вошёл бы по ключу API или через облачный бэкенд: это оплата по API, и сервис
подпиской это не считает.

## Настройки

| Переменная | По умолчанию | Что значит |
|---|---|---|
| `CLAUDE_CODE_TOKEN` | — | обязательна, 16+ символов |
| `CLAUDE_CODE_PORT` | `8094` | порт |
| `CLAUDE_CODE_HOST` | `0.0.0.0` | адрес |
| `CLAUDE_CONFIG_DIR` | `/data/claude` | где CLI хранит вход; том |
| `CLAUDE_CODE_COMMAND` | `claude` | CLI |
| `CLAUDE_CODE_MIN_VERSION` | `2.1.321` | самый старый CLI, с которым сервис работает |
| `CLAUDE_CODE_IDLE_SECONDS` | `300` | сколько CLI может молчать во время вызова |

Сервис не запускается, если в его окружении задан `ANTHROPIC_BASE_URL` или переключатель Bedrock, Vertex или
Foundry. `ANTHROPIC_API_KEY` и `ANTHROPIC_AUTH_TOKEN` до CLI не доходят никогда.

## Запуск

```sh
docker build -t mercurius-claude-code .
docker run --rm -e CLAUDE_CODE_TOKEN=$(openssl rand -hex 32) -v claude-login:/data/claude -p 8094:8094 mercurius-claude-code
```

Версия Claude Code закреплена аргументом сборки `CLAUDE_CODE_VERSION`.

В Меркурии сервис — в профиле compose `claude-code`: добавить его в `COMPOSE_PROFILES`, задать в `.env`
`CLAUDE_CODE_TOKEN` и `CLAUDE_CODE_URL=http://claude-code:8094`.

## Разработка

```sh
composer install
composer check   # PHPUnit, PHPStan, Psalm
```

Тесты идут на подставном CLI (`test/fixtures/fake-claude.php`) и подставном API, без входа и без платных вызовов.

## Лицензия

MIT. Claude Code — продукт Anthropic, ставится из его официального пакета; этот репозиторий его не содержит и не
меняет.
