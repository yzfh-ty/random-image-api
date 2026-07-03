<?php

declare(strict_types=1);

function ri_build_index(string $baseDir, ?string $onlyFolder = null): array
{
    $config = ri_load_config($baseDir);
    $folders = ri_index_target_folders($config, $onlyFolder);
    $fullRebuild = $onlyFolder === null;

    return ri_with_index_lock($config, $baseDir, static function () use ($baseDir, $config, $folders, $fullRebuild): array {
        $index = ri_open_image_index($config, $baseDir);
        $imageRoot = ri_resolve_path($baseDir, $config['imageRoot']);
        $startedAt = gmdate('c');
        $startedAtFloat = microtime(true);
        $warnings = [];

        ri_append_index_log($config, $baseDir, 'index_started', ['folders' => $folders]);
        $index->beginTransaction();
        try {
            if ($fullRebuild) {
                ri_remove_unconfigured_index_rows($index, $config['folders']);
            }

            ri_prepare_index_rebuild($index, $folders);

            foreach ($folders as $folder) {
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

            ri_finalize_index_rebuild($index, $folders);
            $finishedAt = gmdate('c');
            $durationMs = (int)round((microtime(true) - $startedAtFloat) * 1000);
            ri_set_index_meta($index, 'last_indexed_at', $finishedAt);
            ri_set_index_meta($index, 'last_indexed_duration_ms', (string)$durationMs);
            ri_set_index_meta($index, 'last_indexed_warning_count', (string)count($warnings));
            ri_set_index_meta($index, 'last_indexed_error', '');
            $stats = ri_index_status($index, $config);
            $stats['startedAt'] = $startedAt;
            $stats['finishedAt'] = $finishedAt;
            $stats['durationMs'] = $durationMs;
            $stats['indexedFolders'] = $folders;
            $stats['warnings'] = $warnings;
            ri_set_index_meta($index, 'last_indexed_summary', json_encode($stats, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            $index->commit();
            ri_append_index_log($config, $baseDir, 'index_finished', [
                'folders' => $folders,
                'durationMs' => $durationMs,
                'warnings' => count($warnings),
            ]);
            return $stats;
        } catch (Throwable $error) {
            if ($index->inTransaction()) {
                $index->rollBack();
            }

            ri_set_index_meta($index, 'last_indexed_error', $error->getMessage());
            ri_append_index_log($config, $baseDir, 'index_failed', [
                'folders' => $folders,
                'error' => $error->getMessage(),
            ]);
            throw $error;
        }
    });
}
function ri_index_target_folders(array $config, ?string $onlyFolder): array
{
    if ($onlyFolder === null) {
        return $config['folders'];
    }

    if (!in_array($onlyFolder, $config['folders'], true)) {
        throw new RuntimeException('Folder is not configured: ' . $onlyFolder);
    }

    return [$onlyFolder];
}

function ri_remove_unconfigured_index_rows(PDO $index, array $configuredFolders): void
{
    $placeholders = ri_sql_placeholders('folder', count($configuredFolders));
    $params = array_combine(array_keys($placeholders), $configuredFolders);
    $filter = implode(', ', array_keys($placeholders));

    foreach (['image_paths', 'image_index', 'image_sequences'] as $table) {
        $delete = $index->prepare("DELETE FROM {$table} WHERE folder NOT IN ({$filter})");
        $delete->execute($params);
    }

    $index->exec(
        'DELETE FROM remote_link_checks
         WHERE NOT EXISTS (
             SELECT 1
             FROM image_index
             WHERE image_index.folder = remote_link_checks.folder
               AND image_index.id = remote_link_checks.image_id
         )'
    );
}

function ri_with_index_lock(array $config, string $baseDir, callable $callback): array
{
    $lockPath = ri_resolve_path($baseDir, $config['indexLock']);
    $lockDir = dirname($lockPath);
    if (!is_dir($lockDir) && !mkdir($lockDir, 0777, true) && !is_dir($lockDir)) {
        throw new RuntimeException('Cannot create index lock directory.');
    }

    $handle = fopen($lockPath, 'c');
    if ($handle === false) {
        throw new RuntimeException('Cannot open index lock file.');
    }

    if (!flock($handle, LOCK_EX | LOCK_NB)) {
        fclose($handle);
        throw new RuntimeException('Index is already running.');
    }

    try {
        ftruncate($handle, 0);
        fwrite($handle, (string)getmypid() . "\n" . gmdate('c') . "\n");
        return $callback();
    } finally {
        flock($handle, LOCK_UN);
        fclose($handle);
    }
}

function ri_append_index_log(array $config, string $baseDir, string $event, array $context = []): void
{
    $logPath = ri_resolve_path($baseDir, $config['indexLog']);
    $logDir = dirname($logPath);
    if (!is_dir($logDir) && !mkdir($logDir, 0777, true) && !is_dir($logDir)) {
        return;
    }

    $line = json_encode([
        'time' => gmdate('c'),
        'event' => $event,
        'context' => $context,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    if ($line !== false) {
        file_put_contents($logPath, $line . PHP_EOL, FILE_APPEND | LOCK_EX);
    }
}

function ri_prepare_index_rebuild(PDO $index, array $folders): void
{
    $index->exec('DROP TABLE IF EXISTS current_images');
    $index->exec(
        'CREATE TEMP TABLE current_images (
            folder TEXT NOT NULL,
            index_key TEXT NOT NULL,
            PRIMARY KEY (folder, index_key)
        )'
    );

    $placeholders = ri_sql_placeholders('folder', count($folders));
    $deletePaths = $index->prepare(
        'DELETE FROM image_paths WHERE folder IN (' . implode(', ', array_keys($placeholders)) . ')'
    );
    $deletePaths->execute(array_combine(array_keys($placeholders), $folders));
}

function ri_finalize_index_rebuild(PDO $index, array $folders): void
{
    $placeholders = ri_sql_placeholders('folder', count($folders));
    $delete = $index->prepare(
        'DELETE FROM image_index
         WHERE NOT EXISTS (
             SELECT 1
             FROM current_images
             WHERE current_images.folder = image_index.folder
               AND current_images.index_key = image_index.index_key
         )
         AND folder IN (' . implode(', ', array_keys($placeholders)) . ')'
    );
    $delete->execute(array_combine(array_keys($placeholders), $folders));
    $index->exec(
        'DELETE FROM remote_link_checks
         WHERE NOT EXISTS (
             SELECT 1
             FROM image_index
             WHERE image_index.folder = remote_link_checks.folder
               AND image_index.id = remote_link_checks.image_id
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
            if (is_link($entryPath) || !ri_is_inside($folderPath, $entryPath)) {
                $warnings[] = 'Skipped unsafe or linked directory: ' . ri_to_url_path(ri_relative_path($folderPath, $entryPath));
                continue;
            }

            ri_scan_directory_for_index($config, $folder, $folderPath, $entryPath, $index, $warnings);
            continue;
        }

        if (!is_file($entryPath) || is_link($entryPath) || !ri_is_inside($folderPath, $entryPath)) {
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
        $orientation = ri_detect_local_image_type($entryPath);
        $id = ri_remember_index_image($index, $folder, 'local', $relativePath, $extensionWithoutDot, $relativePath, $orientation);
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
        if ($url === '' || str_starts_with($url, '#') || isset($seen[$url]) || !ri_is_safe_remote_url($url, $config, false)) {
            continue;
        }

        $seen[$url] = true;
        $extension = ri_extension_from_url($url, $config);
        $id = ri_remember_index_image($index, $folder, 'remote', $url, $extension, $url, RI_IMAGE_TYPE_UNKNOWN);
        ri_add_index_paths($index, $folder, $directoryRelativePath, $id);
    }
}

function ri_remember_index_image(
    PDO $index,
    string $folder,
    string $sourceType,
    string $target,
    string $extension,
    string $stableKey,
    string $orientation
): int
{
    $id = ri_index_image($index, $folder, $sourceType, $target, $extension, $stableKey, $orientation);
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
