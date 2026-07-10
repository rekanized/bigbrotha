FROM nginx:1.28-alpine

ARG APP_VERSION=dev
ARG VCS_REF=unknown
ARG BUILD_DATE=unknown

LABEL org.opencontainers.image.title="BigBrotha web proxy" \
      org.opencontainers.image.description="Nginx frontend and WebRTC reverse proxy for BigBrotha" \
      org.opencontainers.image.source="https://github.com/rekanized/bigbrotha" \
      org.opencontainers.image.version="$APP_VERSION" \
      org.opencontainers.image.revision="$VCS_REF" \
      org.opencontainers.image.created="$BUILD_DATE"

WORKDIR /app

COPY docker/nginx/default.conf /etc/nginx/conf.d/default.conf
COPY public /app/public
