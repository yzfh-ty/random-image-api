<?php

declare(strict_types=1);

const RI_RESERVED_FOLDERS = ['_api', '_assets', '_remote'];
const RI_DEFAULT_IMAGE_EXTENSIONS = ['.jpg', '.jpeg', '.png', '.gif', '.webp', '.avif', '.bmp', '.svg'];
const RI_DEFAULT_REMOTE_EXTENSION = 'jpg';

function ri_handle_request(string $baseDir): void
{
    $config = ri_load_config($baseDir);
    $index = ri_open_image_index($config, $baseDir);
    $path = ri_request_path();

    if ($path === '') {
        ri_handle_random_request('/', ri_items_for_all($index, $config, $baseDir), $config);
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

    ri_handle_path_random_request($segments, $config, $index, $baseDir);
}

function ri_cli_main(string $baseDir, array $argv): int
{
    $command = $argv[1] ?? 'help';

    try {
        if ($command === 'index') {
            $stats = ri_build_index($baseDir);
            ri_cli_print_index_summary($stats);
            return 0;
        }

        if ($command === 'status') {
            $config = ri_load_config($baseDir);
            $index = ri_open_image_index($config, $baseDir);
            ri_cli_print_status(ri_index_status($index, $config));
            return 0;
        }

        if ($command === 'help' || $command === '--help' || $command === '-h') {
            ri_cli_print_usage($argv[0] ?? 'cli.php');
            return 0;
        }

        fwrite(STDERR, "Unknown command: {$command}\n\n");
        ri_cli_print_usage($argv[0] ?? 'cli.php');
        return 1;
    } catch (Throwable $error) {
        fwrite(STDERR, 'Error: ' . $error->getMessage() . "\n");
        return 1;
    }
}

function ri_cli_print_usage(string $script): void
{
    $script = basename($script);
    echo "Usage:\n";
    echo "  php {$script} index   Rebuild the SQLite image index.\n";
    echo "  php {$script} status  Show index status and folder counts.\n";
}

function ri_cli_print_index_summary(array $stats): void
{
    echo "Index rebuilt successfully.\n";
    echo 'Started at: ' . $stats['startedAt'] . "\n";
    echo 'Finished at: ' . $stats['finishedAt'] . "\n";
    echo 'Total: ' . $stats['total'] . ' images, local: ' . $stats['localCount'] . ', remote: ' . $stats['remoteCount'] . "\n";
    echo 'Paths: ' . $stats['pathCount'] . "\n";

    foreach ($stats['folders'] as $folder) {
        echo '- ' . $folder['folder'] . ': ' . $folder['total'] . ' total, local ' . $folder['localCount'] . ', remote ' . $folder['remoteCount'] . "\n";
    }

    if ($stats['warnings'] !== []) {
        echo "Warnings:\n";
        foreach ($stats['warnings'] as $warning) {
            echo '- ' . $warning . "\n";
        }
    }
}

function ri_cli_print_status(array $status): void
{
    echo 'Database: ' . $status['database'] . "\n";
    echo 'Last indexed at: ' . ($status['lastIndexedAt'] ?? 'never') . "\n";
    echo 'Total: ' . $status['total'] . ' images, local: ' . $status['localCount'] . ', remote: ' . $status['remoteCount'] . "\n";
    echo 'Paths: ' . $status['pathCount'] . "\n";

    foreach ($status['folders'] as $folder) {
        echo '- ' . $folder['folder'] . ': ' . $folder['total'] . ' total, local ' . $folder['localCount'] . ', remote ' . $folder['remoteCount'] . "\n";
    }
}

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
            'trustProxy' => (bool)($raw['server']['trustProxy'] ?? true),
        ],
        'imageRoot' => (string)($raw['imageRoot'] ?? 'images'),
        'folders' => array_values($raw['folders'] ?? []),
        'linkFiles' => array_values($raw['linkFiles'] ?? ['links.txt']),
        'adminPrefix' => (string)($raw['adminPrefix'] ?? '/_api'),
        'indexDatabase' => (string)($raw['indexDatabase'] ?? '.runtime/image-index.sqlite'),
        'imageExtensions' => array_values($raw['imageExtensions'] ?? RI_DEFAULT_IMAGE_EXTENSIONS),
        'defaultMode' => (string)($raw['defaultMode'] ?? 'redirect'),
    ];

    if ($config['folders'] === []) {
        ri_send_error(500, 'invalid_config', 'At least one folder must be configured.');
    }

    if (!preg_match('/^\/[A-Za-z0-9_-]+$/', $config['adminPrefix'])) {
        ri_send_error(500, 'invalid_config', 'Invalid route prefix: adminPrefix.');
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

        $extensions[] = strtolower($extension);
    }
    $config['imageExtensions'] = array_values(array_unique($extensions));

    if (!in_array($config['defaultMode'], ['redirect', 'json'], true)) {
        ri_send_error(500, 'invalid_config', 'defaultMode must be redirect or json.');
    }

    return $config;
}

