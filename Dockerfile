# claude-code: Claude by subscription through the official Claude Code CLI (a module of the Mercurius agent).
# The CLI is pinned: its stream-json frames and its behaviour behind a relay change between versions, so a new version
# is a change of this argument and a run of the checks, never an update on its own.
ARG CLAUDE_CODE_VERSION=2.1.330

FROM node:22-bookworm-slim AS cli
ARG CLAUDE_CODE_VERSION
RUN npm install -g --no-audit --no-fund --loglevel=error "@anthropic-ai/claude-code@${CLAUDE_CODE_VERSION}" \
    && cp "$(readlink -f /usr/local/bin/claude)" /claude \
    && /claude --version

FROM php:8.2-cli-bookworm AS build
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
RUN apt-get update && apt-get install -y --no-install-recommends git unzip && rm -rf /var/lib/apt/lists/*
WORKDIR /app
COPY composer.json ./
RUN composer install --no-dev --no-interaction --no-progress --prefer-dist --optimize-autoloader --no-scripts
COPY src ./src
COPY bin ./bin
COPY resources ./resources

FROM php:8.2-cli-bookworm
# The CLI is one self-contained binary: Node is not needed at run time.
COPY --from=cli /claude /usr/local/bin/claude
RUN docker-php-ext-install pcntl \
    && useradd --system --uid 10050 --home /data/claude --shell /usr/sbin/nologin claude \
    && mkdir -p /data/claude && chown claude:claude /data/claude && chmod 700 /data/claude \
    && echo 'memory_limit=512M' > /usr/local/etc/php/conf.d/claude-code.ini
WORKDIR /app
COPY --from=build /app /app
# The CLI reads USER: without it a signed-in CLI says it is not signed in.
ENV USER=claude LOGNAME=claude HOME=/data/claude CLAUDE_CONFIG_DIR=/data/claude
USER claude
EXPOSE 8094
# 200 only when the CLI is found and new enough: a check of the work, not of the port.
HEALTHCHECK --interval=60s --timeout=30s --start-period=20s --retries=3 CMD php -r 'exit(@file_get_contents("http://127.0.0.1:8094/health") === false ? 1 : 0);'
CMD ["php", "bin/claude-code"]
