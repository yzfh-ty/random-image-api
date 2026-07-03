<?php

declare(strict_types=1);

function ri_check_remote_links(string $baseDir, ?string $onlyFolder = null): array
{
    $config = ri_load_config($baseDir);
    if ($onlyFolder !== null && !in_array($onlyFolder, $config['folders'], true)) {
        throw new RuntimeException('Folder is not configured: ' . $onlyFolder);
    }

    $index = ri_open_image_index($config, $baseDir);
    $items = ri_remote_link_items($index, $onlyFolder);
    $checkedItems = ri_check_remote_link_items($items, $config);
    $results = [];
    $ok = 0;
    $failed = 0;

    foreach ($checkedItems as $result) {
        ri_store_remote_link_check($index, $result);
        $results[] = $result;
        $result['ok'] ? $ok++ : $failed++;
    }

    return [
        'total' => count($items),
        'ok' => $ok,
        'failed' => $failed,
        'items' => $results,
    ];
}

function ri_check_remote_link_items(array $items, array $config): array
{
    $concurrency = (int)$config['linkCheck']['concurrency'];
    if ($concurrency > 1 && count($items) > 1 && function_exists('curl_multi_init') && function_exists('curl_init')) {
        return ri_check_remote_link_items_with_curl_multi($items, $config, $concurrency);
    }

    $results = [];
    foreach ($items as $item) {
        $checked = ri_check_remote_url($item['url'], $config);
        $results[] = ri_remote_link_check_result($item, $checked);
    }

    return $results;
}

function ri_remote_link_check_result(array $item, array $checked): array
{
    return [
        'folder' => $item['folder'],
        'id' => $item['id'],
        'url' => $item['url'],
        'ok' => $checked['ok'],
        'statusCode' => $checked['statusCode'],
        'error' => $checked['error'],
        'durationMs' => $checked['durationMs'],
        'checkedAt' => gmdate('c'),
    ];
}

function ri_remote_link_items(PDO $index, ?string $folder): array
{
    if ($folder === null) {
        $statement = $index->query(
            'SELECT folder, id, target AS url
             FROM image_index
             WHERE source_type = "remote"
             ORDER BY folder, id'
        );
    } else {
        $statement = $index->prepare(
            'SELECT folder, id, target AS url
             FROM image_index
             WHERE source_type = "remote"
               AND folder = :folder
             ORDER BY folder, id'
        );
        $statement->execute([':folder' => $folder]);
    }

    $items = [];
    foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $items[] = [
            'folder' => (string)$row['folder'],
            'id' => (int)$row['id'],
            'url' => (string)$row['url'],
        ];
    }

    return $items;
}

function ri_check_remote_url(string $url, array $config): array
{
    if (!ri_is_safe_remote_url($url, $config, true)) {
        return [
            'ok' => false,
            'statusCode' => null,
            'error' => 'Remote URL host is not allowed.',
            'durationMs' => 0,
        ];
    }

    $startedAt = microtime(true);
    $lastResult = [
        'ok' => false,
        'statusCode' => null,
        'error' => null,
        'durationMs' => 0,
    ];

    foreach (['HEAD', 'GET'] as $method) {
        $result = ri_request_remote_url($url, $config, $method);
        $lastResult = $result;
        if ($result['ok'] || !in_array($result['statusCode'], [405, 403, 501], true)) {
            break;
        }
    }

    $lastResult['durationMs'] = (int)round((microtime(true) - $startedAt) * 1000);
    return $lastResult;
}