function ri_build_index(string $baseDir): array
{
    $config = ri_load_config($baseDir);
    $index = ri_open_image_index($config, $baseDir);
    $imageRoot = ri_resolve_path($baseDir, $config['imageRoot']);
    $startedAt = gmdate('c');
    $warnings = [];

    $index->beginTransaction();
    try {
        ri_prepare_index_rebuild($index);

        foreach ($config['folders'] as $folder) {
            $folderPath = ri_resolve_path($imageRoot, $folder);

            if (!is_dir($folderPath)) {
                $warnings[] = 'Configured folder does not exist: ' . $folder;
                continue;
            }

            if (!ri_is_inside($imageRoot, $folderPath)) {
                $warnings[] = 'Skipped unsafe folder path: ' . $folder;
                continue;
            }

            ri_scan_directory_for_index($config, $folder, $folderPath, $folderPath, $index, $warnings);
        }

        ri_finalize_index_rebuild($index);
        $finishedAt = gmdate('c');
        ri_set_index_meta($index, 'last_indexed_at', $finishedAt);
        $stats = ri_index_status($index, $config);
        $stats['startedAt'] = $startedAt;
        $stats['finishedAt'] = $finishedAt;
        $stats['warnings'] = $warnings;
        ri_set_index_meta($index, 'last_indexed_summary', json_encode($stats, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        $index->commit();
        return $stats;
    } catch (Throwable $error) {
        if ($index->inTransaction()) {
            $index->rollBack();
        }

        throw $error;
    }
}

function ri_prepare_index_rebuild(PDO $index): void
{
    $index->exec('DROP TABLE IF EXISTS current_images');
    $index->exec(
        'CREATE TEMP TABLE current_images (
            folder TEXT NOT NULL,
            index_key TEXT NOT NULL,
            PRIMARY KEY (folder, index_key)
        )'
    );
    $index->exec('DELETE FROM image_paths');
}

function ri_finalize_index_rebuild(PDO $index): void
{
    $index->exec(
        'DELETE FROM image_index
         WHERE NOT EXISTS (
             SELECT 1
             FROM current_images
             WHERE current_images.folder = image_index.folder
               AND current_images.index_key = image_index.index_key
         )'
    );
    $index->exec('DROP TABLE IF EXISTS current_images');
}

function ri_scan_directory_for_index(
    array $config,
    string $folder,
    string $folderPath,
    string $currentPath,
    PDO $index,
    array &$warnings
): void {
    $entries = scandir($currentPath);
    if ($entries === false) {
        $warnings[] = 'Cannot read directory: ' . ri_to_url_path(ri_relative_path($folderPath, $currentPath));
        return;
    }

    foreach ($entries as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }

        $entryPath = $currentPath . DIRECTORY_SEPARATOR . $entry;
        if (is_dir($entryPath)) {
            ri_scan_directory_for_index($config, $folder, $folderPath, $entryPath, $index, $warnings);
            continue;
        }

        if (!is_file($entryPath) || !ri_is_inside($folderPath, $entryPath)) {
            continue;
        }

        if (in_array($entry, $config['linkFiles'], true)) {
            $directoryRelativePath = ri_to_url_path(ri_relative_path($folderPath, $currentPath));
            ri_index_remote_link_file($config, $folder, $entryPath, $directoryRelativePath, $index);
        }

        $extension = strtolower('.' . pathinfo($entryPath, PATHINFO_EXTENSION));
        if (!in_array($extension, $config['imageExtensions'], true)) {
            continue;
        }

        $relativePath = ri_to_url_path(ri_relative_path($folderPath, $entryPath));
        $extensionWithoutDot = strtolower(pathinfo($entryPath, PATHINFO_EXTENSION));
        $id = ri_remember_index_image($index, $folder, 'local', $relativePath, $extensionWithoutDot, $relativePath);
        $directoryRelativePath = dirname($relativePath) === '.' ? '' : dirname($relativePath);
        ri_add_index_paths($index, $folder, $directoryRelativePath, $id);
    }
}

