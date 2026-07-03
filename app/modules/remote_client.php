<?php

declare(strict_types=1);

function ri_run_remote_curl_multi_requests(array $requests, array $config, int $concurrency): array
{
    $multi = curl_multi_init();
    if ($multi === false) {
        return ri_run_remote_curl_requests_sequentially($requests, $config);
    }

    $pending = array_values($requests);
    $active = [];
    $responses = [];

    while ($pending !== [] || $active !== []) {
        while (count($active) < $concurrency && $pending !== []) {
            $request = array_shift($pending);
            $startedAt = microtime(true);
            $error = null;
            $handle = ri_create_remote_curl_handle($request['url'], $config, $request['method'], $error);
            if ($handle === null) {
                $responses[] = array_merge($request, [
                    'ok' => false,
                    'statusCode' => null,
                    'error' => $error ?? 'Cannot initialize cURL.',
                    'durationMs' => 0,
                    'response' => null,
                ]);
                continue;
            }

            $key = spl_object_id($handle);
            $active[$key] = [
                'handle' => $handle,
                'request' => $request,
                'startedAt' => $startedAt,
            ];
            curl_multi_add_handle($multi, $handle);
        }

        do {
            $status = curl_multi_exec($multi, $running);
        } while ($status === CURLM_CALL_MULTI_PERFORM);

        while ($info = curl_multi_info_read($multi)) {
            $handle = $info['handle'];
            $key = spl_object_id($handle);
            if (!isset($active[$key])) {
                curl_multi_remove_handle($multi, $handle);
                curl_close($handle);
                continue;
            }

            $meta = $active[$key];
            $response = curl_multi_getcontent($handle);
            $responses[] = array_merge(
                $meta['request'],
                ri_remote_curl_handle_result($handle, $response, $meta['startedAt'])
            );
            curl_multi_remove_handle($multi, $handle);
            curl_close($handle);
            unset($active[$key]);
        }

        if ($active !== []) {
            $selected = curl_multi_select($multi, 1.0);
            if ($selected === -1) {
                usleep(10000);
            }
        }
    }

    curl_multi_close($multi);
    return $responses;
}

function ri_run_remote_curl_requests_sequentially(array $requests, array $config): array
{
    $responses = [];
    foreach ($requests as $request) {
        $responses[] = array_merge(
            $request,
            ri_request_remote_url_with_curl($request['url'], $config, $request['method'], $request['redirectsRemaining'])
        ) + ['response' => null];
    }

    return $responses;
}

function ri_request_remote_url(string $url, array $config, string $method, int $redirectsRemaining = 5): array
{
    if (function_exists('curl_init')) {
        return ri_request_remote_url_with_curl($url, $config, $method, $redirectsRemaining);
    }

    if (!ri_remote_stream_fallback_allowed($config)) {
        return [
            'ok' => false,
            'statusCode' => null,
            'error' => 'Remote checks require cURL when resolved-IP binding is enabled without a proxy.',
            'durationMs' => 0,
        ];
    }

    $headers = 'User-Agent: ' . $config['linkCheck']['userAgent'] . "\r\n";
    if ($method === 'GET') {
        $headers .= "Range: bytes=0-0\r\n";
    }

    $contextOptions = [
        'http' => [
            'method' => $method,
            'timeout' => $config['linkCheck']['timeoutSeconds'],
            'ignore_errors' => true,
            'follow_location' => 0,
            'header' => $headers,
        ],
        'ssl' => [
            'verify_peer' => $config['linkCheck']['verifyTls'],
            'verify_peer_name' => $config['linkCheck']['verifyTls'],
        ],
    ];

    if ($config['linkCheck']['proxy'] !== '') {
        $contextOptions['http']['proxy'] = ri_normalize_stream_proxy($config['linkCheck']['proxy']);
        $contextOptions['http']['request_fulluri'] = true;
    }

    $context = stream_context_create($contextOptions);

    $handle = @fopen($url, 'r', false, $context);
    if ($handle === false) {
        $error = error_get_last();
        return [
            'ok' => false,
            'statusCode' => null,
            'error' => $error['message'] ?? 'Request failed.',
            'durationMs' => 0,
        ];
    }

    $meta = stream_get_meta_data($handle);
    fclose($handle);
    $statusCode = ri_status_code_from_wrapper_data($meta['wrapper_data'] ?? []);
    if ($statusCode !== null && $statusCode >= 300 && $statusCode < 400 && $redirectsRemaining > 0) {
        $location = ri_location_from_headers($meta['wrapper_data'] ?? []);
        if ($location !== null) {
            $redirectUrl = ri_resolve_redirect_url($url, $location);
            if ($redirectUrl === null || !ri_is_safe_remote_url($redirectUrl, $config, true)) {
                return [
                    'ok' => false,
                    'statusCode' => $statusCode,
                    'error' => 'Redirect target is not allowed.',
                    'durationMs' => 0,
                ];
            }

            return ri_request_remote_url($redirectUrl, $config, $method, $redirectsRemaining - 1);
        }
    }

    return [
        'ok' => $statusCode !== null && $statusCode >= 200 && $statusCode < 400,
        'statusCode' => $statusCode,
        'error' => $statusCode === null ? 'No HTTP status returned.' : null,
        'durationMs' => 0,
    ];
}

