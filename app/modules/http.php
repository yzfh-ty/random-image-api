<?php

declare(strict_types=1);

function ri_handle_request(string $baseDir): void
{
    ri_enforce_request_method();

    $config = ri_load_config($baseDir);
    $index = ri_open_image_index($config, $baseDir);
    $path = ri_request_path();

    if ($path === '') {
        ri_handle_random_database_request('/', null, $index, $config, $baseDir);
    }

    $segments = ri_decode_path_segments($path);
    if ($segments === []) {
        ri_send_error(404, 'not_found', 'Route not found.');
    }

    $first = $segments[0];
    if ($first === ltrim($config['adminPrefix'], '/')) {
        ri_handle_admin_request($segments, $config, $index);
    }

    if (ri_is_indexed_asset_request($segments, $config)) {
        ri_handle_indexed_asset_request($segments, $config, $index, $baseDir);
    }

    ri_handle_folder_random_request($segments, $config, $index, $baseDir);
}

function ri_handle_random_database_request(string $scopeKey, ?string $folder, PDO $index, array $config, string $baseDir): void
{
    $source = ri_source_filter();
    $imageType = ri_requested_image_type();
    $item = ri_select_and_remember_indexed_item($index, $config, $baseDir, $scopeKey, $folder, $source, $imageType);
    if ($item === null) {
        ri_send_error(404, 'empty_pool', 'No indexed image found. Run the CLI index command first.');
    }

    ri_respond_random_item($item, $config);
}

function ri_select_and_remember_indexed_item(
    PDO $index,
    array $config,
    string $baseDir,
    string $scopeKey,
    ?string $folder,
    ?string $source,
    ?string $imageType
): ?array {
    ri_start_session($config);
    $_SESSION['last_served'] ??= [];
    $sessionKey = $scopeKey . '|' . ($source ?? 'all') . '|' . ($imageType ?? 'all-types');
    $lastKey = is_string($_SESSION['last_served'][$sessionKey] ?? null) ? $_SESSION['last_served'][$sessionKey] : null;
    $item = $folder === null
        ? ri_random_item_for_all($index, $config, $baseDir, $source, $imageType, $lastKey)
        : ri_random_item_for_folder($index, $config, $baseDir, $folder, $source, $imageType, $lastKey);

    if ($item !== null) {
        $_SESSION['last_served'][$sessionKey] = ri_image_key($item);
    }
    session_write_close();

    return $item;
}

function ri_random_item_for_all(
    PDO $index,
    array $config,
    string $baseDir,
    ?string $source = null,
    ?string $imageType = null,
    ?string $lastKey = null
): ?array
{
    $folders = $config['folders'];
    if ($folders === []) {
        return null;
    }

    $placeholders = ri_sql_placeholders('folder', count($folders));
    $params = array_combine(array_keys($placeholders), $folders);

    return ri_random_item_from_query(
        $index,
        $config,
        $baseDir,
        'image_index AS i',
        'i.folder IN (' . implode(', ', array_keys($placeholders)) . ')',
        $params,
        $source,
        $imageType,
        $lastKey
    );
}

function ri_random_item_for_folder(
    PDO $index,
    array $config,
    string $baseDir,
    string $folder,
    ?string $source = null,
    ?string $imageType = null,
    ?string $lastKey = null
): ?array {
    return ri_random_item_from_query(
        $index,
        $config,
        $baseDir,
        'image_index AS i',
        'i.folder = :folder',
        [':folder' => $folder],
        $source,
        $imageType,
        $lastKey
    );
}

function ri_random_item_from_query(
    PDO $index,
    array $config,
    string $baseDir,
    string $fromSql,
    string $whereSql,
    array $params,
    ?string $source,
    ?string $imageType,
    ?string $lastKey
): ?array {
    $whereParts = [$whereSql];
    if ($source === 'local' || $source === 'remote') {
        $whereParts[] = 'i.source_type = :source';
        $params[':source'] = $source;
    }
    if ($imageType === RI_IMAGE_TYPE_PC || $imageType === RI_IMAGE_TYPE_MOBILE) {
        $whereParts[] = 'i.orientation = :orientation';
        $params[':orientation'] = $imageType;
    }

    $stats = ri_candidate_folder_stats($index, $fromSql, $whereParts, $params);
    $total = ri_total_candidate_count($stats);
    if ($total < 1) {
        return null;
    }

    $candidateWhereParts = $whereParts;
    $candidateParams = $params;
    $last = ri_parse_image_key($lastKey);
    if ($total > 1 && $last !== null) {
        $candidateWhereParts[] = 'NOT (i.folder = :last_folder AND i.id = :last_id)';
        $candidateParams[':last_folder'] = $last['folder'];
        $candidateParams[':last_id'] = $last['id'];
        $candidateStats = ri_candidate_folder_stats($index, $fromSql, $candidateWhereParts, $candidateParams);
        if (ri_total_candidate_count($candidateStats) < 1) {
            $candidateWhereParts = $whereParts;
            $candidateParams = $params;
            $candidateStats = $stats;
        }
    } else {
        $candidateStats = $stats;
    }

    $folderStats = ri_pick_candidate_folder_stats($candidateStats);
    if ($folderStats === null) {
        return null;
    }

    return ri_random_item_from_folder_stats($index, $config, $baseDir, $fromSql, $candidateWhereParts, $candidateParams, $folderStats);
}

