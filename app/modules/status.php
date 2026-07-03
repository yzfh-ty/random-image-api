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

    return [
        'database' => $config['indexDatabase'],
        'schemaVersion' => ri_get_index_schema_version($index),
        'schemaUpdatedAt' => ri_get_index_meta($index, 'schema_updated_at'),
        'lastIndexedAt' => ri_get_index_meta($index, 'last_indexed_at'),
        'lastIndexedDurationMs' => ri_nullable_int(ri_get_index_meta($index, 'last_indexed_duration_ms')),
        'lastIndexedWarningCount' => ri_nullable_int(ri_get_index_meta($index, 'last_indexed_warning_count')),
        'lastIndexedError' => ri_get_index_meta($index, 'last_indexed_error') ?: null,
        'total' => $total,
        'localCount' => $localCount,
        'remoteCount' => $remoteCount,
        'pcCount' => $pcCount,
        'mobileCount' => $mobileCount,
        'remoteLinkChecks' => ri_remote_link_check_status($index, $config),
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

function ri_remote_link_check_status(PDO $index, array $config): array
{
    $cutoff = gmdate('c', time() - (int)$config['remote']['checkMaxAgeSeconds']);
    $statement = $index->prepare(
        'SELECT
            COUNT(i.id) AS indexed_count,
            SUM(CASE WHEN r.image_id IS NOT NULL THEN 1 ELSE 0 END) AS checked_count,
            SUM(CASE WHEN r.ok = 1 THEN 1 ELSE 0 END) AS ok_count,
            SUM(CASE WHEN r.ok = 0 THEN 1 ELSE 0 END) AS failed_count,
            SUM(CASE WHEN r.image_id IS NULL THEN 1 ELSE 0 END) AS unchecked_count,
            SUM(CASE WHEN r.ok = 1 AND r.checked_at < :cutoff THEN 1 ELSE 0 END) AS stale_count,
            SUM(CASE WHEN r.ok = 1 AND r.checked_at >= :cutoff THEN 1 ELSE 0 END) AS fresh_ok_count,
            MAX(r.checked_at) AS last_checked_at
         FROM image_index AS i
         LEFT JOIN remote_link_checks AS r
           ON r.folder = i.folder
          AND r.image_id = i.id
          AND r.url = i.target
         WHERE i.source_type = "remote"'
    );
    $statement->execute([':cutoff' => $cutoff]);
    $row = $statement->fetch(PDO::FETCH_ASSOC) ?: [];
    $indexed = (int)($row['indexed_count'] ?? 0);
    $freshOk = (int)($row['fresh_ok_count'] ?? 0);

    return [
        'total' => (int)($row['checked_count'] ?? 0),
        'indexed' => $indexed,
        'checked' => (int)($row['checked_count'] ?? 0),
        'ok' => (int)($row['ok_count'] ?? 0),
        'failed' => (int)($row['failed_count'] ?? 0),
        'unchecked' => (int)($row['unchecked_count'] ?? 0),
        'stale' => (int)($row['stale_count'] ?? 0),
        'serviceable' => ($config['remote']['requireChecked'] ?? true) ? $freshOk : $indexed,
        'requiresCheck' => (bool)($config['remote']['requireChecked'] ?? true),
        'checkMaxAgeSeconds' => (int)$config['remote']['checkMaxAgeSeconds'],
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
