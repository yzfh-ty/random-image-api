FROM php:8.2-apache@sha256:848bd4a97499465e365eb4681142332a958701cb6d2fff77342f02699b81985f

RUN php -m | grep -qx curl \
    && php -m | grep -qx pdo_sqlite \
    && php -m | grep -qx sqlite3

RUN { \
        echo 'expose_php = Off'; \
        echo 'display_errors = Off'; \
        echo 'log_errors = On'; \
    } > /usr/local/etc/php/conf.d/security.ini

WORKDIR /app

COPY app /app/app
COPY public /app/public
COPY bin /app/bin
COPY .env.example /app/.env.example
COPY docker/apache.conf /etc/apache2/conf-available/zz-random-image-api.conf
COPY docker/entrypoint.sh /usr/local/bin/random-image-api-entrypoint

RUN a2enmod headers \
    && a2dissite 000-default \
    && a2disconf other-vhosts-access-log \
    && sed -ri 's/^Listen 80$/# Listen 80/' /etc/apache2/ports.conf \
    && sed -ri 's#^ErrorLog .*$#ErrorLog /tmp/random-image-api-error.log#' /etc/apache2/apache2.conf \
    && a2enconf zz-random-image-api

RUN groupadd --system --gid 10001 app \
    && useradd --system --no-create-home --uid 10001 --gid app --home-dir /app app \
    && mkdir -p /app/images /app/.runtime \
    && chown -R app:app /app/images /app/.runtime /var/lock/apache2 /var/log/apache2 /var/run/apache2 \
    && chmod +x /usr/local/bin/random-image-api-entrypoint

ENV RI_SERVER_PORT=3000 \
    RI_IMAGE_ROOT=images \
    RI_RUN_USER=app

VOLUME ["/app/images", "/app/.runtime"]

EXPOSE 3000

HEALTHCHECK --interval=30s --timeout=3s --start-period=10s --retries=3 \
  CMD php -r '$port = (int)(getenv("RI_SERVER_PORT") ?: 3000); $host = (string)(getenv("RI_HEALTHCHECK_HOST") ?: ""); if ($host === "") { $allowed = (string)(getenv("RI_ALLOWED_HOSTS") ?: "localhost"); $host = trim(explode(",", $allowed)[0] ?? "localhost"); } if ($host === "" || preg_match("/[\r\n]/", $host)) exit(1); $fp = @fsockopen("127.0.0.1", $port, $errno, $errstr, 2); if (!$fp) exit(1); fwrite($fp, "GET /_health HTTP/1.1\r\nHost: " . $host . "\r\nConnection: close\r\n\r\n"); $body = stream_get_contents($fp); fclose($fp); exit(str_contains($body, "\"ok\":true") ? 0 : 1);'

ENTRYPOINT ["random-image-api-entrypoint"]