function ri_candidate_folder_stats(PDO $index, string $fromSql, array $whereParts, array $params): array
{
    $statement = $index->prepare(
        'SELECT i.folder, COUNT(*) AS total, MIN(i.id) AS min_id, MAX(i.id) AS max_id
         FROM ' . $fromSql . '
         WHERE ' . implode(' AND ', $whereParts) . '
         GROUP BY i.folder
         ORDER BY i.folder'
    );
    ri_bind_sql_params($statement, $params);
    $statement->execute();

    $stats = [];
    foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $total = (int)($row['total'] ?? 0);
        $minId = (int)($row['min_id'] ?? 0);
        $maxId = (int)($row['max_id'] ?? 0);
        if ($total < 1 || $minId < 1 || $maxId < $minId) {
            continue;
        }

        $stats[] = [
            'folder' => (string)$row['folder'],
            'total' => $total,
            'minId' => $minId,
            'maxId' => $maxId,
        ];
    }

    return $stats;
}

function ri_total_candidate_count(array $stats): int
{
    $total = 0;
    foreach ($stats as $row) {
        $total += (int)$row['total'];
    }

    return $total;
}

function ri_pick_candidate_folder_stats(array $stats): ?array
{
    $total = ri_total_candidate_count($stats);
    if ($total < 1) {
        return null;
    }

    $pick = random_int(1, $total);
    foreach ($stats as $row) {
        $pick -= (int)$row['total'];
        if ($pick <= 0) {
            return $row;
        }
    }

    return $stats[array_key_last($stats)] ?? null;
}

function ri_random_item_from_folder_stats(
    PDO $index,
    array $config,
    string $baseDir,
    string $fromSql,
    array $whereParts,
    array $params,
    array $folderStats
): ?array {
    $candidateId = random_int((int)$folderStats['minId'], (int)$folderStats['maxId']);
    $whereParts[] = 'i.folder = :candidate_folder';
    $params[':candidate_folder'] = $folderStats['folder'];
    $params[':candidate_id'] = $candidateId;

    $row = ri_fetch_random_item_near_id($index, $fromSql, $whereParts, $params, '>=');
    if (!is_array($row)) {
        $row = ri_fetch_random_item_near_id($index, $fromSql, $whereParts, $params, '<');
    }

    if (!is_array($row)) {
        return null;
    }

    return ri_row_to_item($row, $config, $baseDir);
}

function ri_fetch_random_item_near_id(
    PDO $index,
    string $fromSql,
    array $whereParts,
    array $params,
    string $operator
): ?array {
    $whereParts[] = 'i.id ' . $operator . ' :candidate_id';
    $statement = $index->prepare(
        'SELECT i.folder, i.index_key, i.source_type, i.target, i.extension, i.orientation, i.id
         FROM ' . $fromSql . '
         WHERE ' . implode(' AND ', $whereParts) . '
         ORDER BY i.id
         LIMIT 1'
    );
    ri_bind_sql_params($statement, $params);
    $statement->execute();

    $row = $statement->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row)) {
        return null;
    }

    return $row;
}

