#!/bin/sh
set -eu

run_user="${RI_RUN_USER:-app}"
image_root="${RI_IMAGE_ROOT:-images}"
server_port="${RI_SERVER_PORT:-3000}"

case "$run_user" in
    ""|"-"*|*[!A-Za-z0-9_.-]*)
        echo "Invalid RI_RUN_USER: $run_user" >&2
        exit 1
        ;;
esac

if ! run_uid="$(id -u "$run_user" 2>/dev/null)" || ! run_gid="$(id -g "$run_user" 2>/dev/null)"; then
    echo "RI_RUN_USER does not exist in the container: $run_user" >&2
    exit 1
fi

if [ "$run_uid" = "0" ] && [ "${RI_ALLOW_ROOT:-false}" != "true" ]; then
    echo "Refusing to run as root. Set RI_ALLOW_ROOT=true only for trusted debugging." >&2
    exit 1
fi

case "$server_port" in
    ""|*[!0-9]*)
        echo "Invalid RI_SERVER_PORT: $server_port" >&2
        exit 1
        ;;
esac

if [ "$server_port" -lt 1 ] || [ "$server_port" -gt 65535 ]; then
    echo "RI_SERVER_PORT must be between 1 and 65535." >&2
    exit 1
fi

if [ ! -f .env ] && [ -z "${RI_FOLDERS:-}" ]; then
    cp .env.example .env
fi

mkdir -p "$image_root" .runtime

if [ "$(id -u)" = "0" ]; then
    sed -ri "s/^Listen [0-9]+$/Listen $server_port/" /etc/apache2/conf-available/zz-random-image-api.conf
    sed -ri "s/<VirtualHost \*:[0-9]+>/<VirtualHost *:$server_port>/" /etc/apache2/conf-available/zz-random-image-api.conf
fi

if [ "$(id -u)" = "0" ] && [ "${RI_CHOWN_MOUNTS:-false}" = "true" ]; then
    chown -R "$run_uid:$run_gid" "$image_root" .runtime 2>/dev/null || true
fi

run_as_configured_user() {
    if [ "$(id -u)" = "0" ]; then
        exec setpriv --reuid "$run_uid" --regid "$run_gid" --init-groups "$@"
    fi

    exec "$@"
}

if [ "$#" -gt 0 ]; then
    run_as_configured_user "$@"
fi

if [ "${RI_AUTO_INDEX_ON_START:-true}" = "true" ]; then
    if [ "$(id -u)" = "0" ]; then
        setpriv --reuid "$run_uid" --regid "$run_gid" --init-groups php bin/console.php index
    else
        php bin/console.php index
    fi
fi

run_as_configured_user apache2-foreground