function ri_index_remote_link_file(array $config, string $folder, string $filePath, string $directoryRelativePath, PDO $index): void
{
    $seen = [];
    $lines = file($filePath, FILE_IGNORE_NEW_LINES);
    if ($lines === false) {
        return;
    }

    foreach ($lines as $line) {
        $url = trim($line);
        if ($url === '' || str_starts_with($url, '#') || isset($seen[$url]) || !ri_is_http_url($url)) {
            continue;
        }

        $seen[$url] = true;
        $extension = ri_extension_from_url($url, $config);
        $id = ri_remember_index_image($index, $folder, 'remote', $url, $extension, $url);
        ri_add_index_paths($index, $folder, $directoryRelativePath, $id);
    }
}

function ri_remember_index_image(PDO $index, string $folder, string $sourceType, string $target, string $extension, string $stableKey): int
{
    $id = ri_index_image($index, $folder, $sourceType, $target, $extension, $stableKey);
    $insertCurrent = $index->prepare(
        'INSERT OR IGNORE INTO current_images (folder, index_key)
         VALUES (:folder, :index_key)'
    );
    $insertCurrent->execute([
        ':folder' => $folder,
        ':index_key' => $sourceType . ':' . $stableKey,
    ]);

    return $id;
}

function ri_add_index_paths(PDO $index, string $folder, string $directoryRelativePath, int $imageId): void
{
    $insert = $index->prepare(
        'INSERT OR IGNORE INTO image_paths (path, folder, image_id)
         VALUES (:path, :folder, :image_id)'
    );

    foreach (ri_ancestor_paths($folder, $directoryRelativePath) as $path) {
        $insert->execute([
            ':path' => $path,
            ':folder' => $folder,
            ':image_id' => $imageId,
        ]);
    }
}

function ri_ancestor_paths(string $folder, string $directoryRelativePath): array
{
    $directoryRelativePath = trim($directoryRelativePath, '/');
    $paths = [$folder];

    if ($directoryRelativePath === '') {
        return $paths;
    }

    $segments = explode('/', $directoryRelativePath);
    for ($index = 0; $index < count($segments); $index++) {
        $paths[] = $folder . '/' . implode('/', array_slice($segments, 0, $index + 1));
    }

    return $paths;
}

function ri_open_image_index(array $config, string $baseDir): PDO
{
    if (!class_exists(PDO::class) || !in_array('sqlite', PDO::getAvailableDrivers(), true)) {
        ri_send_error(500, 'sqlite_unavailable', 'PDO SQLite extension is not available.');
    }

    $databasePath = ri_resolve_path($baseDir, $config['indexDatabase']);
    $databaseDir = dirname($databasePath);
    if (!is_dir($databaseDir) && !mkdir($databaseDir, 0777, true) && !is_dir($databaseDir)) {
        ri_send_error(500, 'index_unavailable', 'Cannot create SQLite index directory.');
    }

    $pdo = new PDO('sqlite:' . $databasePath);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('PRAGMA busy_timeout = 5000');
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS image_index (
            folder TEXT NOT NULL,
            index_key TEXT NOT NULL,
            source_type TEXT NOT NULL,
            target TEXT NOT NULL,
            extension TEXT NOT NULL,
            id INTEGER NOT NULL,
            PRIMARY KEY (folder, index_key),
            UNIQUE (folder, id)
        )'
    );
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS image_paths (
            path TEXT NOT NULL,
            folder TEXT NOT NULL,
            image_id INTEGER NOT NULL,
            PRIMARY KEY (path, folder, image_id)
        )'
    );
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_image_paths_path ON image_paths (path)');
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS image_sequences (
            folder TEXT PRIMARY KEY,
            next_id INTEGER NOT NULL
        )'
    );
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS index_meta (
            key TEXT PRIMARY KEY,
            value TEXT NOT NULL
        )'
    );

    return $pdo;
}

