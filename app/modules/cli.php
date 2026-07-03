<?php

declare(strict_types=1);

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

        if ($command === 'config') {
            $config = ri_load_config($baseDir);
            ri_cli_output(ri_cli_config_snapshot($config, $baseDir), $options['json'], 'ri_cli_print_config');
            return 0;
        }

        if ($command === 'doctor') {
            $result = ri_cli_doctor($baseDir);
            ri_cli_output($result, $options['json'], 'ri_cli_print_doctor');
            return $result['ok'] ? 0 : 1;
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
    echo "  php {$script} config [--json]         Show effective runtime configuration.\n";
    echo "  php {$script} doctor [--json]         Check PHP extensions, paths, and folders.\n";
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

function ri_cli_config_snapshot(array $config, string $baseDir): array
{
    $linkCheck = $config['linkCheck'];
    $linkCheck['proxySet'] = $linkCheck['proxy'] !== '';
    $linkCheck['proxy'] = $linkCheck['proxy'] === '' ? '' : '[redacted]';

    return [
        'server' => $config['server'],
        'imageRoot' => $config['imageRoot'],
        'folders' => $config['folders'],
        'linkFiles' => $config['linkFiles'],
        'admin' => [
            'enabled' => $config['adminEnabled'],
            'prefix' => $config['adminPrefix'],
            'tokenSet' => $config['adminToken'] !== '',
            'allowQueryToken' => $config['adminAllowQueryToken'],
        ],
        'index' => [
            'database' => $config['indexDatabase'],
            'lock' => $config['indexLock'],
            'log' => $config['indexLog'],
            'logMaxBytes' => $config['indexLogMaxBytes'],
            'logBackups' => $config['indexLogBackups'],
        ],
        'images' => [
            'extensions' => $config['imageExtensions'],
            'allowSvg' => $config['allowSvg'],
            'defaultMode' => $config['defaultMode'],
        ],
        'linkCheck' => $linkCheck,
        'sendfile' => $config['sendfile'],
        'resolvedPaths' => [
            'baseDir' => $baseDir,
            'imageRoot' => ri_resolve_path($baseDir, $config['imageRoot']),
            'indexDatabase' => ri_resolve_path($baseDir, $config['indexDatabase']),
            'indexLock' => ri_resolve_path($baseDir, $config['indexLock']),
            'indexLog' => ri_resolve_path($baseDir, $config['indexLog']),
        ],
    ];
}

function ri_cli_doctor(string $baseDir): array
{
    $checks = [];
    ri_cli_add_check(
        $checks,
        'php_version',
        version_compare(PHP_VERSION, '8.2.0', '>=') ? 'ok' : 'fail',
        'PHP version: ' . PHP_VERSION
    );
    ri_cli_add_check($checks, 'pdo_extension', class_exists(PDO::class) ? 'ok' : 'fail', 'PDO extension is available.');
    ri_cli_add_check(
        $checks,
        'pdo_sqlite_driver',
        class_exists(PDO::class) && in_array('sqlite', PDO::getAvailableDrivers(), true) ? 'ok' : 'fail',
        'PDO SQLite driver is available.'
    );
    ri_cli_add_check($checks, 'image_size', function_exists('getimagesize') ? 'ok' : 'fail', 'getimagesize is available.');
    ri_cli_add_check($checks, 'curl_extension', function_exists('curl_init') ? 'ok' : 'warn', 'cURL is optional; stream fallback is used when missing.');

    try {
        $config = ri_load_config($baseDir);
        ri_cli_add_check($checks, 'config', 'ok', 'Configuration loaded successfully.');
    } catch (Throwable $error) {
        ri_cli_add_check($checks, 'config', 'fail', $error->getMessage());
        return ri_cli_doctor_result($checks);
    }

    if ($config['linkCheck']['concurrency'] > 1 && !function_exists('curl_multi_init')) {
        ri_cli_add_check($checks, 'linkcheck_concurrency', 'warn', 'Concurrent link checks require cURL; sequential fallback will be used.');
    }
    if ($config['linkCheck']['allowedHosts'] === []) {
        ri_cli_add_check($checks, 'remote_allowed_hosts', 'warn', 'Remote link host allowlist is empty; any public host in links.txt can be checked.');
    } else {
        ri_cli_add_check($checks, 'remote_allowed_hosts', 'ok', 'Remote link host allowlist is configured.');
    }
    if (!($config['linkCheck']['bindResolvedIp'] ?? true)) {
        ri_cli_add_check($checks, 'remote_ip_binding', 'warn', 'Resolved-IP binding is disabled for remote link checks.');
    } elseif (!function_exists('curl_init')) {
        ri_cli_add_check($checks, 'remote_ip_binding', 'warn', 'Resolved-IP binding requires cURL; stream fallback cannot bind DNS results.');
    } elseif ($config['linkCheck']['proxy'] !== '') {
        ri_cli_add_check($checks, 'remote_ip_binding', 'warn', 'HTTP proxy is configured; upstream DNS resolution is handled by the proxy.');
    } else {
        ri_cli_add_check($checks, 'remote_ip_binding', 'ok', 'Remote link checks bind cURL requests to resolved public IPs.');
    }
    if ($config['server']['allowedHosts'] === RI_DEFAULT_ALLOWED_HOSTS) {
        ri_cli_add_check($checks, 'allowed_hosts', 'warn', 'Only local development hosts are allowed; set RI_ALLOWED_HOSTS for production domains.');
    } else {
        ri_cli_add_check($checks, 'allowed_hosts', 'ok', 'Allowed hosts are configured.');
    }

    $imageRoot = ri_resolve_path($baseDir, $config['imageRoot']);
    ri_cli_add_path_check($checks, 'image_root', $imageRoot, true);
    foreach ($config['folders'] as $folder) {
        $folderPath = ri_resolve_path($imageRoot, $folder);
        $status = is_dir($folderPath) && ri_is_inside($imageRoot, $folderPath) ? 'ok' : 'fail';
        ri_cli_add_check($checks, 'folder:' . $folder, $status, $folderPath);
    }

    foreach (['indexDatabase', 'indexLock', 'indexLog'] as $key) {
        ri_cli_add_parent_path_check($checks, $key, ri_resolve_path($baseDir, $config[$key]));
    }

    $databasePath = ri_resolve_path($baseDir, $config['indexDatabase']);
    ri_cli_add_check(
        $checks,
        'index_database',
        is_file($databasePath) ? 'ok' : 'warn',
        is_file($databasePath) ? 'SQLite index exists.' : 'SQLite index does not exist yet; run the index command.'
    );

    return ri_cli_doctor_result($checks);
}

function ri_cli_add_path_check(array &$checks, string $name, string $path, bool $mustBeDirectory): void
{
    $exists = $mustBeDirectory ? is_dir($path) : file_exists($path);
    $readable = $exists && is_readable($path);
    $writable = $exists && is_writable($path);
    $status = $exists && $readable && $writable ? 'ok' : 'fail';
    ri_cli_add_check($checks, $name, $status, $path);
}

function ri_cli_add_parent_path_check(array &$checks, string $name, string $path): void
{
    $directory = dirname($path);
    if (is_dir($directory)) {
        ri_cli_add_check($checks, $name, is_writable($directory) ? 'ok' : 'fail', $directory);
        return;
    }

    $parent = dirname($directory);
    $status = is_dir($parent) && is_writable($parent) ? 'warn' : 'fail';
    ri_cli_add_check($checks, $name, $status, 'Directory does not exist yet: ' . $directory);
}

function ri_cli_add_check(array &$checks, string $name, string $status, string $message): void
{
    $checks[] = [
        'name' => $name,
        'status' => $status,
        'message' => $message,
    ];
}

function ri_cli_doctor_result(array $checks): array
{
    $failures = 0;
    $warnings = 0;
    foreach ($checks as $check) {
        if (($check['status'] ?? '') === 'fail') {
            $failures++;
        }
        if (($check['status'] ?? '') === 'warn') {
            $warnings++;
        }
    }

    return [
        'ok' => $failures === 0,
        'phpVersion' => PHP_VERSION,
        'sapi' => PHP_SAPI,
        'failureCount' => $failures,
        'warningCount' => $warnings,
        'checks' => $checks,
    ];
}

function ri_cli_print_index_summary(array $stats): void
{
    echo "Index rebuilt successfully.\n";
    echo 'Started at: ' . $stats['startedAt'] . "\n";
    echo 'Finished at: ' . $stats['finishedAt'] . "\n";
    echo 'Total: ' . $stats['total'] . ' images, local: ' . $stats['localCount'] . ', remote: ' . $stats['remoteCount'] . "\n";

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

function ri_cli_print_config(array $config): void
{
    echo "Effective configuration:\n";
    echo 'Image root: ' . $config['imageRoot'] . "\n";
    echo 'Folders: ' . implode(', ', $config['folders']) . "\n";
    echo 'Link files: ' . implode(', ', $config['linkFiles']) . "\n";
    echo 'Default mode: ' . $config['images']['defaultMode'] . "\n";
    echo 'Admin: ' . ($config['admin']['enabled'] ? 'enabled' : 'disabled')
        . ', token set: ' . ($config['admin']['tokenSet'] ? 'yes' : 'no') . "\n";
    echo 'Index database: ' . $config['index']['database'] . "\n";
    echo 'Server: ' . $config['server']['host'] . ':' . $config['server']['port'] . "\n";
}

function ri_cli_print_doctor(array $result): void
{
    echo 'Doctor: ' . ($result['ok'] ? 'OK' : 'FAILED')
        . ' (' . $result['failureCount'] . ' failures, ' . $result['warningCount'] . " warnings)\n";

    foreach ($result['checks'] as $check) {
        echo '- [' . strtoupper($check['status']) . '] ' . $check['name'] . ': ' . $check['message'] . "\n";
    }
}

function ri_cli_print_status(array $status): void
{
    echo 'Database: ' . $status['database'] . "\n";
    echo 'Schema version: ' . ($status['schemaVersion'] ?? 'unknown') . "\n";
    echo 'Last indexed at: ' . ($status['lastIndexedAt'] ?? 'never') . "\n";
    echo 'Last duration: ' . ($status['lastIndexedDurationMs'] ?? 'unknown') . " ms\n";
    echo 'Last warnings: ' . ($status['lastIndexedWarningCount'] ?? 'unknown') . "\n";
    if (($status['lastIndexedError'] ?? null) !== null) {
        echo 'Last error: ' . $status['lastIndexedError'] . "\n";
    }
    echo 'Total: ' . $status['total'] . ' images, local: ' . $status['localCount'] . ', remote: ' . $status['remoteCount'] . "\n";
    echo 'Types: pc ' . $status['pcCount'] . ', mobile ' . $status['mobileCount'] . "\n";
    echo 'Remote link checks: ' . $status['remoteLinkChecks']['total'] . ' checked, '
        . $status['remoteLinkChecks']['ok'] . ' ok, '
        . $status['remoteLinkChecks']['failed'] . ' failed' . "\n";

    foreach ($status['folders'] as $folder) {
        echo '- ' . $folder['folder'] . ': ' . $folder['total']
            . ' total, local ' . $folder['localCount']
            . ', remote ' . $folder['remoteCount']
            . ', pc ' . $folder['pcCount']
            . ', mobile ' . $folder['mobileCount'] . "\n";
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
