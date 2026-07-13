#!/bin/sh
set -eu

php -r "exit(@file_get_contents('http://127.0.0.1:9997/v3/info') === false ? 1 : 0);"