function ri_index_image(PDO $index, string $folder, string $sourceType, string $target, string $extension, string $stableKey): int
{
    $indexKey = $sourceType . ':' . $stableKey;
    $select = $index->prepare('SELECT id FROM image_index WHERE folder = :folder AND index_key = :index_key');
    $select->execute([
        ':folder' => $folder,
        ':index_key' => $indexKey,
    ]);

    $existingId = $select->fetchColumn();
    if ($existingId !== false) {
        $update = $index->prepare(
            'UPDATE image_index
             SET source_type = :source_type, target = :target, extension = :extension
             WHERE folder = :folder AND index_key = :index_key'
        );
        $update->execute([
            ':source_type' => $sourceType,
            ':target' => $target,
            ':extension' => $extension,
            ':folder' => $folder,
            ':index_key' => $indexKey,
        ]);
        return (int)$existingId;
    }

    $id = ri_next_image_id($index, $folder);
    $insert = $index->prepare(
        'INSERT INTO image_index (folder, index_key, source_type, target, extension, id)
         VALUES (:folder, :index_key, :source_type, :target, :extension, :id)'
    );
    $insert->execute([
        ':folder' => $folder,
        ':index_key' => $indexKey,
        ':source_type' => $sourceType,
        ':target' => $target,
        ':extension' => $extension,
        ':id' => $id,
    ]);

    return $id;
}

function ri_next_image_id(PDO $index, string $folder): int
{
    $select = $index->prepare('SELECT next_id FROM image_sequences WHERE folder = :folder');
    $select->execute([':folder' => $folder]);
    $next = $select->fetchColumn();

    if ($next === false) {
        $insert = $index->prepare('INSERT INTO image_sequences (folder, next_id) VALUES (:folder, :next_id)');
        $insert->execute([
            ':folder' => $folder,
            ':next_id' => 2,
        ]);
        return 1;
    }

    $id = (int)$next;
    $update = $index->prepare('UPDATE image_sequences SET next_id = :next_id WHERE folder = :folder');
    $update->execute([
        ':folder' => $folder,
        ':next_id' => $id + 1,
    ]);

    return $id;
}

function ri_set_index_meta(PDO $index, string $key, string $value): void
{
    $statement = $index->prepare(
        'INSERT INTO index_meta (key, value)
         VALUES (:key, :value)
         ON CONFLICT(key) DO UPDATE SET value = excluded.value'
    );
    $statement->execute([
        ':key' => $key,
        ':value' => $value,
    ]);
}

function ri_get_index_meta(PDO $index, string $key): ?string
{
    $statement = $index->prepare('SELECT value FROM index_meta WHERE key = :key');
    $statement->execute([':key' => $key]);
    $value = $statement->fetchColumn();

    return $value === false ? null : (string)$value;
}

function ri_items_for_all(PDO $index, array $config, string $baseDir): array
{
    $folders = $config['folders'];
    if ($folders === []) {
        return [];
    }

    $placeholders = ri_sql_placeholders('folder', count($folders));
    $statement = $index->prepare(
        'SELECT folder, index_key, source_type, target, extension, id
         FROM image_index
         WHERE folder IN (' . implode(', ', array_keys($placeholders)) . ')
         ORDER BY folder, id'
    );
    $statement->execute(array_combine(array_keys($placeholders), $folders));

    return ri_rows_to_items($statement->fetchAll(PDO::FETCH_ASSOC), $config, $baseDir);
}

function ri_items_for_path(PDO $index, array $config, string $baseDir, string $scopePath): array
{
    $statement = $index->prepare(
        'SELECT i.folder, i.index_key, i.source_type, i.target, i.extension, i.id
         FROM image_paths AS p
         INNER JOIN image_index AS i
             ON i.folder = p.folder
            AND i.id = p.image_id
         WHERE p.path = :path
         ORDER BY i.folder, i.id'
    );
    $statement->execute([':path' => $scopePath]);

    return ri_rows_to_items($statement->fetchAll(PDO::FETCH_ASSOC), $config, $baseDir);
}

function ri_find_item_by_index(PDO $index, array $config, string $baseDir, string $folder, int $id, string $extension): ?array
{
    $statement = $index->prepare(
        'SELECT folder, index_key, source_type, target, extension, id
         FROM image_index
         WHERE folder = :folder
           AND id = :id
           AND extension = :extension'
    );
    $statement->execute([
        ':folder' => $folder,
        ':id' => $id,
        ':extension' => $extension,
    ]);

    $row = $statement->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row)) {
        return null;
    }

    return ri_row_to_item($row, $config, $baseDir);
}

