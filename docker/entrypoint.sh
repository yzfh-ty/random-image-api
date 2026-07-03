#!/bin/sh
set -eu

run_user="${RI_RUN_USER:-app}"
image_root="${RI_IMAGE_ROOT:-images}"

if [ ! -f .env ] && [ -z "${RI_FOLDERS:-}" ]; then
    cp .env.example .env
fi

mkdir -p "$image_root" .runtime

if [ "$(id -u)" = "0" ]; then
    chown -R "$run_user:$run_user" "$image_root" .runtime 2>/dev/null || true
fi

run_as_configured_user() {
    if [ "$(id -u)" = "0" ]; then
        exec su-exec "$run_user" "$@"
    fi

    exec "$@"
}

if [ "$#" -gt 0 ]; then
    run_as_configured_user "$@"
fi

if [ "${RI_AUTO_INDEX_ON_START:-true}" = "true" ]; then
    if [ "$(id -u)" = "0" ]; then
        su-exec "$run_user" php bin/console.php index
    else
        php bin/console.php index
    fi
fi

run_as_configured_user php -S "${RI_SERVER_HOST:-0.0.0.0}:${RI_SERVER_PORT:-3000}" -t public public/index.php
