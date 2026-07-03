FROM php:8.2-cli-alpine

RUN apk add --no-cache curl-dev sqlite-dev \
    && docker-php-ext-install curl pdo_sqlite

WORKDIR /app

COPY app /app/app
COPY public /app/public
COPY bin /app/bin
COPY .env.example /app/.env.example
COPY docker/entrypoint.sh /usr/local/bin/random-image-api-entrypoint

RUN mkdir -p /app/images /app/.runtime \
    && chmod +x /usr/local/bin/random-image-api-entrypoint

ENV RI_SERVER_HOST=0.0.0.0 \
    RI_SERVER_PORT=3000 \
    RI_IMAGE_ROOT=images

VOLUME ["/app/images", "/app/.runtime"]

EXPOSE 3000

HEALTHCHECK --interval=30s --timeout=3s --start-period=10s --retries=3 \
  CMD php -r '$port = (int)(getenv("RI_SERVER_PORT") ?: 3000); exit(@fsockopen("127.0.0.1", $port) ? 0 : 1);'

ENTRYPOINT ["random-image-api-entrypoint"]
