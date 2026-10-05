#!/bin/sh
set -eu

if [ "$#" -ne 1 ]; then
    echo "Usage: $0 IMAGE" >&2
    exit 2
fi

docker run --rm --entrypoint sh "$1" -lc '
        set -eu
        test -f /app/vendor/autoload.php
        test ! -e /app/tests
        test ! -e /app/tmp
        test ! -e /app/bootstrap/cache/config.php
        test -s /app/LICENSE
        test -s /app/THIRD_PARTY_NOTICES.md
        test -s /app/public/js/vendor/sortable.LICENSE.txt
        test -s /usr/share/doc/mediamtx/LICENSE
        test "$APP_ENV" = production
        test "$APP_DEBUG" = false
        test -f /app/app/Providers/AppServiceProvider.php
        test ! -e /app/.env
        test ! -e /app/.env.docker
        test ! -e /app/.docker-state
        test ! -e /app/auth.json
        test -x /usr/local/bin/mediamtx
        command -v ffmpeg >/dev/null
        command -v ffprobe >/dev/null
        command -v nginx >/dev/null
        command -v smbclient >/dev/null
        command -v supervisord >/dev/null
        ! command -v composer >/dev/null
        test -f /etc/nginx/nginx.conf
        test -f /usr/local/etc/php-fpm.d/zz-production.conf
        test -f /etc/supervisor/app.conf
        test -f /etc/supervisor/background.conf
        test -x /usr/local/bin/healthcheck
        test -x /usr/local/bin/healthcheck-relay
        test -x /usr/local/bin/run-relay
        nginx -t
        CAMERA_RECORDING_WORKER_PROCESSES=1 python3 -c '\''import sys; from supervisor.options import ServerOptions; [ServerOptions().realize(["-c", path]) for path in sys.argv[1:]]'\'' /etc/supervisor/app.conf /etc/supervisor/background.conf
        php -r '\''foreach (["bcmath", "mbstring", "pcntl", "pdo_pgsql", "xml", "zip"] as $extension) { if (!extension_loaded($extension)) { fwrite(STDERR, "Missing PHP extension: {$extension}\n"); exit(1); } }'\''
        php artisan --version
        /usr/local/bin/mediamtx --version
    '
