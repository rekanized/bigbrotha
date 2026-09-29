FROM debian:bookworm-slim AS mediamtx

ARG MEDIAMTX_VERSION=1.21.1
ARG MEDIAMTX_SHA256_AMD64=653abc672a3e693f8d3b2717752492fdcfb8072291ec108d03d3dd857411b0ee
ARG MEDIAMTX_SHA256_ARM64=6a3aa635fb60ea9b8d566ec306f0a42ff1b6b52a3942bc2baffbe55880d4c3dd
ARG TARGETARCH

RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends ca-certificates curl tar; \
    rm -rf /var/lib/apt/lists/*; \
    target_arch="${TARGETARCH:-}"; \
    if [ -z "$target_arch" ]; then \
        case "$(dpkg --print-architecture)" in \
            amd64) target_arch='amd64' ;; \
            arm64) target_arch='arm64' ;; \
            *) echo "Unsupported MediaMTX architecture: $(dpkg --print-architecture)" >&2; exit 1 ;; \
        esac; \
    fi; \
    case "$target_arch" in \
        amd64) mediamtx_arch='linux_amd64'; mediamtx_sha256="$MEDIAMTX_SHA256_AMD64" ;; \
        arm64) mediamtx_arch='linux_arm64'; mediamtx_sha256="$MEDIAMTX_SHA256_ARM64" ;; \
        *) echo "Unsupported MediaMTX architecture: ${target_arch}" >&2; exit 1 ;; \
    esac; \
    archive="mediamtx_v${MEDIAMTX_VERSION}_${mediamtx_arch}.tar.gz"; \
    curl -fsSL --retry 3 --retry-all-errors \
        "https://github.com/bluenviron/mediamtx/releases/download/v${MEDIAMTX_VERSION}/${archive}" \
        -o "/tmp/${archive}"; \
    echo "${mediamtx_sha256}  /tmp/${archive}" | sha256sum -c -; \
    mkdir -p /tmp/mediamtx; \
    tar -xzf "/tmp/${archive}" -C /tmp/mediamtx; \
    install -m 0755 /tmp/mediamtx/mediamtx /usr/local/bin/mediamtx

FROM php:8.5-fpm-bookworm AS runtime

ENV APP_ROOT=/app \
    COMPOSER_ALLOW_SUPERUSER=1

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        ca-certificates \
        ffmpeg \
        gosu \
        libonig-dev \
        libpq-dev \
        libxml2-dev \
        libzip-dev \
        nginx-light \
        procps \
        smbclient \
        supervisor \
    && docker-php-ext-install -j"$(nproc)" \
        bcmath \
        mbstring \
        pcntl \
        pdo_mysql \
        pdo_pgsql \
        xml \
        zip \
    && rm -rf /var/lib/apt/lists/*

COPY --from=mediamtx /usr/local/bin/mediamtx /usr/local/bin/mediamtx
COPY docker/php-production.ini /usr/local/etc/php/conf.d/zz-production.ini
COPY docker/php-fpm-production.conf /usr/local/etc/php-fpm.d/zz-production.conf
COPY docker/nginx/nginx.conf /etc/nginx/nginx.conf
COPY docker/nginx/default.conf /etc/nginx/conf.d/default.conf
COPY docker/supervisor/app.conf /etc/supervisor/app.conf
COPY docker/supervisor/background.conf /etc/supervisor/background.conf

WORKDIR /app

FROM runtime AS vendor-production

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

RUN apt-get update \
    && apt-get install -y --no-install-recommends git unzip \
    && rm -rf /var/lib/apt/lists/*

COPY composer.json composer.lock ./

RUN composer install \
        --no-dev \
        --prefer-dist \
        --no-interaction \
        --no-progress \
        --no-scripts \
    && composer clear-cache

COPY . .

RUN composer dump-autoload \
        --no-dev \
        --classmap-authoritative \
        --no-interaction \
        --no-scripts

FROM runtime AS application

ARG APP_VERSION=dev
ARG VCS_REF=unknown
ARG BUILD_DATE=unknown

ENV APP_ENV=production \
    APP_DEBUG=false \
    APP_KEY_FILE=/app/bootstrap-persist/app.key \
    CACHE_STORE=database \
    DB_CONNECTION=pgsql \
    DB_HOST=database \
    DB_PORT=5432 \
    LOG_CHANNEL=stderr \
    MEDIAMTX_AUTH_CALLBACK_URL=http://app:8080/relay/auth/mediamtx \
    MEDIAMTX_MANAGED_EXTERNALLY=true \
    QUEUE_CONNECTION=database \
    SESSION_DRIVER=database

LABEL org.opencontainers.image.title="BigBrotha Laravel application" \
      org.opencontainers.image.description="Laravel camera operations application with ffmpeg and MediaMTX" \
      org.opencontainers.image.source="https://github.com/rekanized/bigbrotha" \
      org.opencontainers.image.version="$APP_VERSION" \
      org.opencontainers.image.revision="$VCS_REF" \
      org.opencontainers.image.created="$BUILD_DATE"

COPY app ./app
COPY artisan composer.json composer.lock ./
COPY bootstrap ./bootstrap
COPY config ./config
COPY database ./database
COPY public ./public
COPY resources ./resources
COPY routes ./routes
COPY --from=vendor-production /app/vendor /app/vendor

RUN mkdir -p \
        bootstrap/cache \
        storage/app/private/ffmpeg-temp \
        storage/app/private/mediamtx \
        storage/framework/cache/data \
        storage/framework/sessions \
        storage/framework/views \
        storage/logs \
    && php artisan package:discover --ansi \
    && chown -R www-data:www-data bootstrap/cache storage

COPY docker/entrypoint.sh /usr/local/bin/container-entrypoint
COPY docker/healthcheck.sh /usr/local/bin/healthcheck
COPY docker/healthcheck-app.sh /usr/local/bin/healthcheck-app
COPY docker/healthcheck-background.sh /usr/local/bin/healthcheck-background
COPY docker/healthcheck-relay.sh /usr/local/bin/healthcheck-relay
COPY docker/run-app.sh /usr/local/bin/run-app
COPY docker/run-background.sh /usr/local/bin/run-background
COPY docker/run-relay.sh /usr/local/bin/run-relay
COPY docker/run-worker.sh /usr/local/bin/run-worker
COPY docker/run-scheduler.sh /usr/local/bin/run-scheduler

RUN chmod 0755 \
        /usr/local/bin/container-entrypoint \
        /usr/local/bin/healthcheck \
        /usr/local/bin/healthcheck-app \
        /usr/local/bin/healthcheck-background \
        /usr/local/bin/healthcheck-relay \
        /usr/local/bin/run-app \
        /usr/local/bin/run-background \
        /usr/local/bin/run-relay \
        /usr/local/bin/run-worker \
        /usr/local/bin/run-scheduler

HEALTHCHECK --interval=30s --timeout=5s --start-period=45s --retries=3 CMD ["/usr/local/bin/healthcheck"]

ENTRYPOINT ["container-entrypoint"]
CMD ["run-app"]

FROM runtime AS test

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

RUN apt-get update \
    && apt-get install -y --no-install-recommends git libsqlite3-dev unzip \
    && docker-php-ext-install -j"$(nproc)" pdo_sqlite \
    && rm -rf /var/lib/apt/lists/*

COPY . .

RUN cp .env.example .env \
    && mkdir -p \
        bootstrap/cache \
        storage/framework/cache/data \
        storage/framework/sessions \
        storage/framework/views \
        storage/logs \
    && composer install --prefer-dist --no-interaction --no-progress \
    && composer validate --strict --no-check-publish \
    && composer audit --locked --no-dev

CMD ["php", "artisan", "test"]

FROM application AS development

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

RUN apt-get update \
    && apt-get install -y --no-install-recommends git libsqlite3-dev unzip \
    && docker-php-ext-install -j"$(nproc)" pdo_sqlite \
    && rm -rf /var/lib/apt/lists/* \
    && sed -i 's/open_file_cache max=1000 inactive=60s;/open_file_cache off;/' /etc/nginx/nginx.conf \
    && sed -i 's/expires 1h;/expires -1;/' /etc/nginx/conf.d/default.conf

COPY docker/php-development.ini /usr/local/etc/php/conf.d/zzz-development.ini

FROM application AS final
