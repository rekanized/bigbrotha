#!/bin/sh
set -eu

APP_ROOT="${APP_ROOT:-/app}"
APP_KEY_FILE="${APP_KEY_FILE:-$APP_ROOT/storage/app/private/app.key}"
APP_BOOTSTRAP_MARKER="${APP_BOOTSTRAP_MARKER:-$APP_ROOT/storage/app/private/bootstrap/app.ready}"
APP_CONTAINER_ROLE="${APP_CONTAINER_ROLE:-app}"

cd "$APP_ROOT"

mkdir -p \
    bootstrap/cache \
    storage/app/private/bootstrap \
    storage/app/private/ffmpeg-temp \
    storage/app/private/mediamtx \
    storage/framework/cache \
    storage/framework/sessions \
    storage/framework/views \
    storage/logs

mkdir -p "$(dirname "$APP_KEY_FILE")"

chown -R www-data:www-data bootstrap/cache storage

ensure_app_key() {
    if [ -n "${APP_KEY:-}" ]; then
        if [ ! -s "$APP_KEY_FILE" ]; then
            umask 077
            printf '%s' "$APP_KEY" > "$APP_KEY_FILE"
            chown www-data:www-data "$APP_KEY_FILE"
        fi

        export APP_KEY

        return
    fi

    if [ -s "$APP_KEY_FILE" ]; then
        APP_KEY="$(tr -d '\r\n' < "$APP_KEY_FILE")"
        export APP_KEY

        return
    fi

    lock_dir="${APP_KEY_FILE}.lock"

    while ! mkdir "$lock_dir" 2>/dev/null; do
        if [ -s "$APP_KEY_FILE" ]; then
            APP_KEY="$(tr -d '\r\n' < "$APP_KEY_FILE")"
            export APP_KEY

            return
        fi

        sleep 1
    done

    trap 'rmdir "$lock_dir" 2>/dev/null || true' EXIT INT TERM

    if [ ! -s "$APP_KEY_FILE" ]; then
        APP_KEY="$(php -r 'echo "base64:".base64_encode(random_bytes(32));')"
        umask 077
        printf '%s' "$APP_KEY" > "$APP_KEY_FILE"
        chown www-data:www-data "$APP_KEY_FILE"
    else
        APP_KEY="$(tr -d '\r\n' < "$APP_KEY_FILE")"
    fi

    export APP_KEY
    rmdir "$lock_dir" 2>/dev/null || true
    trap - EXIT INT TERM
}

ensure_app_key

wait_for_app_bootstrap() {
    if [ "${APP_WAIT_FOR_APP_BOOTSTRAP:-false}" != "true" ]; then
        return
    fi

    timeout_seconds="${APP_BOOTSTRAP_WAIT_TIMEOUT:-180}"
    deadline=$(( $(date +%s) + timeout_seconds ))

    while [ ! -f "$APP_BOOTSTRAP_MARKER" ]; do
        if [ "$(date +%s)" -ge "$deadline" ]; then
            echo "Timed out waiting for app bootstrap marker at $APP_BOOTSTRAP_MARKER" >&2
            exit 1
        fi

        sleep 1
    done
}

should_migrate_if_no_users() {
    php <<'PHP'
<?php
$driver = getenv('DB_CONNECTION') ?: 'sqlite';
$database = getenv('DB_DATABASE') ?: ((getenv('APP_ROOT') ?: '/app').'/database/database.sqlite');
$host = getenv('DB_HOST') ?: 'database';
$port = (int) (getenv('DB_PORT') ?: ($driver === 'pgsql' ? 5432 : 3306));
$username = getenv('DB_USERNAME') ?: null;
$password = getenv('DB_PASSWORD') ?: null;

try {
    switch ($driver) {
        case 'pgsql':
            $pdo = new PDO(sprintf('pgsql:host=%s;port=%d;dbname=%s', $host, $port, $database), $username, $password, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            ]);
            $tableExists = (bool) $pdo->query("SELECT EXISTS (SELECT 1 FROM information_schema.tables WHERE table_schema = 'public' AND table_name = 'users')")->fetchColumn();
            break;

        case 'mysql':
            $pdo = new PDO(sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $host, $port, $database), $username, $password, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            ]);
            $tableExists = (bool) $pdo->query("SELECT EXISTS (SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'users')")->fetchColumn();
            break;

        case 'sqlite':
            $pdo = new PDO('sqlite:'.$database, null, null, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            ]);
            $statement = $pdo->prepare("SELECT name FROM sqlite_master WHERE type = 'table' AND name = 'users' LIMIT 1");
            $statement->execute();
            $tableExists = $statement->fetchColumn() !== false;
            break;

        default:
            fwrite(STDERR, "Unsupported DB_CONNECTION for auto-migrate bootstrap: {$driver}\n");
            exit(2);
    }

    if (!$tableExists) {
        exit(0);
    }

    $userCount = (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
    exit($userCount === 0 ? 0 : 1);
} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage().PHP_EOL);
    exit(2);
}
PHP
}

run_app_bootstrap() {
    if [ "$APP_CONTAINER_ROLE" != "app" ]; then
        return
    fi

    rm -f "$APP_BOOTSTRAP_MARKER"

    should_migrate=false

    if [ "${APP_RUN_MIGRATIONS:-false}" = "true" ]; then
        should_migrate=true
    elif [ "${APP_AUTO_MIGRATE_IF_NO_USERS:-false}" = "true" ]; then
        if should_migrate_if_no_users; then
            should_migrate=true
        else
            status=$?

            if [ "$status" -eq 2 ]; then
                exit 1
            fi
        fi
    fi

    if [ "$should_migrate" = "true" ]; then
        gosu www-data php artisan migrate --force
    fi

    if [ "${APP_AUTO_START_RELAY:-false}" = "true" ]; then
        gosu www-data php artisan relay:start
    fi

    touch "$APP_BOOTSTRAP_MARKER"
    chown www-data:www-data "$APP_BOOTSTRAP_MARKER"
}

if [ "${APP_WAIT_FOR_DB:-true}" = "true" ]; then
    php <<'PHP'
<?php
$driver = getenv('DB_CONNECTION') ?: 'sqlite';

if ($driver === 'sqlite') {
    exit(0);
}

$host = getenv('DB_HOST') ?: 'database';
$port = (int) (getenv('DB_PORT') ?: ($driver === 'pgsql' ? 5432 : 3306));
$timeoutSeconds = (int) (getenv('APP_DB_WAIT_TIMEOUT') ?: 60);
$deadline = time() + max(1, $timeoutSeconds);

do {
    $connection = @fsockopen($host, $port, $errno, $error, 2);

    if (is_resource($connection)) {
        fclose($connection);
        exit(0);
    }

    usleep(500000);
} while (time() < $deadline);

fwrite(STDERR, sprintf("Database %s:%d was not reachable within %d seconds.\n", $host, $port, $timeoutSeconds));
exit(1);
PHP
fi

run_app_bootstrap
wait_for_app_bootstrap

exec gosu www-data "$@"