#!/bin/sh
set -eu

worker_processes="${CAMERA_RECORDING_WORKER_PROCESSES:-1}"

case "$worker_processes" in
    ''|*[!0-9]*)
        echo "CAMERA_RECORDING_WORKER_PROCESSES must be a positive integer, got: $worker_processes" >&2
        exit 1
        ;;
esac

if [ "$worker_processes" -lt 1 ]; then
    echo "CAMERA_RECORDING_WORKER_PROCESSES must be at least 1." >&2
    exit 1
fi

case "${CAMERA_AI_ENABLED:-false}" in
    1|true|yes|on) CAMERA_AI_AUTOSTART=true ;;
    *) CAMERA_AI_AUTOSTART=false ;;
esac
export CAMERA_AI_AUTOSTART

export CAMERA_RECORDING_WORKER_PROCESSES="$worker_processes"
: > /tmp/container-role-background

exec /usr/bin/supervisord -c /etc/supervisor/background.conf
