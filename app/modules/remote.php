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
        if (ri_should_retry_remote_head_with_get($method, $result)) {
            continue;
        }
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

    if (($response['error'] ?? null) === null) {
        $contentTypeError = ri_remote_image_content_type_error($response['contentType'] ?? null);
        if ($contentTypeError !== null) {
            $response['ok'] = false;
            $response['error'] = $contentTypeError;
        }
    }

    if (ri_should_retry_remote_head_with_get($response['method'], $response)) {
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

function ri_should_retry_remote_head_with_get(string $method, array $result): bool
{
    if ($method !== 'HEAD' || (bool)($result['ok'] ?? false)) {
        return false;
    }

    $statusCode = $result['statusCode'] ?? null;
    if (in_array($statusCode, [405, 403, 501], true)) {
        return true;
    }

    return ($result['error'] ?? null) === 'Remote response did not include an image Content-Type.'
        && is_int($statusCode)
        && $statusCode >= 200
        && $statusCode < 400;
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
