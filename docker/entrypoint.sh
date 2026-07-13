#!/bin/sh
set -eu

APP_ROOT="${APP_ROOT:-/app}"
APP_KEY_FILE="${APP_KEY_FILE:-$APP_ROOT/storage/app/private/app.key}"
APP_BOOTSTRAP_MARKER="${APP_BOOTSTRAP_MARKER:-$APP_ROOT/storage/app/private/bootstrap/app.ready}"
APP_DATABASE_INITIALIZED_MARKER="${APP_DATABASE_INITIALIZED_MARKER:-$APP_ROOT/storage/app/private/bootstrap/database.initialized}"
start_command="${1:-php-fpm}"

cd "$APP_ROOT"

mkdir -p \
    bootstrap/cache \
    storage/app/private/bootstrap \
    storage/app/private/continuous-recorders \
    storage/app/private/ffmpeg-temp \
    storage/app/private/mediamtx \
    storage/app/private/motion-recorders \
    storage/framework/cache/data \
    storage/framework/sessions \
    storage/framework/views \
    storage/logs

normalize_recording_runtime_permissions() {
    for runtime_path in \
        storage/app/private/continuous-recorders \
        storage/app/private/motion-recorders
    do
        chown -R www-data:www-data "$runtime_path"
        find "$runtime_path" -type d -exec chmod 2775 {} +
    done
}

mkdir -p "$(dirname "$APP_KEY_FILE")"
chmod 700 "$(dirname "$APP_KEY_FILE")" 2>/dev/null || true

case "$(basename "$start_command")" in
    run-app|php-fpm|php-fpm*)
        chown -R www-data:www-data \
            bootstrap/cache \
            storage/app/private/bootstrap \
            storage/app/private/ffmpeg-temp \
            storage/app/private/mediamtx \
            storage/framework \
            storage/logs
        normalize_recording_runtime_permissions
        ;;
    run-background|run-worker|run-scheduler)
        chown www-data:www-data \
            bootstrap/cache \
            storage \
            storage/app \
            storage/app/private \
            storage/app/private/bootstrap \
            storage/app/private/ffmpeg-temp \
            storage/app/private/mediamtx \
            storage/framework \
            storage/framework/cache \
            storage/framework/cache/data \
            storage/framework/sessions \
            storage/framework/views \
            storage/logs
        normalize_recording_runtime_permissions
        ;;
    *)
        chown www-data:www-data \
            bootstrap/cache \
            storage \
            storage/app \
            storage/app/private \
            storage/app/private/bootstrap \
            storage/app/private/ffmpeg-temp \
            storage/app/private/mediamtx \
            storage/framework \
            storage/framework/cache \
            storage/framework/cache/data \
            storage/framework/sessions \
            storage/framework/views \
            storage/logs
        ;;
esac

normalize_app_key_permissions() {
    if [ -e "$APP_KEY_FILE" ]; then
        chown www-data:www-data "$APP_KEY_FILE" 2>/dev/null || true
        chmod 600 "$APP_KEY_FILE" 2>/dev/null || true
    fi
}

load_file_env() {
    variable_name="$1"
    file_variable_name="${variable_name}_FILE"
    file_path="$(printenv "$file_variable_name" 2>/dev/null || true)"
    current_value="$(printenv "$variable_name" 2>/dev/null || true)"

    if [ "$current_value" != "" ] || [ "$file_path" = "" ]; then
        return
    fi

    if [ ! -r "$file_path" ]; then
        echo "Unable to read $file_variable_name at $file_path" >&2
        exit 1
    fi

    export "$variable_name=$(tr -d '\r\n' < "$file_path")"
}

load_file_env DB_PASSWORD

