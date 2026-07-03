<?php

declare(strict_types=1);

function ri_select_and_remember_item(
    array $items,
    string $scopePath,
    ?string $source,
    ?array $config = null,
    ?string $imageType = null
): array
{
    ri_start_session($config);
    $_SESSION['last_served'] ??= [];
    $scopeKey = $scopePath . '|' . ($source ?? 'all') . '|' . ($imageType ?? 'all-types');
    $lastKey = is_string($_SESSION['last_served'][$scopeKey] ?? null) ? $_SESSION['last_served'][$scopeKey] : null;
    $item = ri_pick_item($items, $lastKey);
    $_SESSION['last_served'][$scopeKey] = ri_image_key($item);
    session_write_close();

    return $item;
}
function ri_pick_item(array $items, ?string $lastKey): array
{
    $candidates = $items;
    if (count($items) > 1 && $lastKey !== null) {
        $candidates = array_values(array_filter(
            $items,
            static fn(array $item): bool => ri_image_key($item) !== $lastKey
        ));
    }

    return $candidates[random_int(0, count($candidates) - 1)];
}

function ri_image_key(array $item): string
{
    return $item['folder'] . ':' . $item['id'];
}

function ri_filter_items(array $items, ?string $source, ?string $imageType = null): array
{
    $filtered = $items;
    if ($source === 'local' || $source === 'remote') {
        $filtered = array_values(array_filter($filtered, static fn(array $item): bool => $item['sourceType'] === $source));
    }

    if ($imageType === RI_IMAGE_TYPE_PC || $imageType === RI_IMAGE_TYPE_MOBILE) {
        $filtered = array_values(array_filter(
            $filtered,
            static fn(array $item): bool => ($item['orientation'] ?? RI_IMAGE_TYPE_UNKNOWN) === $imageType
        ));
    }

    return array_values($filtered);
}

function ri_source_filter(): ?string
{
    $source = $_GET['source'] ?? null;
    return ($source === 'local' || $source === 'remote') ? $source : null;
}

function ri_output_local_image(array $item, array $config): void
{
    $path = $item['absolutePath'];
    $folderPath = $item['folderPath'] ?? null;
    if (!is_string($folderPath) || is_link($path) || !is_file($path) || !is_readable($path)) {
        ri_send_error(404, 'not_found', 'Image not found.');
    }

    $realPath = realpath($path);
    $realFolderPath = realpath($folderPath);
    if ($realPath === false || $realFolderPath === false || !ri_is_real_path_inside($realFolderPath, $realPath)) {
        ri_send_error(404, 'not_found', 'Image not found.');
    }

    $mtime = filemtime($realPath);
    $size = filesize($realPath);
    if ($mtime === false || $size === false) {
        ri_send_error(404, 'not_found', 'Image not found.');
    }

    $etag = '"' . md5($realPath . '|' . $size . '|' . $mtime) . '"';
    ri_security_headers();
    header('Content-Type: ' . ri_mime_type($realPath));
    header('Content-Length: ' . (string)$size);
    header('Cache-Control: public, max-age=86400');
    header('ETag: ' . $etag);
    header('Last-Modified: ' . gmdate('D, d M Y H:i:s', (int)$mtime) . ' GMT');

    if (($_SERVER['HTTP_IF_NONE_MATCH'] ?? '') === $etag) {
        http_response_code(304);
        exit;
    }

    if ($config['sendfile']['mode'] === 'x-sendfile') {
        header('X-Sendfile: ' . $realPath);
        exit;
    }

    if ($config['sendfile']['mode'] === 'x-accel') {
        $internalPath = rtrim($config['sendfile']['xAccelPrefix'], '/')
            . '/'
            . rawurlencode($item['folder'])
            . '/'
            . ri_encode_url_path($item['relativePath']);
        header('X-Accel-Redirect: ' . $internalPath);
        exit;
    }

    if (ri_is_head_request()) {
        exit;
    }

    readfile($realPath);
    exit;
}

function ri_public_url(array $item, array $config): string
{
    return ri_request_origin($config) . '/' . rawurlencode($item['folder']) . '/' . $item['id'] . '.' . rawurlencode($item['extension']);
}

function ri_request_origin(array $config): string
{
    $trustProxy = (bool)($config['server']['trustProxy'] ?? false);
    $proto = $trustProxy ? ri_first_header('HTTP_X_FORWARDED_PROTO') : null;
    $host = $trustProxy ? ri_first_header('HTTP_X_FORWARDED_HOST') : null;

    if ($proto !== 'http' && $proto !== 'https') {
        $proto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    }

    if ($host === null) {
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    }

    if (!is_string($host) || !ri_is_safe_host($host)) {
        ri_send_error(400, 'invalid_host', 'Invalid Host header.');
    }

    if (!ri_is_allowed_host($host, $config['server']['allowedHosts'] ?? [])) {
        ri_send_error(400, 'host_not_allowed', 'Host is not allowed.');
    }

    return $proto . '://' . $host;
}

function ri_send_json(int $statusCode, array $payload): void
{
    http_response_code($statusCode);
    ri_no_store_headers();
    header('Content-Type: application/json; charset=UTF-8');
    if (ri_is_head_request()) {
        exit;
    }

    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function ri_send_error(int $statusCode, string $error, string $message): void
{
    if (PHP_SAPI === 'cli') {
        throw new RuntimeException($message);
    }

    ri_send_json($statusCode, ['error' => $error, 'message' => $message]);
}

function ri_is_browser_request(): bool
{
    $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
    return is_string($accept) && str_contains(strtolower($accept), 'text/html');
}

function ri_render_image_page(string $url): void
{
    http_response_code(200);
    ri_no_store_headers();
    header("Content-Security-Policy: default-src 'none'; img-src 'self' http: https: data:; style-src 'unsafe-inline'; base-uri 'none'; frame-ancestors 'none'");
    header('Content-Type: text/html; charset=UTF-8');
    if (ri_is_head_request()) {
        exit;
    }

    $escapedUrl = htmlspecialchars($url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    echo <<<HTML
<!doctype html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>Random Image</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        html, body { width: 100%; min-height: 100%; background: #000; }
        body { display: flex; align-items: center; justify-content: center; min-height: 100vh; }
        img { display: block; width: auto; height: auto; max-width: 100vw; max-height: 100vh; object-fit: contain; }
    </style>
</head>
<body>
    <img src="{$escapedUrl}" alt="Random Image">
</body>
</html>
HTML;
    exit;
}

function ri_no_store_headers(): void
{
    ri_security_headers();
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
}

function ri_security_headers(): void
{
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: no-referrer');
    header('Accept-CH: Sec-CH-UA-Mobile, Sec-CH-Viewport-Width, Viewport-Width');
}

function ri_is_head_request(): bool
{
    return ($_SERVER['REQUEST_METHOD'] ?? '') === 'HEAD';
}

function ri_enforce_request_method(): void
{
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if ($method === 'GET' || $method === 'HEAD') {
        return;
    }

    header('Allow: GET, HEAD');
    ri_send_error(405, 'method_not_allowed', 'Only GET and HEAD requests are allowed.');
}

function ri_start_session(?array $config = null): void
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start([
            'cookie_httponly' => true,
            'cookie_samesite' => 'Lax',
            'cookie_secure' => ri_is_secure_request($config),
            'use_strict_mode' => true,
        ]);
    }
}

function ri_is_secure_request(?array $config = null): bool
{
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        return true;
    }

    if (($config['server']['trustProxy'] ?? false) === true) {
        return ri_first_header('HTTP_X_FORWARDED_PROTO') === 'https';
    }

    return false;
}
