FROM debian:bookworm-slim AS mediamtx

ARG MEDIAMTX_VERSION=1.19.2
ARG MEDIAMTX_SHA256_AMD64=f9c601cc303ceca8fad2883917b022882672c5bc56311e92dbceb16e5f20c60c
ARG MEDIAMTX_SHA256_ARM64=562f419912a8668c18216a9e8c95359ec82fbb754e4a44e2953ef62b98eec688
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

FROM php:8.3-fpm-bookworm AS runtime

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
        procps \
        smbclient \
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
COPY docker/healthcheck-app.sh /usr/local/bin/healthcheck-app
COPY docker/healthcheck-worker.sh /usr/local/bin/healthcheck-worker
COPY docker/healthcheck-scheduler.sh /usr/local/bin/healthcheck-scheduler
COPY docker/run-worker.sh /usr/local/bin/run-worker
COPY docker/run-scheduler.sh /usr/local/bin/run-scheduler

RUN chmod 0755 \
        /usr/local/bin/container-entrypoint \
        /usr/local/bin/healthcheck-app \
        /usr/local/bin/healthcheck-worker \
        /usr/local/bin/healthcheck-scheduler \
        /usr/local/bin/run-worker \
        /usr/local/bin/run-scheduler

HEALTHCHECK --interval=30s --timeout=5s --start-period=45s --retries=3 CMD ["/usr/local/bin/healthcheck-app"]

ENTRYPOINT ["container-entrypoint"]
CMD ["php-fpm", "-F"]

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

FROM application AS final
