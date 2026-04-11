FROM php:8.3-fpm-bookworm

ARG MEDIAMTX_VERSION=1.17.1
ARG TARGETARCH

ENV COMPOSER_ALLOW_SUPERUSER=1
ENV APP_ROOT=/app

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        ca-certificates \
        curl \
        gosu \
        libonig-dev \
        libpq-dev \
        libxml2-dev \
        libzip-dev \
        procps \
        smbclient \
        tar \
        unzip \
        zip \
    && docker-php-ext-install -j"$(nproc)" \
        bcmath \
        mbstring \
        pcntl \
        pdo_mysql \
        pdo_pgsql \
        xml \
        zip \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

COPY . .

RUN composer install --no-dev --prefer-dist --no-interaction --no-progress --optimize-autoloader \
    && chmod +x bin/ffmpeg bin/ffprobe \
    && set -eux; \
        case "${TARGETARCH}" in \
            amd64) mediamtx_arch='linux_amd64' ;; \
            arm64) mediamtx_arch='linux_arm64v8' ;; \
            *) echo "Unsupported MediaMTX architecture: ${TARGETARCH}" >&2; exit 1 ;; \
        esac; \
        curl -fsSL "https://github.com/bluenviron/mediamtx/releases/download/v${MEDIAMTX_VERSION}/mediamtx_v${MEDIAMTX_VERSION}_${mediamtx_arch}.tar.gz" -o /tmp/mediamtx.tar.gz; \
        mkdir -p /tmp/mediamtx; \
        tar -xzf /tmp/mediamtx.tar.gz -C /tmp/mediamtx; \
        install -m 0755 /tmp/mediamtx/mediamtx /usr/local/bin/mediamtx; \
        rm -rf /tmp/mediamtx /tmp/mediamtx.tar.gz \
    && mkdir -p \
        bootstrap/cache \
        storage/app/private/ffmpeg-temp \
        storage/app/private/mediamtx \
        storage/framework/cache \
        storage/framework/sessions \
        storage/framework/views \
        storage/logs \
    && chown -R www-data:www-data bootstrap/cache storage

COPY docker/entrypoint.sh /usr/local/bin/container-entrypoint
COPY docker/healthcheck-app.sh /usr/local/bin/healthcheck-app
COPY docker/run-worker.sh /usr/local/bin/run-worker
COPY docker/run-scheduler.sh /usr/local/bin/run-scheduler

RUN chmod +x /usr/local/bin/container-entrypoint /usr/local/bin/healthcheck-app /usr/local/bin/run-worker /usr/local/bin/run-scheduler

HEALTHCHECK --interval=30s --timeout=5s --start-period=45s --retries=3 CMD ["/usr/local/bin/healthcheck-app"]

ENTRYPOINT ["container-entrypoint"]
CMD ["php-fpm", "-F"]