<?php

declare(strict_types=1);

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
            orientation TEXT NOT NULL DEFAULT "unknown",
            id INTEGER NOT NULL,
            PRIMARY KEY (folder, index_key),
            UNIQUE (folder, id)
        )'
    );
    ri_ensure_image_index_schema($pdo);
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_image_index_orientation ON image_index (orientation)');
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
function ri_ensure_image_index_schema(PDO $index): void
{
    if (!ri_table_has_column($index, 'image_index', 'orientation')) {
        $index->exec('ALTER TABLE image_index ADD COLUMN orientation TEXT NOT NULL DEFAULT "unknown"');
    }
}

function ri_table_has_column(PDO $index, string $table, string $column): bool
{
    $statement = $index->query('PRAGMA table_info(' . $table . ')');
    foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if (($row['name'] ?? null) === $column) {
            return true;
        }
    }

    return false;
}

function ri_index_image(
    PDO $index,
    string $folder,
    string $sourceType,
    string $target,
    string $extension,
    string $stableKey,
    string $orientation
): int
{
    $indexKey = $sourceType . ':' . $stableKey;
    $orientation = ri_normalize_image_type($orientation);
    $select = $index->prepare('SELECT id FROM image_index WHERE folder = :folder AND index_key = :index_key');
    $select->execute([
        ':folder' => $folder,
        ':index_key' => $indexKey,
    ]);

    $existingId = $select->fetchColumn();
    if ($existingId !== false) {
        $update = $index->prepare(
            'UPDATE image_index
             SET source_type = :source_type, target = :target, extension = :extension, orientation = :orientation
             WHERE folder = :folder AND index_key = :index_key'
        );
        $update->execute([
            ':source_type' => $sourceType,
            ':target' => $target,
            ':extension' => $extension,
            ':orientation' => $orientation,
            ':folder' => $folder,
            ':index_key' => $indexKey,
        ]);
        return (int)$existingId;
    }

    $id = ri_next_image_id($index, $folder);
    $insert = $index->prepare(
        'INSERT INTO image_index (folder, index_key, source_type, target, extension, orientation, id)
         VALUES (:folder, :index_key, :source_type, :target, :extension, :orientation, :id)'
    );
    $insert->execute([
        ':folder' => $folder,
        ':index_key' => $indexKey,
        ':source_type' => $sourceType,
        ':target' => $target,
        ':extension' => $extension,
        ':orientation' => $orientation,
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
        'SELECT folder, index_key, source_type, target, extension, orientation, id
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
        'SELECT i.folder, i.index_key, i.source_type, i.target, i.extension, i.orientation, i.id
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
        'SELECT folder, index_key, source_type, target, extension, orientation, id
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
    $orientation = ri_normalize_image_type((string)($row['orientation'] ?? RI_IMAGE_TYPE_UNKNOWN));
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
        'orientation' => $orientation,
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
