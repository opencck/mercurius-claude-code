[English](README.md) | **Русский** | [中文](README.cn.md)

# mercurius-claude-code

Claude по подписке для агента. Сервис запускает неизменённый официальный CLI
[Claude Code](https://code.claude.com) как одноразовый клиент одного вызова модели и отвечает по Messages API
Anthropic, поэтому агент говорит с ним так же, как с Anthropic. Цикл агента, инструменты, подтверждения и память
остаются у агента; CLI — только транспорт, а вход принадлежит самому CLI.

Это необязательный модуль [Меркурия](https://github.com/opencck/mercurius), но работает и сам по себе.

**Состояние: в разработке.** Готов каркас: здоровье, вход и окружение CLI. Вызовы модели (`/v1/messages`) —
следующими коммитами.

## API

Все маршруты, кроме `/health`, требуют `Authorization: Bearer <CLAUDE_CODE_TOKEN>`.

| Маршрут | Ответ |
|---|---|
| `GET /health` | `200`, когда CLI найден и достаточно новый, иначе `503`: `{"status","module","cli","auth","detail"}`; `auth` — `login`, `token`, `none` или `metered` |
| `GET /auth/status` | то же с планом и признаком, ждёт ли вход своего кода |
| `POST /auth/login` | запускает `claude auth login`, отвечает `{"url": "…"}` — ссылку открывает владелец |
| `POST /auth/login/code` | тело `{"code": "…"}` — код со страницы; `200` с новым состоянием, `422`, если CLI код не принял |
| `POST /auth/logout` | убирает вход |
| `POST /v1/messages`, `GET /v1/models`, `GET /usage` | ещё не сделаны (`501`) |

Запрос может нести `X-Claude-OAuth-Token` — токен `claude setup-token`. Сервис передаёт его CLI на этот запрос и
нигде не хранит.

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

Тесты идут на подставном CLI (`test/fixtures/fake-claude.php`), без входа и без платных вызовов.

## Лицензия

MIT. Claude Code — продукт Anthropic, ставится из его официального пакета; этот репозиторий его не содержит и не
меняет.