ensure_app_key() {
    if [ -n "${APP_KEY:-}" ]; then
        if [ ! -s "$APP_KEY_FILE" ]; then
            umask 077
            printf '%s' "$APP_KEY" > "$APP_KEY_FILE"
        fi

        normalize_app_key_permissions
        export APP_KEY

        return
    fi

    if [ -s "$APP_KEY_FILE" ]; then
        normalize_app_key_permissions
        APP_KEY="$(tr -d '\r\n' < "$APP_KEY_FILE")"
        export APP_KEY

        return
    fi

    lock_dir="${APP_KEY_FILE}.lock"
    lock_deadline=$(( $(date +%s) + ${APP_KEY_LOCK_WAIT_TIMEOUT:-30} ))

    while ! mkdir "$lock_dir" 2>/dev/null; do
        if [ -s "$APP_KEY_FILE" ]; then
            APP_KEY="$(tr -d '\r\n' < "$APP_KEY_FILE")"
            export APP_KEY

            return
        fi

        if [ "$(date +%s)" -ge "$lock_deadline" ]; then
            echo "Timed out waiting for application-key lock at $lock_dir. Remove the stale lock directory after confirming no other app container is initializing the key." >&2
            exit 1
        fi

        sleep 1
    done

    trap 'rmdir "$lock_dir" 2>/dev/null || true' EXIT INT TERM

    if [ ! -s "$APP_KEY_FILE" ]; then
        APP_KEY="$(php -r 'echo "base64:".base64_encode(random_bytes(32));')"
        umask 077
        printf '%s' "$APP_KEY" > "$APP_KEY_FILE"
    else
        APP_KEY="$(tr -d '\r\n' < "$APP_KEY_FILE")"
    fi

    normalize_app_key_permissions
    export APP_KEY
    rmdir "$lock_dir" 2>/dev/null || true
    trap - EXIT INT TERM
}

ensure_app_key

wait_for_app_bootstrap() {
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

mark_database_initialized() {
    touch "$APP_DATABASE_INITIALIZED_MARKER"
    chown www-data:www-data "$APP_DATABASE_INITIALIZED_MARKER"
}

should_migrate_if_schema_missing() {
    php <<'PHP'
<?php
$driver = getenv('DB_CONNECTION') ?: 'pgsql';
$database = getenv('DB_DATABASE') ?: 'bigbrotha';
$host = getenv('DB_HOST') ?: 'database';
$port = (int) (getenv('DB_PORT') ?: 5432);
$username = getenv('DB_USERNAME') ?: 'bigbrotha';
$password = getenv('DB_PASSWORD') ?: null;

try {
    if ($driver !== 'pgsql') {
        fwrite(STDERR, "Docker runtime expects DB_CONNECTION=pgsql; received {$driver}\n");
        exit(2);
    }

    $pdo = new PDO(sprintf('pgsql:host=%s;port=%d;dbname=%s', $host, $port, $database), $username, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
    $tableExists = (bool) $pdo->query("SELECT EXISTS (SELECT 1 FROM information_schema.tables WHERE table_schema = 'public' AND table_name = 'users')")->fetchColumn();

    exit($tableExists ? 1 : 0);
} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage().PHP_EOL);
    exit(2);
}
PHP
}

run_app_bootstrap() {
    rm -f "$APP_BOOTSTRAP_MARKER"

    database_is_empty=false

    if should_migrate_if_schema_missing; then
        if [ -f "$APP_DATABASE_INITIALIZED_MARKER" ]; then
            echo "Refusing automatic migrations: the database looks empty but a prior initialization marker exists at $APP_DATABASE_INITIALIZED_MARKER. Check the database volume and Docker service wiring before starting again." >&2
            exit 1
        fi

        database_is_empty=true
    else
        status=$?

        if [ "$status" -eq 2 ]; then
            exit 1
        fi

        if [ "$status" -eq 1 ]; then
            mark_database_initialized
        fi
    fi

    if [ "$database_is_empty" = "true" ]; then
        echo "Running automatic migrations because the users table is missing." >&2
    else
        echo "Applying pending database migrations." >&2
    fi

    gosu www-data php artisan migrate --force
    mark_database_initialized

    gosu www-data php artisan relay:sync

    touch "$APP_BOOTSTRAP_MARKER"
    chown www-data:www-data "$APP_BOOTSTRAP_MARKER"
}

wait_for_database() {
    php <<'PHP'
<?php
$driver = getenv('DB_CONNECTION') ?: 'pgsql';

if ($driver !== 'pgsql') {
    fwrite(STDERR, sprintf("Docker runtime expects DB_CONNECTION=pgsql, received %s.\n", $driver));
    exit(1);
}

$host = getenv('DB_HOST') ?: 'database';
$port = (int) (getenv('DB_PORT') ?: 5432);
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
}

wait_for_database

case "$(basename "$start_command")" in
    run-app|php-fpm|php-fpm*)
        run_app_bootstrap
        ;;
    run-background|run-worker|run-scheduler)
        wait_for_app_bootstrap
        ;;
esac

if [ "$#" -gt 0 ]; then
    case "$(basename "$1")" in
        run-app|run-background|php-fpm|php-fpm*)
            exec "$@"
            ;;
    esac
fi

exec gosu www-data "$@"
