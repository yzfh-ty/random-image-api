<?php

declare(strict_types=1);

function ri_load_config(string $baseDir): array
{
    $path = $baseDir . DIRECTORY_SEPARATOR . 'config.json';
    if (!is_file($path)) {
        ri_send_error(500, 'invalid_config', 'config.json is missing.');
    }

    $decoded = json_decode((string)file_get_contents($path), true);
    if (!is_array($decoded)) {
        ri_send_error(500, 'invalid_config', 'config.json is invalid JSON.');
    }

    return ri_normalize_config($decoded);
}
function ri_normalize_config(array $raw): array
{
    $config = [
        'server' => [
            'host' => (string)($raw['server']['host'] ?? '0.0.0.0'),
            'port' => (int)($raw['server']['port'] ?? 3000),
            'trustProxy' => (bool)($raw['server']['trustProxy'] ?? false),
            'allowedHosts' => array_values($raw['server']['allowedHosts'] ?? []),
        ],
        'imageRoot' => (string)($raw['imageRoot'] ?? 'images'),
        'folders' => array_values($raw['folders'] ?? []),
        'linkFiles' => array_values($raw['linkFiles'] ?? ['links.txt']),
        'adminPrefix' => (string)($raw['adminPrefix'] ?? '/_api'),
        'adminEnabled' => (bool)($raw['adminEnabled'] ?? false),
        'adminToken' => ri_config_string_with_env($raw['adminToken'] ?? null, 'RI_ADMIN_TOKEN'),
        'adminAllowQueryToken' => (bool)($raw['adminAllowQueryToken'] ?? false),
        'indexDatabase' => (string)($raw['indexDatabase'] ?? '.runtime/image-index.sqlite'),
        'indexLock' => (string)($raw['indexLock'] ?? '.runtime/index.lock'),
        'indexLog' => (string)($raw['indexLog'] ?? '.runtime/index.log'),
        'imageExtensions' => array_values($raw['imageExtensions'] ?? RI_DEFAULT_IMAGE_EXTENSIONS),
        'allowSvg' => (bool)($raw['allowSvg'] ?? false),
        'defaultMode' => (string)($raw['defaultMode'] ?? 'redirect'),
        'linkCheck' => [
            'timeoutSeconds' => (int)($raw['linkCheck']['timeoutSeconds'] ?? 5),
            'userAgent' => (string)($raw['linkCheck']['userAgent'] ?? 'random-image-api/1.0'),
            'proxy' => ri_config_string_with_env($raw['linkCheck']['proxy'] ?? null, 'RI_HTTP_PROXY'),
            'verifyTls' => ri_config_bool_with_env($raw['linkCheck']['verifyTls'] ?? null, 'RI_LINKCHECK_VERIFY_TLS', true),
            'allowedHosts' => array_values($raw['linkCheck']['allowedHosts'] ?? []),
        ],
        'sendfile' => [
            'mode' => (string)($raw['sendfile']['mode'] ?? 'php'),
            'xAccelPrefix' => (string)($raw['sendfile']['xAccelPrefix'] ?? ''),
        ],
    ];

    if ($config['folders'] === []) {
        ri_send_error(500, 'invalid_config', 'At least one folder must be configured.');
    }

    foreach ($config['server']['allowedHosts'] as $host) {
        if (!is_string($host) || !ri_is_safe_host($host)) {
            ri_send_error(500, 'invalid_config', 'Invalid allowed host: ' . (string)$host);
        }
    }

    if (!preg_match('/^\/[A-Za-z0-9_-]+$/', $config['adminPrefix'])) {
        ri_send_error(500, 'invalid_config', 'Invalid route prefix: adminPrefix.');
    }

    if ($config['adminEnabled'] && $config['adminToken'] === '') {
        ri_send_error(500, 'invalid_config', 'adminToken or RI_ADMIN_TOKEN is required when adminEnabled is true.');
    }

    $adminFolder = ltrim($config['adminPrefix'], '/');
    $seenFolders = [];
    foreach ($config['folders'] as $folder) {
        if (!is_string($folder) || !ri_is_safe_segment($folder) || in_array($folder, RI_RESERVED_FOLDERS, true) || $folder === $adminFolder) {
            ri_send_error(500, 'invalid_config', 'Invalid or reserved folder name: ' . (string)$folder);
        }

        if (isset($seenFolders[$folder])) {
            ri_send_error(500, 'invalid_config', 'Duplicate folder configured: ' . $folder);
        }

        $seenFolders[$folder] = true;
    }

    foreach ($config['linkFiles'] as $fileName) {
        if (!is_string($fileName) || !ri_is_safe_segment($fileName)) {
            ri_send_error(500, 'invalid_config', 'Invalid link file name: ' . (string)$fileName);
        }
    }

    $extensions = [];
    foreach ($config['imageExtensions'] as $extension) {
        if (!is_string($extension) || !preg_match('/^\.[A-Za-z0-9]+$/', $extension)) {
            ri_send_error(500, 'invalid_config', 'Invalid image extension: ' . (string)$extension);
        }

        if (strtolower($extension) === '.svg' && !$config['allowSvg']) {
            continue;
        }

        $extensions[] = strtolower($extension);
    }
    $config['imageExtensions'] = array_values(array_unique($extensions));

    if (!in_array($config['defaultMode'], ['redirect', 'json'], true)) {
        ri_send_error(500, 'invalid_config', 'defaultMode must be redirect or json.');
    }

    if ($config['linkCheck']['timeoutSeconds'] < 1 || $config['linkCheck']['timeoutSeconds'] > 60) {
        ri_send_error(500, 'invalid_config', 'linkCheck.timeoutSeconds must be between 1 and 60.');
    }
    $config['linkCheck']['proxy'] = trim($config['linkCheck']['proxy']);
    foreach ($config['linkCheck']['allowedHosts'] as $host) {
        if (!is_string($host) || !ri_is_safe_allowed_remote_host($host)) {
            ri_send_error(500, 'invalid_config', 'Invalid linkCheck allowed host: ' . (string)$host);
        }
    }

    if (!in_array($config['sendfile']['mode'], ['php', 'x-sendfile', 'x-accel'], true)) {
        ri_send_error(500, 'invalid_config', 'sendfile.mode must be php, x-sendfile, or x-accel.');
    }

    if ($config['sendfile']['mode'] === 'x-accel' && $config['sendfile']['xAccelPrefix'] === '') {
        ri_send_error(500, 'invalid_config', 'sendfile.xAccelPrefix is required when sendfile.mode is x-accel.');
    }

    return $config;
}

function ri_config_string_with_env(mixed $value, string $envName): string
{
    if (is_string($value) && trim($value) !== '') {
        return $value;
    }

    $envValue = getenv($envName);
    return is_string($envValue) ? $envValue : '';
}

function ri_config_bool_with_env(mixed $value, string $envName, bool $default): bool
{
    $envValue = getenv($envName);
    if (is_string($envValue) && $envValue !== '') {
        return in_array(strtolower($envValue), ['1', 'true', 'yes', 'on'], true);
    }

    if (is_bool($value)) {
        return $value;
    }

    return $default;
}
