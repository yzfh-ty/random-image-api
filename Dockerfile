FROM php:8.2-cli-alpine

RUN apk add --no-cache sqlite-dev \
    && docker-php-ext-install pdo_sqlite

WORKDIR /app

COPY app /app/app
COPY public /app/public
COPY bin /app/bin
COPY .env.example /app/.env.example

RUN mkdir -p /app/images

EXPOSE 3000

HEALTHCHECK --interval=30s --timeout=3s --start-period=10s --retries=3 \
  CMD php -r "exit(@fsockopen('127.0.0.1', 3000) ? 0 : 1);"

CMD ["sh", "-lc", "[ -f .env ] || cp .env.example .env; php bin/console.php index && php -S 0.0.0.0:3000 -t public public/index.php"]
