<?php

declare(strict_types=1);

function ri_index_status(PDO $index, array $config): array
{
    $folders = ri_all_folder_summaries($index, $config);
    $total = 0;
    $localCount = 0;
    $remoteCount = 0;
    $pcCount = 0;
    $mobileCount = 0;
    foreach ($folders as $folder) {
        $total += $folder['total'];
        $localCount += $folder['localCount'];
        $remoteCount += $folder['remoteCount'];
        $pcCount += $folder['pcCount'];
        $mobileCount += $folder['mobileCount'];
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
        'pcCount' => $pcCount,
        'mobileCount' => $mobileCount,
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
            SUM(CASE WHEN source_type = "remote" THEN 1 ELSE 0 END) AS remote_count,
            SUM(CASE WHEN orientation = "pc" THEN 1 ELSE 0 END) AS pc_count,
            SUM(CASE WHEN orientation = "mobile" THEN 1 ELSE 0 END) AS mobile_count,
            SUM(CASE WHEN orientation = "square" THEN 1 ELSE 0 END) AS square_count,
            SUM(CASE WHEN orientation = "unknown" THEN 1 ELSE 0 END) AS unknown_count
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
        'pcCount' => (int)($row['pc_count'] ?? 0),
        'mobileCount' => (int)($row['mobile_count'] ?? 0),
        'squareCount' => (int)($row['square_count'] ?? 0),
        'unknownCount' => (int)($row['unknown_count'] ?? 0),
    ];
}
