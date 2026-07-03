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
    echo 'Types: pc ' . $status['pcCount'] . ', mobile ' . $status['mobileCount'] . "\n";
    echo 'Paths: ' . $status['pathCount'] . "\n";
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
