FROM php:8.2-cli-alpine

RUN apk add --no-cache sqlite-dev \
    && docker-php-ext-install pdo_sqlite

WORKDIR /app

COPY app /app/app
COPY images /app/images
COPY config.json /app/config.json
COPY index.php /app/index.php
COPY cli.php /app/cli.php

EXPOSE 3000

HEALTHCHECK --interval=30s --timeout=3s --start-period=10s --retries=3 \
  CMD php -r "exit(@file_get_contents('http://127.0.0.1:3000/_api/folders') === false ? 1 : 0);"

CMD ["sh", "-lc", "php cli.php index && php -S 0.0.0.0:3000 index.php"]
