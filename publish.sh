#!/bin/sh

set -eu

USERNAME="${DOCKERHUB_USERNAME:-rekanized}"
IMAGE_TAG="${IMAGE_TAG:-latest}"
APP_IMAGE="$USERNAME/bigbrotha-app:$IMAGE_TAG"
WEB_IMAGE="$USERNAME/bigbrotha-web:$IMAGE_TAG"
BUILD_FLAGS="${BUILD_FLAGS:---pull}"

require_command() {
	if ! command -v "$1" >/dev/null 2>&1; then
		echo "Required command not found: $1" >&2
		exit 1
	fi
}

validate_repo_root() {
	if [ ! -f Dockerfile ] || [ ! -f docker/nginx/Dockerfile ] || [ ! -f artisan ]; then
		echo "Run publish.sh from the BigBrotha repository root." >&2
		exit 1
	fi
}

validate_app_image() {
	echo "Validating app image contents..."

	docker run --rm --entrypoint sh "$APP_IMAGE" -lc 'test -f /app/vendor/autoload.php'
}

require_command docker
validate_repo_root

echo "Building BigBrotha images for $USERNAME..."

echo "Building app image..."
docker build $BUILD_FLAGS -t "$APP_IMAGE" .

validate_app_image

echo "Building web image..."
docker build $BUILD_FLAGS -t "$WEB_IMAGE" -f docker/nginx/Dockerfile .

echo "Ensure you are logged into Docker Hub before pushing: docker login"

echo "Pushing app image..."
docker push "$APP_IMAGE"

echo "Pushing web image..."
docker push "$WEB_IMAGE"

echo "Successfully published to Docker Hub."
echo "Use these image values for deployment:"
echo "BIGBROTHA_APP_IMAGE=$APP_IMAGE"
echo "BIGBROTHA_WEB_IMAGE=$WEB_IMAGE"
echo "Then deploy with: docker compose pull && docker compose up -d --no-build"