function ri_check_remote_link_items_with_curl_multi(array $items, array $config, int $concurrency): array
{
    $states = [];
    $requests = [];

    foreach ($items as $index => $item) {
        $states[$index] = [
            'item' => $item,
            'startedAt' => microtime(true),
            'done' => false,
            'checked' => null,
        ];

        if (!ri_is_safe_remote_url($item['url'], $config, true)) {
            $states[$index]['done'] = true;
            $states[$index]['checked'] = [
                'ok' => false,
                'statusCode' => null,
                'error' => 'Remote URL host is not allowed.',
                'durationMs' => 0,
            ];
            continue;
        }

        $requests[] = [
            'state' => $index,
            'url' => $item['url'],
            'method' => 'HEAD',
            'redirectsRemaining' => 5,
        ];
    }

    while ($requests !== []) {
        $responses = ri_run_remote_curl_multi_requests($requests, $config, $concurrency);
        $requests = [];
        foreach ($responses as $response) {
            ri_update_remote_link_state_from_response($states[$response['state']], $response, $requests, $config);
        }
    }

    $results = [];
    foreach ($states as $state) {
        if (!$state['done'] || !is_array($state['checked'])) {
            $state['checked'] = [
                'ok' => false,
                'statusCode' => null,
                'error' => 'Remote check did not complete.',
                'durationMs' => (int)round((microtime(true) - $state['startedAt']) * 1000),
            ];
        }

        $results[] = ri_remote_link_check_result($state['item'], $state['checked']);
    }

    return $results;
}

function ri_update_remote_link_state_from_response(array &$state, array $response, array &$nextRequests, array $config): void
{
    $statusCode = $response['statusCode'];
    if ($statusCode !== null && $statusCode >= 300 && $statusCode < 400 && $response['redirectsRemaining'] > 0) {
        $location = is_string($response['response']) ? ri_location_from_header_string($response['response']) : null;
        if ($location !== null) {
            $redirectUrl = ri_resolve_redirect_url($response['url'], $location);
            if ($redirectUrl === null || !ri_is_safe_remote_url($redirectUrl, $config, true)) {
                ri_finish_remote_link_state($state, [
                    'ok' => false,
                    'statusCode' => $statusCode,
                    'error' => 'Redirect target is not allowed.',
                ]);
                return;
            }

            $nextRequests[] = [
                'state' => $response['state'],
                'url' => $redirectUrl,
                'method' => $response['method'],
                'redirectsRemaining' => $response['redirectsRemaining'] - 1,
            ];
            return;
        }
    }

    if ($response['method'] === 'HEAD' && !$response['ok'] && in_array($statusCode, [405, 403, 501], true)) {
        $nextRequests[] = [
            'state' => $response['state'],
            'url' => $state['item']['url'],
            'method' => 'GET',
            'redirectsRemaining' => 5,
        ];
        return;
    }

    ri_finish_remote_link_state($state, $response);
}