function ri_remote_stream_fallback_allowed(array $config): bool
{
    return !($config['linkCheck']['bindResolvedIp'] ?? true)
        || $config['linkCheck']['proxy'] !== '';
}

function ri_request_remote_url_with_curl(string $url, array $config, string $method, int $redirectsRemaining): array
{
    $startedAt = microtime(true);
    $error = null;
    $handle = ri_create_remote_curl_handle($url, $config, $method, $error);
    if ($handle === null) {
        return [
            'ok' => false,
            'statusCode' => null,
            'error' => $error ?? 'Cannot initialize cURL.',
            'durationMs' => 0,
        ];
    }

    $response = curl_exec($handle);
    $result = ri_remote_curl_handle_result($handle, $response, $startedAt);
    curl_close($handle);

    if ($result['error'] !== null) {
        return ri_public_remote_result($result);
    }

    $statusCode = $result['statusCode'];
    if ($statusCode !== null && $statusCode >= 300 && $statusCode < 400 && $redirectsRemaining > 0 && is_string($result['response'])) {
        $location = ri_location_from_header_string($result['response']);
        if ($location !== null) {
            $redirectUrl = ri_resolve_redirect_url($url, $location);
            if ($redirectUrl === null || !ri_is_safe_remote_url($redirectUrl, $config, true)) {
                return [
                    'ok' => false,
                    'statusCode' => $statusCode,
                    'error' => 'Redirect target is not allowed.',
                    'durationMs' => (int)round((microtime(true) - $startedAt) * 1000),
                ];
            }

            return ri_request_remote_url_with_curl($redirectUrl, $config, $method, $redirectsRemaining - 1);
        }
    }

    return ri_public_remote_result($result);
}

function ri_create_remote_curl_handle(string $url, array $config, string $method, ?string &$error = null)
{
    $handle = curl_init($url);
    if ($handle === false) {
        $error = 'Cannot initialize cURL.';
        return null;
    }

    curl_setopt_array($handle, [
        CURLOPT_NOBODY => $method === 'HEAD',
        CURLOPT_RETURNTRANSFER => false,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT => $config['linkCheck']['timeoutSeconds'],
        CURLOPT_USERAGENT => $config['linkCheck']['userAgent'],
        CURLOPT_HEADER => false,
        CURLOPT_SSL_VERIFYPEER => $config['linkCheck']['verifyTls'],
        CURLOPT_SSL_VERIFYHOST => $config['linkCheck']['verifyTls'] ? 2 : 0,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
    ]);
    ri_attach_remote_curl_capture($handle);

    if ($method === 'GET') {
        curl_setopt($handle, CURLOPT_RANGE, '0-0');
    }

    if ($config['linkCheck']['proxy'] !== '') {
        curl_setopt($handle, CURLOPT_PROXY, $config['linkCheck']['proxy']);
    }

    $resolveEntries = ri_curl_resolve_entries_for_url($url, $config, $error);
    if ($error !== null) {
        curl_close($handle);
        return null;
    }

    if ($resolveEntries !== []) {
        curl_setopt($handle, CURLOPT_RESOLVE, $resolveEntries);
    }

    return $handle;
}

function ri_attach_remote_curl_capture($handle): void
{
    $key = spl_object_id($handle);
    $GLOBALS['ri_remote_curl_context'][$key] = [
        'headers' => '',
        'bodyBytes' => 0,
        'bodyLimitExceeded' => false,
    ];

    curl_setopt($handle, CURLOPT_HEADERFUNCTION, static function ($handle, string $header): int {
        $key = spl_object_id($handle);
        if (isset($GLOBALS['ri_remote_curl_context'][$key])) {
            $current = (string)$GLOBALS['ri_remote_curl_context'][$key]['headers'];
            if (strlen($current) < 65536) {
                $GLOBALS['ri_remote_curl_context'][$key]['headers'] = $current . $header;
            }
        }

        return strlen($header);
    });

    curl_setopt($handle, CURLOPT_WRITEFUNCTION, static function ($handle, string $chunk): int {
        $key = spl_object_id($handle);
        $length = strlen($chunk);
        if (!isset($GLOBALS['ri_remote_curl_context'][$key])) {
            return $length;
        }

        $bodyBytes = (int)$GLOBALS['ri_remote_curl_context'][$key]['bodyBytes'] + $length;
        if ($bodyBytes > 1024) {
            $GLOBALS['ri_remote_curl_context'][$key]['bodyLimitExceeded'] = true;
            return 0;
        }

        $GLOBALS['ri_remote_curl_context'][$key]['bodyBytes'] = $bodyBytes;
        return $length;
    });
}

function ri_take_remote_curl_context($handle): array
{
    $key = spl_object_id($handle);
    $context = $GLOBALS['ri_remote_curl_context'][$key] ?? [];
    unset($GLOBALS['ri_remote_curl_context'][$key]);

    return is_array($context) ? $context : [];
}

