#!/bin/sh
set -eu

if [ -f /tmp/container-role-relay ]; then
    exec /usr/local/bin/healthcheck-relay
fi

if [ -f /tmp/container-role-background ]; then
    exec /usr/local/bin/healthcheck-background
fi

exec /usr/local/bin/healthcheck-app