function ri_finish_remote_link_state(array &$state, array $response): void
{
    $state['done'] = true;
    $state['checked'] = [
        'ok' => (bool)$response['ok'],
        'statusCode' => $response['statusCode'],
        'error' => $response['error'],
        'durationMs' => (int)round((microtime(true) - $state['startedAt']) * 1000),
    ];
}

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
            $handle = ri_create_remote_curl_handle($request['url'], $config, $request['method']);
            if ($handle === null) {
                $responses[] = array_merge($request, [
                    'ok' => false,
                    'statusCode' => null,
                    'error' => 'Cannot initialize cURL.',
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

function ri_request_remote_url_with_curl(string $url, array $config, string $method, int $redirectsRemaining): array
{
    $startedAt = microtime(true);
    $handle = ri_create_remote_curl_handle($url, $config, $method);
    if ($handle === null) {
        return [
            'ok' => false,
            'statusCode' => null,
            'error' => 'Cannot initialize cURL.',
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

function ri_create_remote_curl_handle(string $url, array $config, string $method)
{
    $handle = curl_init($url);
    if ($handle === false) {
        return null;
    }

    curl_setopt_array($handle, [
        CURLOPT_NOBODY => $method === 'HEAD',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT => $config['linkCheck']['timeoutSeconds'],
        CURLOPT_USERAGENT => $config['linkCheck']['userAgent'],
        CURLOPT_HEADER => true,
        CURLOPT_SSL_VERIFYPEER => $config['linkCheck']['verifyTls'],
        CURLOPT_SSL_VERIFYHOST => $config['linkCheck']['verifyTls'] ? 2 : 0,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
    ]);

    if ($method === 'GET') {
        curl_setopt($handle, CURLOPT_RANGE, '0-0');
    }

    if ($config['linkCheck']['proxy'] !== '') {
        curl_setopt($handle, CURLOPT_PROXY, $config['linkCheck']['proxy']);
    }

    return $handle;
}

function ri_remote_curl_handle_result($handle, mixed $response, float $startedAt): array
{
    $error = curl_error($handle);
    $errno = curl_errno($handle);
    $statusCode = (int)curl_getinfo($handle, CURLINFO_RESPONSE_CODE);

    if ($errno !== 0) {
        return [
            'ok' => false,
            'statusCode' => $statusCode > 0 ? $statusCode : null,
            'error' => $error,
            'durationMs' => (int)round((microtime(true) - $startedAt) * 1000),
            'response' => is_string($response) ? $response : null,
        ];
    }

    return [
        'ok' => $statusCode >= 200 && $statusCode < 400,
        'statusCode' => $statusCode > 0 ? $statusCode : null,
        'error' => null,
        'durationMs' => (int)round((microtime(true) - $startedAt) * 1000),
        'response' => is_string($response) ? $response : null,
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

function ri_is_safe_remote_url(string $url, array $config, bool $resolveDns): bool
{
    if (!ri_is_http_url($url)) {
        return false;
    }

    $host = parse_url($url, PHP_URL_HOST);
    if (!is_string($host) || !ri_is_allowed_remote_host($host, $config['linkCheck']['allowedHosts'])) {
        return false;
    }

    return !ri_is_private_or_local_host($host, $resolveDns);
}

function ri_is_safe_allowed_remote_host(string $host): bool
{
    if (str_starts_with($host, '*.')) {
        $host = substr($host, 2);
    }

    return ri_is_safe_host($host);
}

function ri_is_allowed_remote_host(string $host, array $allowedHosts): bool
{
    if ($allowedHosts === []) {
        return true;
    }

    $host = strtolower(trim($host, '[]'));
    foreach ($allowedHosts as $allowedHost) {
        if (!is_string($allowedHost)) {
            continue;
        }

        $allowedHost = strtolower(trim($allowedHost));
        if (str_starts_with($allowedHost, '*.')) {
            $suffix = substr($allowedHost, 1);
            if (str_ends_with($host, $suffix)) {
                return true;
            }
            continue;
        }

        if ($host === strtolower(trim($allowedHost, '[]'))) {
            return true;
        }
    }

    return false;
}

function ri_is_private_or_local_host(string $host, bool $resolveDns): bool
{
    $host = strtolower(trim($host, '[]'));
    if ($host === 'localhost' || str_ends_with($host, '.localhost') || $host === 'metadata.google.internal') {
        return true;
    }

    if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
        return !ri_is_public_ip($host);
    }

    if ($resolveDns) {
        foreach (ri_resolve_host_addresses($host) as $address) {
            if (!ri_is_public_ip($address)) {
                return true;
            }
        }
    }

    return false;
}

function ri_is_public_ip(string $address): bool
{
    return filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
}

function ri_resolve_host_addresses(string $host): array
{
    $addresses = [];
    $records = @dns_get_record($host, DNS_A + DNS_AAAA);
    if (is_array($records)) {
        foreach ($records as $record) {
            foreach (['ip', 'ipv6'] as $key) {
                if (isset($record[$key]) && is_string($record[$key])) {
                    $addresses[] = $record[$key];
                }
            }
        }
    }

    $legacyAddresses = @gethostbynamel($host);
    if (is_array($legacyAddresses)) {
        $addresses = array_merge($addresses, $legacyAddresses);
    }

    return array_values(array_unique($addresses));
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

function ri_store_remote_link_check(PDO $index, array $result): void
{
    $statement = $index->prepare(
        'INSERT INTO remote_link_checks (folder, image_id, url, ok, status_code, error, duration_ms, checked_at)
         VALUES (:folder, :image_id, :url, :ok, :status_code, :error, :duration_ms, :checked_at)
         ON CONFLICT(folder, image_id) DO UPDATE SET
            url = excluded.url,
            ok = excluded.ok,
            status_code = excluded.status_code,
            error = excluded.error,
            duration_ms = excluded.duration_ms,
            checked_at = excluded.checked_at'
    );
    $statement->execute([
        ':folder' => $result['folder'],
        ':image_id' => $result['id'],
        ':url' => $result['url'],
        ':ok' => $result['ok'] ? 1 : 0,
        ':status_code' => $result['statusCode'],
        ':error' => $result['error'],
        ':duration_ms' => $result['durationMs'],
        ':checked_at' => $result['checkedAt'],
    ]);
}
