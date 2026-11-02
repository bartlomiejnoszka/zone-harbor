# syntax=docker/dockerfile:1
ARG PHP_IMAGE=dunglas/frankenphp:1-php8.5-trixie@sha256:c8abcc7925f16b1431f139d0410abe8f48db3fd862bacb646b05a4413337106a
FROM composer:2@sha256:2cf275428757e5435a667bfc0d038b14520ca02be2b1b70d44a05c8c19e2de48 AS composer

FROM ${PHP_IMAGE} AS base
RUN install-php-extensions intl mbstring opcache pcntl \
    && groupadd --gid 10001 app \
    && useradd --uid 10001 --gid app --create-home app
WORKDIR /app
ENV APP_ENV=prod APP_DEBUG=0 XDG_CONFIG_HOME=/app/var/caddy/config XDG_DATA_HOME=/app/var/caddy/data

FROM base AS dependencies
COPY --from=composer /usr/bin/composer /usr/local/bin/composer
RUN apt-get update && apt-get install -y --no-install-recommends unzip && rm -rf /var/lib/apt/lists/*
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-interaction --no-progress --prefer-dist

FROM dependencies AS test
RUN composer install --no-scripts --no-interaction --no-progress --prefer-dist
COPY . .
CMD ["composer", "check"]

FROM base AS runtime
COPY --from=dependencies /app/vendor /app/vendor
COPY src/ ./src/
COPY config/ ./config/
COPY schema/ ./schema/
COPY bin/ ./bin/
COPY public/ ./public/
COPY bootstrap.php composer.json composer.lock ./
COPY docker/Caddyfile /etc/frankenphp/Caddyfile
RUN mkdir -p var/cache var/log var/caddy/config var/caddy/data operations/mcp /app/user-config \
    && chown -R app:app var operations /app/user-config \
    && chmod 0700 /app/user-config \
    && setcap -r /usr/local/bin/frankenphp
USER app
EXPOSE 8080
HEALTHCHECK --interval=30s --timeout=5s --start-period=20s --retries=3 \
    CMD php -r 'exit(@file_get_contents("http://127.0.0.1:8080/healthz") === "{\"status\":\"ok\"}" ? 0 : 1);'
ENTRYPOINT ["docker-php-entrypoint"]
CMD ["frankenphp", "run", "--config", "/etc/frankenphp/Caddyfile"]
