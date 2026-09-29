#!/bin/sh

set -eu

ROOT_DIR="$(CDPATH= cd -- "$(dirname "$0")" && pwd)"
cd "$ROOT_DIR"

USERNAME="${DOCKERHUB_USERNAME:-rekanized}"
VCS_REF="$(git rev-parse HEAD 2>/dev/null || echo unknown)"
SHORT_REF="$(printf '%s' "$VCS_REF" | cut -c1-12)"
DEFAULT_TAG="$(date -u +'%Y%m%d%H%M%S')-${SHORT_REF}"
IMAGE_TAG="${IMAGE_TAG:-$DEFAULT_TAG}"
PUBLISH_LATEST="${PUBLISH_LATEST:-true}"
PUSH_IMAGES="${PUSH_IMAGES:-true}"
BUILD_DATE="$(date -u +'%Y-%m-%dT%H:%M:%SZ')"
BUILD_FLAGS="${BUILD_FLAGS:---pull}"
TEST_TIMEOUT_SECONDS="${TEST_TIMEOUT_SECONDS:-300}"

APP_IMAGE="$USERNAME/bigbrotha-app:$IMAGE_TAG"
APP_LATEST_IMAGE="$USERNAME/bigbrotha-app:latest"
TEST_IMAGE="bigbrotha-publish-test:${SHORT_REF}"

require_command() {
    if ! command -v "$1" >/dev/null 2>&1; then
        echo "Required command not found: $1" >&2
        exit 1
    fi
}

cleanup() {
    docker image rm "$TEST_IMAGE" >/dev/null 2>&1 || true
}

validate_repo_root() {
    if [ ! -f Dockerfile ] || [ ! -f docker/nginx/default.conf ] || [ ! -f artisan ]; then
        echo "Unable to locate the BigBrotha repository root." >&2
        exit 1
    fi
}

validate_app_image() {
    echo "Validating app image contents..."

    docker run --rm --entrypoint sh "$APP_IMAGE" -lc '
        set -eu
        test -f /app/vendor/autoload.php
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
}

push_and_verify() {
    image="$1"

    docker push "$image"

    if docker buildx version >/dev/null 2>&1; then
        docker buildx imagetools inspect "$image" >/dev/null
    else
        docker manifest inspect "$image" >/dev/null
    fi
}

require_command docker
require_command git
require_command timeout
validate_repo_root
trap cleanup EXIT INT TERM

case "$PUSH_IMAGES:$PUBLISH_LATEST" in
    true:true|true:false|false:true|false:false) ;;
    *) echo "PUSH_IMAGES and PUBLISH_LATEST must each be true or false." >&2; exit 1 ;;
esac

if ! docker info >/dev/null 2>&1; then
    echo "Docker daemon access is required. Run this script as a user with Docker access (or invoke it with sudo)." >&2
    exit 1
fi

docker compose version >/dev/null
APP_URL=https://validation.invalid \
DB_PASSWORD=validation-only-not-for-runtime \
BIGBROTHA_DOCKER_ENV_FILE=.env.docker.example \
    docker compose \
        --env-file .env.docker.example \
        -f docker-compose.yml \
        -f docker-compose.build.yml \
        config --quiet

echo "Building and running the release test image..."
# BUILD_FLAGS intentionally supports multiple Docker CLI flags.
# shellcheck disable=SC2086
docker build $BUILD_FLAGS \
    --target test \
    -t "$TEST_IMAGE" \
    .
timeout --foreground "$TEST_TIMEOUT_SECONDS" docker run --rm --stop-timeout 10 "$TEST_IMAGE"

echo "Building the BigBrotha release image for $USERNAME with immutable tag $IMAGE_TAG..."

# shellcheck disable=SC2086
docker build $BUILD_FLAGS \
    --target final \
    --build-arg APP_VERSION="$IMAGE_TAG" \
    --build-arg VCS_REF="$VCS_REF" \
    --build-arg BUILD_DATE="$BUILD_DATE" \
    -t "$APP_IMAGE" \
    .

validate_app_image

if [ "$PUSH_IMAGES" = "false" ]; then
    echo "Local publish validation passed; no images were pushed."
    echo "Validated image: $APP_IMAGE"
    exit 0
fi

echo "Pushing the immutable release image..."
push_and_verify "$APP_IMAGE"

if [ "$PUBLISH_LATEST" = "true" ] && [ "$IMAGE_TAG" != "latest" ]; then
    docker tag "$APP_IMAGE" "$APP_LATEST_IMAGE"

    echo "Updating latest tags..."
    push_and_verify "$APP_LATEST_IMAGE"
fi

echo "Successfully published and verified Docker Hub images."
echo "Immutable deployment values:"
echo "BIGBROTHA_APP_IMAGE=$APP_IMAGE"

if [ "$PUBLISH_LATEST" = "true" ]; then
    echo "The latest tags were updated as well."
fi
