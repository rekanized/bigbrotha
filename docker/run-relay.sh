#!/bin/sh
set -eu

config_path="${MEDIAMTX_CONFIG_PATH:-/app/storage/app/private/mediamtx/mediamtx.yml}"

if [ ! -s "$config_path" ]; then
    echo "MediaMTX configuration is missing or empty: $config_path" >&2
    exit 1
fi

: > /tmp/container-role-relay
cd /tmp

exec "${MEDIAMTX_BINARY_PATH:-/usr/local/bin/mediamtx}" "$config_path"
