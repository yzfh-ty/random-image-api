<?php

declare(strict_types=1);

function ri_load_config(string $baseDir): array
{
    ri_load_env_file($baseDir);

    $path = $baseDir . DIRECTORY_SEPARATOR . 'config.json';
    if (!is_file($path)) {
        if (ri_env_string('RI_FOLDERS') === null) {
            ri_send_error(500, 'invalid_config', 'config.json is missing and RI_FOLDERS is not set.');
        }

        $decoded = [];
    } else {
        $decoded = json_decode((string)file_get_contents($path), true);
        if (!is_array($decoded)) {
            ri_send_error(500, 'invalid_config', 'config.json is invalid JSON.');
        }
    }

    return ri_normalize_config($decoded);
}

function ri_normalize_config(array $raw): array
{
    $config = [
        'server' => [
            'host' => ri_config_string_with_env($raw['server']['host'] ?? null, 'RI_SERVER_HOST', '0.0.0.0'),
            'port' => ri_config_int_with_env($raw['server']['port'] ?? null, 'RI_SERVER_PORT', 3000),
            'trustProxy' => ri_config_bool_with_env($raw['server']['trustProxy'] ?? null, 'RI_TRUST_PROXY', false),
            'allowedHosts' => ri_config_list_with_env($raw['server']['allowedHosts'] ?? null, 'RI_ALLOWED_HOSTS', []),
        ],
        'imageRoot' => ri_config_string_with_env($raw['imageRoot'] ?? null, 'RI_IMAGE_ROOT', 'images'),
        'folders' => ri_config_list_with_env($raw['folders'] ?? null, 'RI_FOLDERS', []),
        'linkFiles' => ri_config_list_with_env($raw['linkFiles'] ?? null, 'RI_LINK_FILES', ['links.txt']),
        'adminPrefix' => ri_config_string_with_env($raw['adminPrefix'] ?? null, 'RI_ADMIN_PREFIX', '/_api'),
        'adminEnabled' => ri_config_bool_with_env($raw['adminEnabled'] ?? null, 'RI_ADMIN_ENABLED', false),
        'adminToken' => ri_config_string_with_env($raw['adminToken'] ?? null, 'RI_ADMIN_TOKEN', ''),
        'adminAllowQueryToken' => ri_config_bool_with_env($raw['adminAllowQueryToken'] ?? null, 'RI_ADMIN_ALLOW_QUERY_TOKEN', false),
        'indexDatabase' => ri_config_string_with_env($raw['indexDatabase'] ?? null, 'RI_INDEX_DATABASE', '.runtime/image-index.sqlite'),
        'indexLock' => ri_config_string_with_env($raw['indexLock'] ?? null, 'RI_INDEX_LOCK', '.runtime/index.lock'),
        'indexLog' => ri_config_string_with_env($raw['indexLog'] ?? null, 'RI_INDEX_LOG', '.runtime/index.log'),
        'imageExtensions' => ri_config_list_with_env($raw['imageExtensions'] ?? null, 'RI_IMAGE_EXTENSIONS', RI_DEFAULT_IMAGE_EXTENSIONS),
        'allowSvg' => ri_config_bool_with_env($raw['allowSvg'] ?? null, 'RI_ALLOW_SVG', false),
        'defaultMode' => ri_config_string_with_env($raw['defaultMode'] ?? null, 'RI_DEFAULT_MODE', 'redirect'),
        'linkCheck' => [
            'timeoutSeconds' => ri_config_int_with_env($raw['linkCheck']['timeoutSeconds'] ?? null, 'RI_LINKCHECK_TIMEOUT', 5),
            'userAgent' => ri_config_string_with_env($raw['linkCheck']['userAgent'] ?? null, 'RI_LINKCHECK_USER_AGENT', 'random-image-api/1.0'),
            'proxy' => ri_config_string_with_env($raw['linkCheck']['proxy'] ?? null, 'RI_HTTP_PROXY', ''),
            'verifyTls' => ri_config_bool_with_env($raw['linkCheck']['verifyTls'] ?? null, 'RI_LINKCHECK_VERIFY_TLS', true),
            'allowedHosts' => ri_config_list_with_env($raw['linkCheck']['allowedHosts'] ?? null, 'RI_LINKCHECK_ALLOWED_HOSTS', []),
        ],
        'sendfile' => [
            'mode' => ri_config_string_with_env($raw['sendfile']['mode'] ?? null, 'RI_SENDFILE_MODE', 'php'),
            'xAccelPrefix' => ri_config_string_with_env($raw['sendfile']['xAccelPrefix'] ?? null, 'RI_X_ACCEL_PREFIX', ''),
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

function ri_load_env_file(string $baseDir): void
{
    $path = $baseDir . DIRECTORY_SEPARATOR . '.env';
    if (!is_file($path) || !is_readable($path)) {
        return;
    }

    $lines = file($path, FILE_IGNORE_NEW_LINES);
    if ($lines === false) {
        return;
    }

    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }

        if (str_starts_with($line, 'export ')) {
            $line = trim(substr($line, 7));
        }

        if (preg_match('/^([A-Za-z_][A-Za-z0-9_]*)\s*=\s*(.*)$/', $line, $matches) !== 1) {
            continue;
        }

        $name = $matches[1];
        if (getenv($name) !== false) {
            continue;
        }

        $value = ri_parse_env_value($matches[2]);
        putenv($name . '=' . $value);
        $_ENV[$name] = $value;
    }
}

function ri_parse_env_value(string $value): string
{
    $value = trim($value);
    if ($value === '') {
        return '';
    }

    $quote = $value[0];
    if (($quote === '"' || $quote === "'") && str_ends_with($value, $quote)) {
        $value = substr($value, 1, -1);
        return $quote === '"' ? stripcslashes($value) : $value;
    }

    return trim((string)preg_replace('/\s+#.*$/', '', $value));
}

function ri_env_string(string $envName): ?string
{
    $envValue = getenv($envName);
    if (!is_string($envValue) || trim($envValue) === '') {
        return null;
    }

    return trim($envValue);
}

function ri_config_string_with_env(mixed $value, string $envName, string $default): string
{
    $envValue = ri_env_string($envName);
    if ($envValue !== null) {
        return $envValue;
    }

    return is_string($value) && trim($value) !== '' ? trim($value) : $default;
}

function ri_config_bool_with_env(mixed $value, string $envName, bool $default): bool
{
    $envValue = ri_env_string($envName);
    if ($envValue !== null) {
        return ri_parse_config_bool($envValue, $envName);
    }

    if (is_bool($value)) {
        return $value;
    }

    if (is_string($value) && trim($value) !== '') {
        return ri_parse_config_bool($value, $envName);
    }

    return $default;
}

function ri_parse_config_bool(string $value, string $name): bool
{
    return match (strtolower(trim($value))) {
        '1', 'true', 'yes', 'on' => true,
        '0', 'false', 'no', 'off' => false,
        default => ri_invalid_bool_config($name),
    };
}

function ri_invalid_bool_config(string $name): bool
{
    ri_send_error(500, 'invalid_config', $name . ' must be a boolean value.');
}

function ri_config_int_with_env(mixed $value, string $envName, int $default): int
{
    $envValue = ri_env_string($envName);
    if ($envValue !== null) {
        return (int)$envValue;
    }

    if (is_int($value)) {
        return $value;
    }

    if (is_string($value) && trim($value) !== '') {
        return (int)$value;
    }

    return $default;
}

function ri_config_list_with_env(mixed $value, string $envName, array $default): array
{
    $envValue = ri_env_string($envName);
    if ($envValue !== null) {
        return ri_parse_env_list($envValue, $envName);
    }

    return is_array($value) ? array_values($value) : $default;
}

function ri_parse_env_list(string $value, string $envName): array
{
    $value = trim($value);
    if ($value === '') {
        return [];
    }

    if (str_starts_with($value, '[')) {
        $decoded = json_decode($value, true);
        if (!is_array($decoded)) {
            ri_send_error(500, 'invalid_config', $envName . ' must be a comma-separated list or JSON array.');
        }

        return array_values($decoded);
    }

    return array_values(array_filter(
        array_map(static fn(string $item): string => trim($item), explode(',', $value)),
        static fn(string $item): bool => $item !== ''
    ));
}