function ri_curl_resolve_entries_for_url(string $url, array $config, ?string &$error = null): array
{
    if (
        !($config['linkCheck']['bindResolvedIp'] ?? true)
        || $config['linkCheck']['proxy'] !== ''
    ) {
        return [];
    }

    $host = parse_url($url, PHP_URL_HOST);
    $scheme = parse_url($url, PHP_URL_SCHEME);
    if (!is_string($host) || !is_string($scheme)) {
        $error = 'Remote URL is invalid.';
        return [];
    }

    if (filter_var(trim($host, '[]'), FILTER_VALIDATE_IP) !== false) {
        return [];
    }

    $port = parse_url($url, PHP_URL_PORT);
    if (!is_int($port)) {
        $port = strtolower($scheme) === 'https' ? 443 : 80;
    }

    $addresses = ri_resolved_public_host_addresses($host);
    if ($addresses === []) {
        $error = 'Remote URL host did not resolve to public addresses.';
        return [];
    }

    $address = $addresses[0];
    if (str_contains($address, ':') && !str_starts_with($address, '[')) {
        $address = '[' . $address . ']';
    }

    return [$host . ':' . $port . ':' . $address];
}

function ri_remote_curl_handle_result($handle, mixed $response, float $startedAt): array
{
    $context = ri_take_remote_curl_context($handle);
    $capturedHeaders = is_string($context['headers'] ?? null) ? (string)$context['headers'] : null;
    $bodyLimitExceeded = (bool)($context['bodyLimitExceeded'] ?? false);
    $error = curl_error($handle);
    $errno = curl_errno($handle);
    $statusCode = (int)curl_getinfo($handle, CURLINFO_RESPONSE_CODE);

    if ($errno !== 0 && !$bodyLimitExceeded) {
        return [
            'ok' => false,
            'statusCode' => $statusCode > 0 ? $statusCode : null,
            'error' => $error,
            'durationMs' => (int)round((microtime(true) - $startedAt) * 1000),
            'response' => $capturedHeaders ?? (is_string($response) ? $response : null),
        ];
    }

    return [
        'ok' => $statusCode >= 200 && $statusCode < 400,
        'statusCode' => $statusCode > 0 ? $statusCode : null,
        'error' => null,
        'durationMs' => (int)round((microtime(true) - $startedAt) * 1000),
        'response' => $capturedHeaders ?? (is_string($response) ? $response : null),
    ];
}

function ri_public_remote_result(array $result): array
{
    return [
        'ok' => $result['ok'],
        'statusCode' => $result['statusCode'],
        'error' => $result['error'],
        'durationMs' => $result['durationMs'],
    ];
}

function ri_location_from_headers(array $headers): ?string
{
    foreach (array_reverse($headers) as $header) {
        if (is_string($header) && stripos($header, 'Location:') === 0) {
            return trim(substr($header, strlen('Location:')));
        }
    }

    return null;
}

function ri_location_from_header_string(string $headers): ?string
{
    if (preg_match_all('/^Location:\s*(.+)$/im', $headers, $matches) < 1) {
        return null;
    }

    return trim((string)end($matches[1]));
}

function ri_resolve_redirect_url(string $baseUrl, string $location): ?string
{
    $location = trim($location);
    if ($location === '' || preg_match('/[\x00-\x1F\x7F]/', $location) === 1 || str_contains($location, '\\')) {
        return null;
    }

    if (ri_is_http_url($location)) {
        return $location;
    }

    if (str_starts_with($location, '//')) {
        $scheme = parse_url($baseUrl, PHP_URL_SCHEME) ?: 'https';
        return $scheme . ':' . $location;
    }

    $scheme = parse_url($baseUrl, PHP_URL_SCHEME);
    $host = parse_url($baseUrl, PHP_URL_HOST);
    if (!is_string($scheme) || !is_string($host)) {
        return null;
    }

    $port = parse_url($baseUrl, PHP_URL_PORT);
    $authority = $scheme . '://' . $host . (is_int($port) ? ':' . $port : '');
    if (str_starts_with($location, '/')) {
        return $authority . $location;
    }

    $path = parse_url($baseUrl, PHP_URL_PATH);
    if (str_starts_with($location, '?')) {
        return $authority . (is_string($path) && $path !== '' ? $path : '/') . $location;
    }

    $directory = is_string($path) ? preg_replace('#/[^/]*$#', '/', $path) : '/';
    return $authority . $directory . $location;
}

function ri_normalize_stream_proxy(string $proxy): string
{
    $proxy = trim($proxy);
    if ($proxy === '') {
        return '';
    }

    if (str_starts_with($proxy, 'tcp://')) {
        return $proxy;
    }

    $host = parse_url($proxy, PHP_URL_HOST);
    $port = parse_url($proxy, PHP_URL_PORT);
    if (is_string($host) && is_int($port)) {
        return 'tcp://' . $host . ':' . $port;
    }

    return $proxy;
}

function ri_status_code_from_wrapper_data(array $headers): ?int
{
    foreach ($headers as $header) {
        if (is_string($header) && preg_match('/^HTTP\/\S+\s+(\d{3})\b/', $header, $matches) === 1) {
            return (int)$matches[1];
        }
    }

    return null;
}