function ri_rows_to_items(array $rows, array $config, string $baseDir): array
{
    $items = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }

        $item = ri_row_to_item($row, $config, $baseDir);
        if ($item !== null) {
            $items[] = $item;
        }
    }

    return $items;
}

function ri_row_to_item(array $row, array $config, string $baseDir): ?array
{
    $folder = (string)$row['folder'];
    if (!in_array($folder, $config['folders'], true)) {
        return null;
    }

    $sourceType = (string)$row['source_type'];
    $target = (string)$row['target'];
    $extension = strtolower((string)$row['extension']);
    $id = (int)$row['id'];

    $item = [
        'sourceType' => $sourceType,
        'folder' => $folder,
        'id' => $id,
        'indexKey' => (string)$row['index_key'],
        'extension' => $extension,
    ];

    if ($sourceType === 'local') {
        if (!ri_is_safe_relative_path($target)) {
            return null;
        }

        $imageRoot = ri_resolve_path($baseDir, $config['imageRoot']);
        $folderPath = ri_resolve_path($imageRoot, $folder);
        $absolutePath = ri_resolve_path($folderPath, $target);
        $item['relativePath'] = $target;
        $item['filename'] = basename($target);
        $item['absolutePath'] = $absolutePath;
        return $item;
    }

    if ($sourceType === 'remote' && ri_is_http_url($target)) {
        $item['originalUrl'] = $target;
        return $item;
    }

    return null;
}

function ri_handle_random_request(string $scopePath, array $items, array $config): void
{
    $source = ri_source_filter();
    $items = ri_filter_items($items, $source);
    if ($items === []) {
        ri_send_error(404, 'empty_pool', 'No indexed image found. Run the CLI index command first.');
    }

    $item = ri_select_and_remember_item($items, $scopePath, $source);
    $url = ri_public_url($item, $config);

    if (($config['defaultMode'] === 'json') || (($_GET['json'] ?? '') === '1')) {
        ri_send_json(200, [
            'folder' => $item['folder'],
            'id' => $item['id'],
            'url' => $url,
            'sourceType' => $item['sourceType'],
        ]);
    }

    if (ri_is_browser_request()) {
        ri_render_image_page($url);
    }

    ri_no_store_headers();
    header('Location: ' . $url, true, 302);
    exit;
}

function ri_handle_path_random_request(array $segments, array $config, PDO $index, string $baseDir): void
{
    $folder = $segments[0] ?? '';
    if (in_array($folder, RI_RESERVED_FOLDERS, true) || !in_array($folder, $config['folders'], true)) {
        ri_send_error(404, 'folder_not_found', 'Image folder is not configured.');
    }

    $scopePath = implode('/', $segments);
    ri_handle_random_request($scopePath, ri_items_for_path($index, $config, $baseDir, $scopePath), $config);
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

    ri_output_indexed_item($item);
}

function ri_parse_indexed_asset_name(string $name): array
{
    if (preg_match('/^([1-9][0-9]*)\.([A-Za-z0-9]+)$/', $name, $matches) !== 1) {
        ri_send_error(404, 'not_found', 'Image not found.');
    }

    return [(int)$matches[1], strtolower($matches[2])];
}

function ri_output_indexed_item(array $item): void
{
    if ($item['sourceType'] === 'local') {
        ri_output_local_image($item);
    }

    if ($item['sourceType'] === 'remote') {
        header('Cache-Control: public, max-age=86400');
        header('Location: ' . $item['originalUrl'], true, 302);
        exit;
    }

    ri_send_error(404, 'not_found', 'Image not found.');
}

