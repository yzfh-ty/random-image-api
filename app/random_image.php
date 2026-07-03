<?php

declare(strict_types=1);

const RI_RESERVED_FOLDERS = ['_api', '_assets', '_remote'];
const RI_DEFAULT_IMAGE_EXTENSIONS = ['.jpg', '.jpeg', '.png', '.gif', '.webp', '.avif', '.bmp'];
const RI_DEFAULT_REMOTE_EXTENSION = 'jpg';

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

    ri_handle_path_random_request($segments, $config, $index, $baseDir);
}

function ri_cli_main(string $baseDir, array $argv): int
{
    $command = $argv[1] ?? 'help';
    $options = ri_cli_options($argv);

    try {
        if ($command === 'index') {
            $stats = ri_build_index($baseDir, $options['folder'] ?? null);
            ri_cli_output($stats, $options['json'], 'ri_cli_print_index_summary');
            return 0;
        }

        if ($command === 'status') {
            $config = ri_load_config($baseDir);
            $index = ri_open_image_index($config, $baseDir);
            ri_cli_output(ri_index_status($index, $config), $options['json'], 'ri_cli_print_status');
            return 0;
        }

        if ($command === 'paths') {
            $config = ri_load_config($baseDir);
            $index = ri_open_image_index($config, $baseDir);
            ri_cli_output(['paths' => ri_index_paths($index, $options['folder'] ?? null)], $options['json'], 'ri_cli_print_paths');
            return 0;
        }

        if ($command === 'check-links') {
            $result = ri_check_remote_links($baseDir, $options['folder'] ?? null);
            ri_cli_output($result, $options['json'], 'ri_cli_print_check_links_summary');
            return 0;
        }

        if ($command === 'help' || $command === '--help' || $command === '-h') {
            ri_cli_print_usage($argv[0] ?? 'bin/console.php');
            return 0;
        }

        fwrite(STDERR, "Unknown command: {$command}\n\n");
        ri_cli_print_usage($argv[0] ?? 'bin/console.php');
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
    echo "  php {$script} index [--folder=name]   Rebuild the SQLite image index.\n";
    echo "  php {$script} status [--json]         Show index status and folder counts.\n";
    echo "  php {$script} paths [--folder=name]   List indexed category paths.\n";
    echo "  php {$script} check-links             Check remote links from links.txt.\n";
}

function ri_cli_options(array $argv): array
{
    $options = [
        'folder' => null,
        'json' => false,
    ];

    foreach (array_slice($argv, 2) as $argument) {
        if ($argument === '--json') {
            $options['json'] = true;
            continue;
        }

        if (str_starts_with($argument, '--folder=')) {
            $folder = substr($argument, strlen('--folder='));
            $options['folder'] = $folder === '' ? null : $folder;
        }
    }

    return $options;
}

function ri_cli_output(array $payload, bool $json, callable $printer): void
{
    if ($json) {
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "\n";
        return;
    }

    $printer($payload);
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
    echo 'Last duration: ' . ($status['lastIndexedDurationMs'] ?? 'unknown') . " ms\n";
    echo 'Last warnings: ' . ($status['lastIndexedWarningCount'] ?? 'unknown') . "\n";
    if (($status['lastIndexedError'] ?? null) !== null) {
        echo 'Last error: ' . $status['lastIndexedError'] . "\n";
    }
    echo 'Total: ' . $status['total'] . ' images, local: ' . $status['localCount'] . ', remote: ' . $status['remoteCount'] . "\n";
    echo 'Paths: ' . $status['pathCount'] . "\n";
    echo 'Remote link checks: ' . $status['remoteLinkChecks']['total'] . ' checked, '
        . $status['remoteLinkChecks']['ok'] . ' ok, '
        . $status['remoteLinkChecks']['failed'] . ' failed' . "\n";

    foreach ($status['folders'] as $folder) {
        echo '- ' . $folder['folder'] . ': ' . $folder['total'] . ' total, local ' . $folder['localCount'] . ', remote ' . $folder['remoteCount'] . "\n";
    }
}

function ri_cli_print_paths(array $payload): void
{
    foreach ($payload['paths'] as $path) {
        echo '- ' . $path['path'] . ': ' . $path['total'] . ' images (' . $path['folder'] . ')' . "\n";
    }
}

function ri_cli_print_check_links_summary(array $result): void
{
    echo 'Remote links checked: ' . $result['total'] . "\n";
    echo 'OK: ' . $result['ok'] . ', failed: ' . $result['failed'] . "\n";

    foreach ($result['items'] as $item) {
        $status = $item['ok'] ? 'OK' : 'FAIL';
        $code = $item['statusCode'] === null ? '-' : (string)$item['statusCode'];
        echo '- [' . $status . '] ' . $item['folder'] . '/' . $item['id'] . ' HTTP ' . $code . ' ' . $item['url'] . "\n";
        if (!$item['ok'] && $item['error'] !== null) {
            echo '  ' . $item['error'] . "\n";
        }
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
        if ($url === '' || str_starts_with($url, '#') || isset($seen[$url]) || !ri_is_safe_remote_url($url, $config, false)) {
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
    $pdo->exec('PRAGMA journal_mode = WAL');
    $pdo->exec('PRAGMA synchronous = NORMAL');
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
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS remote_link_checks (
            folder TEXT NOT NULL,
            image_id INTEGER NOT NULL,
            url TEXT NOT NULL,
            ok INTEGER NOT NULL,
            status_code INTEGER,
            error TEXT,
            duration_ms INTEGER NOT NULL,
            checked_at TEXT NOT NULL,
            PRIMARY KEY (folder, image_id)
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

    if (
        !preg_match('/^[A-Za-z0-9]+$/', $extension)
        || !in_array('.' . $extension, $config['imageExtensions'], true)
    ) {
        return null;
    }

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
        $item['folderPath'] = $folderPath;
        return $item;
    }

    if ($sourceType === 'remote' && ri_is_safe_remote_url($target, $config, false)) {
        $item['originalUrl'] = $target;
        return $item;
    }

    return null;
}

function ri_handle_random_database_request(string $scopePath, ?string $indexedPath, PDO $index, array $config, string $baseDir): void
{
    $source = ri_source_filter();
    $item = ri_select_and_remember_indexed_item($index, $config, $baseDir, $scopePath, $indexedPath, $source);
    if ($item === null) {
        ri_send_error(404, 'empty_pool', 'No indexed image found. Run the CLI index command first.');
    }

    ri_respond_random_item($item, $config);
}

function ri_select_and_remember_indexed_item(
    PDO $index,
    array $config,
    string $baseDir,
    string $scopePath,
    ?string $indexedPath,
    ?string $source
): ?array {
    ri_start_session($config);
    $_SESSION['last_served'] ??= [];
    $scopeKey = $scopePath . '|' . ($source ?? 'all');
    $lastKey = is_string($_SESSION['last_served'][$scopeKey] ?? null) ? $_SESSION['last_served'][$scopeKey] : null;
    $item = $indexedPath === null
        ? ri_random_item_for_all($index, $config, $baseDir, $source, $lastKey)
        : ri_random_item_for_path($index, $config, $baseDir, $indexedPath, $source, $lastKey);

    if ($item !== null) {
        $_SESSION['last_served'][$scopeKey] = ri_image_key($item);
    }
    session_write_close();

    return $item;
}

function ri_random_item_for_all(PDO $index, array $config, string $baseDir, ?string $source = null, ?string $lastKey = null): ?array
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
        $lastKey
    );
}

function ri_random_item_for_path(
    PDO $index,
    array $config,
    string $baseDir,
    string $scopePath,
    ?string $source = null,
    ?string $lastKey = null
): ?array {
    return ri_random_item_from_query(
        $index,
        $config,
        $baseDir,
        'image_paths AS p INNER JOIN image_index AS i ON i.folder = p.folder AND i.id = p.image_id',
        'p.path = :path',
        [':path' => $scopePath],
        $source,
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
    ?string $lastKey
): ?array {
    $whereParts = [$whereSql];
    if ($source === 'local' || $source === 'remote') {
        $whereParts[] = 'i.source_type = :source';
        $params[':source'] = $source;
    }

    $total = ri_count_random_candidates($index, $fromSql, $whereParts, $params);
    if ($total === 0) {
        return null;
    }

    $candidateWhereParts = $whereParts;
    $candidateParams = $params;
    $last = ri_parse_image_key($lastKey);
    if ($total > 1 && $last !== null) {
        $candidateWhereParts[] = 'NOT (i.folder = :last_folder AND i.id = :last_id)';
        $candidateParams[':last_folder'] = $last['folder'];
        $candidateParams[':last_id'] = $last['id'];
        $candidateTotal = ri_count_random_candidates($index, $fromSql, $candidateWhereParts, $candidateParams);
        if ($candidateTotal === 0) {
            $candidateWhereParts = $whereParts;
            $candidateParams = $params;
            $candidateTotal = $total;
        }
    } else {
        $candidateTotal = $total;
    }

    $offset = random_int(0, $candidateTotal - 1);
    $statement = $index->prepare(
        'SELECT i.folder, i.index_key, i.source_type, i.target, i.extension, i.id
         FROM ' . $fromSql . '
         WHERE ' . implode(' AND ', $candidateWhereParts) . '
         ORDER BY i.folder, i.id
         LIMIT 1 OFFSET :offset'
    );
    foreach ($candidateParams as $key => $value) {
        $statement->bindValue($key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
    }
    $statement->bindValue(':offset', $offset, PDO::PARAM_INT);
    $statement->execute();

    $row = $statement->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row)) {
        return null;
    }

    return ri_row_to_item($row, $config, $baseDir);
}

function ri_count_random_candidates(PDO $index, string $fromSql, array $whereParts, array $params): int
{
    $statement = $index->prepare(
        'SELECT COUNT(*)
         FROM ' . $fromSql . '
         WHERE ' . implode(' AND ', $whereParts)
    );
    foreach ($params as $key => $value) {
        $statement->bindValue($key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
    }
    $statement->execute();

    return (int)$statement->fetchColumn();
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

function ri_handle_random_request(string $scopePath, array $items, array $config): void
{
    $source = ri_source_filter();
    $items = ri_filter_items($items, $source);
    if ($items === []) {
        ri_send_error(404, 'empty_pool', 'No indexed image found. Run the CLI index command first.');
    }

    $item = ri_select_and_remember_item($items, $scopePath, $source, $config);
    ri_respond_random_item($item, $config);
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
    ri_handle_random_database_request($scopePath, $scopePath, $index, $config, $baseDir);
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

    $pathCount = (int)$index->query(
        'SELECT COUNT(*)
         FROM (
            SELECT path, folder
            FROM image_paths
            GROUP BY path, folder
         )'
    )->fetchColumn();

    return [
        'database' => $config['indexDatabase'],
        'lastIndexedAt' => ri_get_index_meta($index, 'last_indexed_at'),
        'lastIndexedDurationMs' => ri_nullable_int(ri_get_index_meta($index, 'last_indexed_duration_ms')),
        'lastIndexedWarningCount' => ri_nullable_int(ri_get_index_meta($index, 'last_indexed_warning_count')),
        'lastIndexedError' => ri_get_index_meta($index, 'last_indexed_error') ?: null,
        'total' => $total,
        'localCount' => $localCount,
        'remoteCount' => $remoteCount,
        'pathCount' => $pathCount,
        'remoteLinkChecks' => ri_remote_link_check_status($index),
        'folders' => $folders,
    ];
}

function ri_nullable_int(?string $value): ?int
{
    if ($value === null || $value === '') {
        return null;
    }

    return (int)$value;
}

function ri_index_paths(PDO $index, ?string $folder = null): array
{
    if ($folder === null) {
        $statement = $index->query(
            'SELECT path, folder, COUNT(*) AS total
             FROM image_paths
             GROUP BY path, folder
             ORDER BY path, folder'
        );
    } else {
        $statement = $index->prepare(
            'SELECT path, folder, COUNT(*) AS total
             FROM image_paths
             WHERE folder = :folder
             GROUP BY path, folder
             ORDER BY path, folder'
        );
        $statement->execute([':folder' => $folder]);
    }

    $paths = [];
    foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $paths[] = [
            'path' => (string)$row['path'],
            'folder' => (string)$row['folder'],
            'total' => (int)$row['total'],
        ];
    }

    return $paths;
}

function ri_remote_link_check_status(PDO $index): array
{
    $row = $index->query(
        'SELECT
            COUNT(*) AS total,
            SUM(CASE WHEN ok = 1 THEN 1 ELSE 0 END) AS ok_count,
            SUM(CASE WHEN ok = 0 THEN 1 ELSE 0 END) AS failed_count,
            MAX(checked_at) AS last_checked_at
         FROM remote_link_checks'
    )->fetch(PDO::FETCH_ASSOC) ?: [];

    return [
        'total' => (int)($row['total'] ?? 0),
        'ok' => (int)($row['ok_count'] ?? 0),
        'failed' => (int)($row['failed_count'] ?? 0),
        'lastCheckedAt' => $row['last_checked_at'] ?? null,
    ];
}

function ri_check_remote_links(string $baseDir, ?string $onlyFolder = null): array
{
    $config = ri_load_config($baseDir);
    if ($onlyFolder !== null && !in_array($onlyFolder, $config['folders'], true)) {
        throw new RuntimeException('Folder is not configured: ' . $onlyFolder);
    }

    $index = ri_open_image_index($config, $baseDir);
    $items = ri_remote_link_items($index, $onlyFolder);
    $results = [];
    $ok = 0;
    $failed = 0;

    foreach ($items as $item) {
        $checked = ri_check_remote_url($item['url'], $config);
        $result = [
            'folder' => $item['folder'],
            'id' => $item['id'],
            'url' => $item['url'],
            'ok' => $checked['ok'],
            'statusCode' => $checked['statusCode'],
            'error' => $checked['error'],
            'durationMs' => $checked['durationMs'],
            'checkedAt' => gmdate('c'),
        ];
        ri_store_remote_link_check($index, $result);
        $results[] = $result;
        $checked['ok'] ? $ok++ : $failed++;
    }

    return [
        'total' => count($items),
        'ok' => $ok,
        'failed' => $failed,
        'items' => $results,
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
    $handle = curl_init($url);
    if ($handle === false) {
        return [
            'ok' => false,
            'statusCode' => null,
            'error' => 'Cannot initialize cURL.',
            'durationMs' => 0,
        ];
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

    $response = curl_exec($handle);
    $error = curl_error($handle);
    $errno = curl_errno($handle);
    $statusCode = (int)curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    curl_close($handle);

    if ($errno !== 0) {
        return [
            'ok' => false,
            'statusCode' => $statusCode > 0 ? $statusCode : null,
            'error' => $error,
            'durationMs' => (int)round((microtime(true) - $startedAt) * 1000),
        ];
    }

    if ($statusCode >= 300 && $statusCode < 400 && $redirectsRemaining > 0 && is_string($response)) {
        $location = ri_location_from_header_string($response);
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

    return [
        'ok' => $statusCode >= 200 && $statusCode < 400,
        'statusCode' => $statusCode > 0 ? $statusCode : null,
        'error' => null,
        'durationMs' => (int)round((microtime(true) - $startedAt) * 1000),
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

function ri_select_and_remember_item(array $items, string $scopePath, ?string $source, ?array $config = null): array
{
    ri_start_session($config);
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
