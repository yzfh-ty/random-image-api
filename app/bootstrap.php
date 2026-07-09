<?php

declare(strict_types=1);

const RI_RESERVED_FOLDERS = ['_api', '_assets', '_remote', '_health'];
const RI_DEFAULT_ALLOWED_HOSTS = ['localhost', 'localhost:3000', '127.0.0.1', '127.0.0.1:3000', '[::1]', '[::1]:3000'];
const RI_DEFAULT_IMAGE_EXTENSIONS = ['.jpg', '.jpeg', '.png', '.gif', '.webp', '.avif', '.bmp'];
const RI_DEFAULT_REMOTE_EXTENSION = 'jpg';
const RI_IMAGE_INDEX_SCHEMA_VERSION = 1;
const RI_IMAGE_TYPE_PC = 'pc';
const RI_IMAGE_TYPE_MOBILE = 'mobile';
const RI_IMAGE_TYPE_SQUARE = 'square';
const RI_IMAGE_TYPE_UNKNOWN = 'unknown';
const RI_MANAGED_TYPE_FOLDERS = [RI_IMAGE_TYPE_PC, RI_IMAGE_TYPE_MOBILE];

require __DIR__ . '/modules/support.php';
require __DIR__ . '/modules/image_type.php';
require __DIR__ . '/modules/config.php';
require __DIR__ . '/modules/database.php';
require __DIR__ . '/modules/remote_validation.php';
require __DIR__ . '/modules/remote_client.php';
require __DIR__ . '/modules/indexer.php';
require __DIR__ . '/modules/status.php';
require __DIR__ . '/modules/remote.php';
require __DIR__ . '/modules/response.php';
require __DIR__ . '/modules/http.php';
require __DIR__ . '/modules/cli.php';