function ri_bind_sql_params(PDOStatement $statement, array $params): void
{
    foreach ($params as $key => $value) {
        $statement->bindValue($key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
    }
}

function ri_parse_image_key(?string $key): ?array
{
    if ($key === null) {
        return null;
    }

    $separator = strrpos($key, ':');
    if ($separator === false) {
        return null;
    }

    $folder = substr($key, 0, $separator);
    $id = substr($key, $separator + 1);
    if ($folder === '' || !ctype_digit($id)) {
        return null;
    }

    return [
        'folder' => $folder,
        'id' => (int)$id,
    ];
}

function ri_respond_random_item(array $item, array $config): void
{
    $url = ri_public_url($item, $config);

    if (($config['defaultMode'] === 'json') || (($_GET['json'] ?? '') === '1')) {
        ri_send_json(200, [
            'folder' => $item['folder'],
            'id' => $item['id'],
            'url' => $url,
            'sourceType' => $item['sourceType'],
            'type' => $item['orientation'],
        ]);
    }

    if (ri_is_browser_request()) {
        ri_render_image_page($url);
    }

    ri_no_store_headers();
    header('Location: ' . $url, true, 302);
    exit;
}

function ri_handle_folder_random_request(array $segments, array $config, PDO $index, string $baseDir): void
{
    if (count($segments) !== 1) {
        ri_send_error(404, 'not_found', 'Route not found.');
    }

    $folder = $segments[0] ?? '';
    if (in_array($folder, RI_RESERVED_FOLDERS, true) || !in_array($folder, $config['folders'], true)) {
        ri_send_error(404, 'folder_not_found', 'Image folder is not configured.');
    }

    ri_handle_random_database_request($folder, $folder, $index, $config, $baseDir);
}

function ri_is_indexed_asset_request(array $segments, array $config): bool
{
    if (count($segments) !== 2) {
        return false;
    }

    if (!in_array($segments[0], $config['folders'], true)) {
        return false;
    }

    return preg_match('/^[1-9][0-9]*\.[A-Za-z0-9]+$/', $segments[1]) === 1;
}

function ri_handle_indexed_asset_request(array $segments, array $config, PDO $index, string $baseDir): void
{
    $folder = $segments[0];
    [$id, $extension] = ri_parse_indexed_asset_name($segments[1]);
    $item = ri_find_item_by_index($index, $config, $baseDir, $folder, $id, $extension);
    if ($item === null) {
        ri_send_error(404, 'not_found', 'Image not found.');
    }

    ri_output_indexed_item($item, $config);
}

function ri_parse_indexed_asset_name(string $name): array
{
    if (preg_match('/^([1-9][0-9]*)\.([A-Za-z0-9]+)$/', $name, $matches) !== 1) {
        ri_send_error(404, 'not_found', 'Image not found.');
    }

    return [(int)$matches[1], strtolower($matches[2])];
}

function ri_output_indexed_item(array $item, array $config): void
{
    if ($item['sourceType'] === 'local') {
        ri_output_local_image($item, $config);
    }

    if ($item['sourceType'] === 'remote') {
        ri_security_headers();
        header('Cache-Control: public, max-age=86400');
        header('Location: ' . $item['originalUrl'], true, 302);
        exit;
    }

    ri_send_error(404, 'not_found', 'Image not found.');
}

function ri_handle_admin_request(array $segments, array $config, PDO $index): void
{
    if (!$config['adminEnabled']) {
        ri_send_error(404, 'not_found', 'Admin route not found.');
    }

    ri_require_admin_token($config);

    $resource = $segments[1] ?? '';
    if ($resource === 'index' && count($segments) === 2) {
        ri_send_json(200, ri_index_status($index, $config));
    }

    if ($resource !== 'folders') {
        ri_send_error(404, 'not_found', 'Admin route not found.');
    }

    if (count($segments) === 2) {
        ri_send_json(200, [
            'folders' => ri_all_folder_summaries($index, $config),
            'index' => ri_index_status($index, $config),
        ]);
    }

    $folder = $segments[2] ?? '';
    if (!in_array($folder, $config['folders'], true)) {
        ri_send_error(404, 'folder_not_found', 'Image folder is not configured.');
    }

    ri_send_json(200, ri_folder_summary_from_index($index, $folder));
}

function ri_require_admin_token(array $config): void
{
    $expected = (string)$config['adminToken'];
    $provided = ri_bearer_token();
    if ($provided === null && $config['adminAllowQueryToken']) {
        $provided = is_string($_GET['token'] ?? null) ? (string)$_GET['token'] : null;
    }

    if ($provided === null || !hash_equals($expected, $provided)) {
        ri_send_error(401, 'unauthorized', 'Admin token is required.');
    }
}

function ri_bearer_token(): ?string
{
    $authorization = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? null;
    if (!is_string($authorization)) {
        return null;
    }

    if (preg_match('/^Bearer\s+(.+)$/i', trim($authorization), $matches) !== 1) {
        return null;
    }

    return $matches[1];
}
