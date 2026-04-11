#!/bin/sh

set -e

USERNAME="${DOCKERHUB_USERNAME:-rekanized}"
IMAGE_TAG="${IMAGE_TAG:-latest}"
APP_IMAGE="$USERNAME/bigbrotha-app:$IMAGE_TAG"
WEB_IMAGE="$USERNAME/bigbrotha-web:$IMAGE_TAG"

echo "Building BigBrotha images for $USERNAME..."

echo "Building app image..."
docker build -t "$APP_IMAGE" .

echo "Building web image..."
docker build -t "$WEB_IMAGE" -f docker/nginx/Dockerfile .

echo "Ensure you are logged into Docker Hub before pushing: docker login"

echo "Pushing app image..."
docker push "$APP_IMAGE"

echo "Pushing web image..."
docker push "$WEB_IMAGE"

echo "Successfully published to Docker Hub."
echo "Use these image values in your .env for deployment:"
echo "BIGBROTHA_APP_IMAGE=$APP_IMAGE"
echo "BIGBROTHA_WEB_IMAGE=$WEB_IMAGE"
echo "Then deploy with: docker compose pull && docker compose up -d --no-build"