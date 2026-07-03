<?php

declare(strict_types=1);

function ri_is_safe_host(string $host): bool
{
    if ($host === '' || preg_match('/[\x00-\x1F\x7F\/\\\\]/', $host) === 1) {
        return false;
    }

    if (preg_match('/^\[([0-9A-Fa-f:.]+)\](?::([0-9]{1,5}))?$/', $host, $matches) === 1) {
        return filter_var($matches[1], FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false
            && ri_is_valid_port($matches[2] ?? null);
    }

    if (preg_match('/^([A-Za-z0-9.-]+)(?::([0-9]{1,5}))?$/', $host, $matches) !== 1) {
        return false;
    }

    return ri_is_valid_hostname($matches[1]) && ri_is_valid_port($matches[2] ?? null);
}
function ri_is_valid_hostname(string $host): bool
{
    if ($host === '' || strlen($host) > 253 || str_contains($host, '..') || str_starts_with($host, '.') || str_ends_with($host, '.')) {
        return false;
    }

    foreach (explode('.', $host) as $label) {
        if (
            $label === ''
            || strlen($label) > 63
            || !preg_match('/^[A-Za-z0-9-]+$/', $label)
            || str_starts_with($label, '-')
            || str_ends_with($label, '-')
        ) {
            return false;
        }
    }

    return true;
}

function ri_is_valid_port(?string $port): bool
{
    if ($port === null || $port === '') {
        return true;
    }

    $value = (int)$port;
    return $value >= 1 && $value <= 65535;
}

function ri_is_allowed_host(string $host, array $allowedHosts): bool
{
    if ($allowedHosts === []) {
        return true;
    }

    $host = strtolower($host);
    $hostWithoutPort = ri_host_without_port($host);
    foreach ($allowedHosts as $allowedHost) {
        if (!is_string($allowedHost)) {
            continue;
        }

        $allowedHost = strtolower($allowedHost);
        if ($host === $allowedHost || $hostWithoutPort === $allowedHost) {
            return true;
        }
    }

    return false;
}

function ri_host_without_port(string $host): string
{
    if (str_starts_with($host, '[')) {
        $end = strpos($host, ']');
        return $end === false ? $host : substr($host, 0, $end + 1);
    }

    $colon = strrpos($host, ':');
    if ($colon === false) {
        return $host;
    }

    return substr($host, 0, $colon);
}

function ri_first_header(string $key): ?string
{
    $value = $_SERVER[$key] ?? null;
    if (!is_string($value) || trim($value) === '') {
        return null;
    }

    return trim(explode(',', $value)[0]);
}

function ri_request_path(): string
{
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
    return trim(is_string($path) ? $path : '/', '/');
}

function ri_decode_path_segments(string $path): array
{
    if ($path === '') {
        return [];
    }

    $segments = [];
    foreach (explode('/', $path) as $segment) {
        if ($segment === '') {
            continue;
        }

        $decoded = rawurldecode($segment);
        if (!ri_is_safe_segment($decoded)) {
            return [];
        }

        $segments[] = $decoded;
    }

    return $segments;
}

function ri_is_safe_segment(string $segment): bool
{
    return $segment !== ''
        && $segment !== '.'
        && $segment !== '..'
        && !str_contains($segment, '/')
        && !str_contains($segment, '\\')
        && !str_contains($segment, "\0");
}

function ri_is_safe_relative_path(string $path): bool
{
    $path = trim(str_replace('\\', '/', $path), '/');
    if ($path === '') {
        return false;
    }

    foreach (explode('/', $path) as $segment) {
        if (!ri_is_safe_segment($segment)) {
            return false;
        }
    }

    return true;
}

function ri_is_http_url(string $value): bool
{
    $value = trim($value);
    if ($value === '' || preg_match('/[\x00-\x20\x7F]/', $value) === 1 || str_contains($value, '\\')) {
        return false;
    }

    $scheme = parse_url($value, PHP_URL_SCHEME);
    $host = parse_url($value, PHP_URL_HOST);
    return is_string($scheme)
        && in_array(strtolower($scheme), ['http', 'https'], true)
        && is_string($host)
        && ri_is_safe_url_host($host);
}

function ri_is_safe_url_host(string $host): bool
{
    if ($host === '' || preg_match('/[\x00-\x1F\x7F\/\\\\]/', $host) === 1) {
        return false;
    }

    if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
        return true;
    }

    return ri_is_safe_host($host);
}

function ri_extension_from_url(string $url, array $config): string
{
    $path = parse_url($url, PHP_URL_PATH);
    if (is_string($path)) {
        $extension = strtolower('.' . pathinfo($path, PATHINFO_EXTENSION));
        if (in_array($extension, $config['imageExtensions'], true)) {
            return ltrim($extension, '.');
        }
    }

    return RI_DEFAULT_REMOTE_EXTENSION;
}

function ri_resolve_path(string $root, string $path): string
{
    if (preg_match('/^[A-Za-z]:[\/\\\\]/', $path) === 1) {
        return rtrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path), DIRECTORY_SEPARATOR);
    }

    return rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);
}

function ri_is_inside(string $root, string $target): bool
{
    $rootReal = realpath($root);
    $targetReal = realpath($target);
    if ($rootReal === false || $targetReal === false) {
        return false;
    }

    return ri_is_real_path_inside($rootReal, $targetReal);
}

function ri_is_real_path_inside(string $rootReal, string $targetReal): bool
{
    $rootReal = rtrim($rootReal, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
    return str_starts_with($targetReal . (is_dir($targetReal) ? DIRECTORY_SEPARATOR : ''), $rootReal);
}

function ri_relative_path(string $root, string $target): string
{
    $rootTrimmed = rtrim((string)realpath($root), DIRECTORY_SEPARATOR);
    $rootReal = $rootTrimmed . DIRECTORY_SEPARATOR;
    $targetReal = (string)realpath($target);
    if ($targetReal === $rootTrimmed) {
        return '';
    }

    if (str_starts_with($targetReal, $rootReal)) {
        return substr($targetReal, strlen($rootReal));
    }

    return basename($targetReal);
}

function ri_to_url_path(string $value): string
{
    return str_replace(DIRECTORY_SEPARATOR, '/', $value);
}

function ri_encode_url_path(string $value): string
{
    $segments = array_map('rawurlencode', explode('/', trim($value, '/')));
    return implode('/', $segments);
}

function ri_sql_placeholders(string $prefix, int $count): array
{
    $placeholders = [];
    for ($index = 0; $index < $count; $index++) {
        $placeholders[':' . $prefix . $index] = null;
    }

    return $placeholders;
}

function ri_mime_type(string $path): string
{
    return match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
        'avif' => 'image/avif',
        'bmp' => 'image/bmp',
        'gif' => 'image/gif',
        'jpg', 'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'svg' => 'image/svg+xml',
        'webp' => 'image/webp',
        default => 'application/octet-stream',
    };
}
