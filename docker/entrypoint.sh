#!/bin/sh
set -eu

if [ "$#" -gt 0 ]; then
    exec "$@"
fi

if [ ! -f .env ] && [ -z "${RI_FOLDERS:-}" ]; then
    cp .env.example .env
fi

mkdir -p "${RI_IMAGE_ROOT:-images}" .runtime

if [ "${RI_AUTO_INDEX_ON_START:-true}" = "true" ]; then
    php bin/console.php index
fi

exec php -S "${RI_SERVER_HOST:-0.0.0.0}:${RI_SERVER_PORT:-3000}" -t public public/index.php
