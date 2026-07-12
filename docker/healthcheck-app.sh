#!/bin/sh
set -eu

APP_ROOT="${APP_ROOT:-/app}"
APP_BOOTSTRAP_MARKER="${APP_BOOTSTRAP_MARKER:-$APP_ROOT/storage/app/private/bootstrap/app.ready}"

[ -f "$APP_BOOTSTRAP_MARKER" ]

cd "$APP_ROOT"

php artisan camera-recordings:healthcheck app --no-interaction >/dev/null

/usr/bin/supervisorctl -c /etc/supervisor/app.conf status php-fpm | grep -q 'RUNNING'
/usr/bin/supervisorctl -c /etc/supervisor/app.conf status nginx | grep -q 'RUNNING'

php -r '
    $context = stream_context_create(["http" => ["timeout" => 2, "ignore_errors" => true]]);
    $response = @file_get_contents("http://127.0.0.1:8080/up", false, $context);

    if ($response === false) {
        fwrite(STDERR, "Nginx could not reach the Laravel health endpoint on 127.0.0.1:8080.\n");
        exit(1);
    }

    foreach ($http_response_header ?? [] as $header) {
        if (preg_match("#^HTTP/\\S+ 2\\d\\d#", $header) === 1) {
            exit(0);
        }
    }

    fwrite(STDERR, "Laravel health endpoint did not return a successful response.\n");
    exit(1);
'