function ri_handle_admin_request(array $segments, array $config, PDO $index): void
{
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

function ri_index_status(PDO $index, array $config): array
{
    $folders = ri_all_folder_summaries($index, $config);
    $total = 0;
    $localCount = 0;
    $remoteCount = 0;
    foreach ($folders as $folder) {
        $total += $folder['total'];
        $localCount += $folder['localCount'];
        $remoteCount += $folder['remoteCount'];
    }

    $pathCount = (int)$index->query('SELECT COUNT(*) FROM image_paths')->fetchColumn();

    return [
        'database' => $config['indexDatabase'],
        'lastIndexedAt' => ri_get_index_meta($index, 'last_indexed_at'),
        'total' => $total,
        'localCount' => $localCount,
        'remoteCount' => $remoteCount,
        'pathCount' => $pathCount,
        'folders' => $folders,
    ];
}

function ri_all_folder_summaries(PDO $index, array $config): array
{
    $folders = [];
    foreach ($config['folders'] as $folder) {
        $folders[] = ri_folder_summary_from_index($index, $folder);
    }

    return $folders;
}

function ri_folder_summary_from_index(PDO $index, string $folder): array
{
    $statement = $index->prepare(
        'SELECT
            COUNT(*) AS total,
            SUM(CASE WHEN source_type = "local" THEN 1 ELSE 0 END) AS local_count,
            SUM(CASE WHEN source_type = "remote" THEN 1 ELSE 0 END) AS remote_count
         FROM image_index
         WHERE folder = :folder'
    );
    $statement->execute([':folder' => $folder]);
    $row = $statement->fetch(PDO::FETCH_ASSOC) ?: [];

    return [
        'folder' => $folder,
        'total' => (int)($row['total'] ?? 0),
        'localCount' => (int)($row['local_count'] ?? 0),
        'remoteCount' => (int)($row['remote_count'] ?? 0),
    ];
}

function ri_select_and_remember_item(array $items, string $scopePath, ?string $source): array
{
    ri_start_session();
    $_SESSION['last_served'] ??= [];
    $scopeKey = $scopePath . '|' . ($source ?? 'all');
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

function ri_filter_items(array $items, ?string $source): array
{
    if ($source !== 'local' && $source !== 'remote') {
        return array_values($items);
    }

    return array_values(array_filter($items, static fn(array $item): bool => $item['sourceType'] === $source));
}

function ri_source_filter(): ?string
{
    $source = $_GET['source'] ?? null;
    return ($source === 'local' || $source === 'remote') ? $source : null;
}

function ri_output_local_image(array $item): void
{
    $path = $item['absolutePath'];
    if (!is_file($path) || !is_readable($path)) {
        ri_send_error(404, 'not_found', 'Image not found.');
    }

    $mtime = filemtime($path);
    $size = filesize($path);
    if ($mtime === false || $size === false) {
        ri_send_error(404, 'not_found', 'Image not found.');
    }

    $etag = '"' . md5($path . '|' . $size . '|' . $mtime) . '"';
    header('Content-Type: ' . ri_mime_type($path));
    header('Content-Length: ' . (string)$size);
    header('Cache-Control: public, max-age=86400');
    header('ETag: ' . $etag);
    header('Last-Modified: ' . gmdate('D, d M Y H:i:s', (int)$mtime) . ' GMT');

    if (($_SERVER['HTTP_IF_NONE_MATCH'] ?? '') === $etag) {
        http_response_code(304);
        exit;
    }

    readfile($path);
    exit;
}

function ri_public_url(array $item, array $config): string
{
    return ri_request_origin($config) . '/' . rawurlencode($item['folder']) . '/' . $item['id'] . '.' . rawurlencode($item['extension']);
}

function ri_request_origin(array $config): string
{
    $trustProxy = (bool)($config['server']['trustProxy'] ?? true);
    $proto = $trustProxy ? ri_first_header('HTTP_X_FORWARDED_PROTO') : null;
    $host = $trustProxy ? ri_first_header('HTTP_X_FORWARDED_HOST') : null;

    if ($proto === null) {
        $proto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    }

    if ($host === null) {
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    }

    return $proto . '://' . $host;
}

function ri_first_header(string $key): ?string
{
    $value = $_SERVER[$key] ?? null;
    if (!is_string($value) || trim($value) === '') {
        return null;
    }

    return trim(explode(',', $value)[0]);
}

function ri_send_json(int $statusCode, array $payload): void
{
    http_response_code($statusCode);
    ri_no_store_headers();
    header('Content-Type: application/json; charset=UTF-8');
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
    header('Content-Type: text/html; charset=UTF-8');

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
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
}

function ri_start_session(): void
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start([
            'cookie_httponly' => true,
            'cookie_samesite' => 'Lax',
            'use_strict_mode' => true,
        ]);
    }
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
    $scheme = parse_url($value, PHP_URL_SCHEME);
    $host = parse_url($value, PHP_URL_HOST);
    return ($scheme === 'http' || $scheme === 'https') && is_string($host) && $host !== '';
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
