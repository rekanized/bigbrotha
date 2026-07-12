#!/bin/sh
set -eu

exec /usr/bin/supervisord -c /etc/supervisor/app.conf
