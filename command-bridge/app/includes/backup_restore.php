<?php
declare(strict_types=1);

require_once __DIR__ . '/version.php';
/*
 * Application data backup and restore helpers.
 *
 * Backups contain current MariaDB table data plus storage/uploads and
 * storage/branding. They intentionally do not include server configuration,
 * database credentials, or TLS material.
 */

const COMMAND_BRIDGE_BACKUP_FORMAT = 'command-bridge-application-backup';
const COMMAND_BRIDGE_BACKUP_FORMAT_VERSION = 1;
const COMMAND_BRIDGE_BACKUP_MAX_BYTES = 524288000; // 500 MiB
const COMMAND_BRIDGE_BACKUP_FILE_ROOTS = ['uploads', 'branding'];

final class CommandBridgeBackupException extends RuntimeException
{
}

function command_bridge_backup_quote_identifier(string $identifier): string
{
    if (!preg_match('/^[A-Za-z0-9_]+$/', $identifier)) {
        throw new CommandBridgeBackupException('The backup contains an invalid database table or column name.');
    }

    return '`' . $identifier . '`';
}

function command_bridge_backup_tables(): array
{
    $rows = db()->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'")->fetchAll(PDO::FETCH_NUM);
    $tables = [];

    foreach ($rows as $row) {
        $table = (string) ($row[0] ?? '');
        command_bridge_backup_quote_identifier($table);
        $tables[] = $table;
    }

    sort($tables, SORT_STRING);

    if ($tables === []) {
        throw new CommandBridgeBackupException('No database tables were available to back up.');
    }

    return $tables;
}

function command_bridge_backup_columns(string $table): array
{
    $rows = db()->query('SHOW COLUMNS FROM ' . command_bridge_backup_quote_identifier($table))->fetchAll(PDO::FETCH_ASSOC);
    $columns = [];

    foreach ($rows as $row) {
        $column = (string) ($row['Field'] ?? '');
        command_bridge_backup_quote_identifier($column);
        $columns[] = $column;
    }

    if ($columns === []) {
        throw new CommandBridgeBackupException('A database table did not contain any columns.');
    }

    return $columns;
}

function command_bridge_backup_delete_tree(string $path): void
{
    if (!is_dir($path) && !is_link($path)) {
        return;
    }

    if (is_link($path) || is_file($path)) {
        @unlink($path);
        return;
    }

    $items = scandir($path);
    if ($items !== false) {
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            command_bridge_backup_delete_tree($path . DIRECTORY_SEPARATOR . $item);
        }
    }

    @rmdir($path);
}

function command_bridge_backup_json_encode(mixed $value): string
{
    try {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    } catch (JsonException $exception) {
        throw new CommandBridgeBackupException('The database contains data that could not be encoded for a backup.', 0, $exception);
    }
}

function command_bridge_backup_create_archive(string $archivePath): array
{
    if (!class_exists('ZipArchive')) {
        throw new CommandBridgeBackupException('The PHP ZipArchive extension is required for backup and restore.');
    }

    $zip = new ZipArchive();
    if ($zip->open($archivePath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new CommandBridgeBackupException('The backup archive could not be created.');
    }

    $temporaryFiles = [];

    try {
        $tables = command_bridge_backup_tables();
        $manifestTables = [];

        foreach ($tables as $table) {
            $columns = command_bridge_backup_columns($table);
            $temporaryFile = tempnam(sys_get_temp_dir(), 'cb_backup_data_');
            if ($temporaryFile === false) {
                throw new CommandBridgeBackupException('A temporary backup file could not be created.');
            }
            $temporaryFiles[] = $temporaryFile;

            $handle = fopen($temporaryFile, 'wb');
            if ($handle === false) {
                throw new CommandBridgeBackupException('A temporary backup file could not be opened.');
            }

            try {
                fwrite($handle, '[');
                $statement = db()->query('SELECT * FROM ' . command_bridge_backup_quote_identifier($table));
                $rowCount = 0;

                while (($row = $statement->fetch(PDO::FETCH_ASSOC)) !== false) {
                    if ($rowCount > 0) {
                        fwrite($handle, ',');
                    }
                    fwrite($handle, command_bridge_backup_json_encode($row));
                    $rowCount++;
                }

                fwrite($handle, ']');
            } finally {
                fclose($handle);
            }

            $dataFile = 'database/' . $table . '.json';
            if (!$zip->addFile($temporaryFile, $dataFile)) {
                throw new CommandBridgeBackupException('Database data could not be added to the backup archive.');
            }

            $manifestTables[] = [
                'name' => $table,
                'columns' => $columns,
                'rows' => $rowCount,
                'data_file' => $dataFile,
            ];
        }

        $storageRoot = storage_root_path();
        $fileCount = 0;

        foreach (COMMAND_BRIDGE_BACKUP_FILE_ROOTS as $root) {
            $sourceRoot = $storageRoot . DIRECTORY_SEPARATOR . $root;
            if (!is_dir($sourceRoot)) {
                continue;
            }

            $sourceRootReal = realpath($sourceRoot);
            if ($sourceRootReal === false) {
                continue;
            }

            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($sourceRootReal, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::LEAVES_ONLY
            );

            foreach ($iterator as $item) {
                if (!$item instanceof SplFileInfo || !$item->isFile() || $item->isLink()) {
                    continue;
                }

                $filePath = $item->getRealPath();
                if ($filePath === false || !str_starts_with($filePath, $sourceRootReal . DIRECTORY_SEPARATOR)) {
                    continue;
                }

                $relativePath = str_replace(DIRECTORY_SEPARATOR, '/', substr($filePath, strlen($sourceRootReal) + 1));
                if ($relativePath === '' || str_contains($relativePath, "\0")) {
                    continue;
                }

                if (!$zip->addFile($filePath, 'files/' . $root . '/' . $relativePath)) {
                    throw new CommandBridgeBackupException('Application files could not be added to the backup archive.');
                }
                $fileCount++;
            }
        }

        $manifest = [
            'format' => COMMAND_BRIDGE_BACKUP_FORMAT,
            'format_version' => COMMAND_BRIDGE_BACKUP_FORMAT_VERSION,
            'created_at_utc' => gmdate('c'),
            'application_version' => CB_VERSION,
            'tables' => $manifestTables,
            'file_roots' => COMMAND_BRIDGE_BACKUP_FILE_ROOTS,
            'file_count' => $fileCount,
        ];

        if (!$zip->addFromString('manifest.json', command_bridge_backup_json_encode($manifest))) {
            throw new CommandBridgeBackupException('Backup metadata could not be added to the archive.');
        }

        if (!$zip->close()) {
            throw new CommandBridgeBackupException('The backup archive could not be finalized.');
        }

        return $manifest;
    } catch (Throwable $exception) {
        $zip->close();
        if ($exception instanceof CommandBridgeBackupException) {
            throw $exception;
        }
        throw new CommandBridgeBackupException('The backup could not be created.', 0, $exception);
    } finally {
        foreach ($temporaryFiles as $temporaryFile) {
            @unlink($temporaryFile);
        }
    }
}

function command_bridge_backup_stream_download(): never
{
    $archivePath = tempnam(sys_get_temp_dir(), 'cb_backup_');
    if ($archivePath === false) {
        throw new CommandBridgeBackupException('A temporary backup archive could not be created.');
    }

    try {
        command_bridge_backup_create_archive($archivePath);

        if (headers_sent()) {
            throw new CommandBridgeBackupException('The backup download could not be started because response headers were already sent.');
        }

        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }

        $filename = 'commandbridge-backup-' . gmdate('Ymd-His') . '.zip';
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . (string) filesize($archivePath));
        header('Cache-Control: no-store, private, max-age=0');
        header('Pragma: no-cache');

        $stream = fopen($archivePath, 'rb');
        if ($stream === false) {
            throw new CommandBridgeBackupException('The completed backup archive could not be opened.');
        }

        try {
            fpassthru($stream);
        } finally {
            fclose($stream);
        }
    } finally {
        @unlink($archivePath);
    }

    exit;
}

function command_bridge_backup_upload_error(array $upload): void
{
    if (!isset($upload['error']) || !is_int($upload['error'])) {
        throw new CommandBridgeBackupException('Choose a valid backup ZIP file.');
    }

    if ($upload['error'] === UPLOAD_ERR_OK) {
        return;
    }

    $messages = [
        UPLOAD_ERR_INI_SIZE => 'The backup file is larger than the server upload limit.',
        UPLOAD_ERR_FORM_SIZE => 'The backup file is larger than the allowed restore limit.',
        UPLOAD_ERR_PARTIAL => 'The backup file was only partially uploaded.',
        UPLOAD_ERR_NO_FILE => 'Choose a backup ZIP file to restore.',
    ];

    throw new CommandBridgeBackupException($messages[$upload['error']] ?? 'The backup file could not be uploaded.');
}

function command_bridge_backup_safe_relative_path(string $path): bool
{
    if ($path === '' || str_contains($path, "\0") || str_starts_with($path, '/') || str_contains($path, '\\')) {
        return false;
    }

    foreach (explode('/', $path) as $part) {
        if ($part === '' || $part === '.' || $part === '..') {
            return false;
        }
    }

    return true;
}

function command_bridge_backup_read_manifest(ZipArchive $zip): array
{
    $raw = $zip->getFromName('manifest.json');
    if ($raw === false) {
        throw new CommandBridgeBackupException('The selected ZIP is not a Command Bridge backup.');
    }

    try {
        $manifest = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $exception) {
        throw new CommandBridgeBackupException('The backup metadata is invalid.', 0, $exception);
    }

    if (!is_array($manifest)
        || ($manifest['format'] ?? null) !== COMMAND_BRIDGE_BACKUP_FORMAT
        || (int) ($manifest['format_version'] ?? 0) !== COMMAND_BRIDGE_BACKUP_FORMAT_VERSION
        || !is_array($manifest['tables'] ?? null)
        || !is_array($manifest['file_roots'] ?? null)
    ) {
        throw new CommandBridgeBackupException('The selected ZIP is not a supported Command Bridge backup.');
    }

    $roots = array_values($manifest['file_roots']);
    sort($roots, SORT_STRING);
    $expectedRoots = COMMAND_BRIDGE_BACKUP_FILE_ROOTS;
    sort($expectedRoots, SORT_STRING);

    if ($roots !== $expectedRoots) {
        throw new CommandBridgeBackupException('The backup has an unsupported application-file layout.');
    }

    return $manifest;
}

function command_bridge_backup_validate_restore(ZipArchive $zip, array $manifest): array
{
    $statTotal = 0;
    for ($index = 0; $index < $zip->numFiles; $index++) {
        $stat = $zip->statIndex($index);
        if ($stat === false) {
            throw new CommandBridgeBackupException('The backup archive could not be inspected.');
        }
        $statTotal += (int) ($stat['size'] ?? 0);
        if ($statTotal > COMMAND_BRIDGE_BACKUP_MAX_BYTES) {
            throw new CommandBridgeBackupException('The backup is too large to restore through the web application.');
        }
    }

    $currentTables = command_bridge_backup_tables();
    $backupTables = [];

    foreach ($manifest['tables'] as $table) {
        if (!is_array($table)
            || !is_string($table['name'] ?? null)
            || !is_array($table['columns'] ?? null)
            || !is_string($table['data_file'] ?? null)
            || !is_int($table['rows'] ?? null)
        ) {
            throw new CommandBridgeBackupException('The backup database metadata is invalid.');
        }

        $name = $table['name'];
        command_bridge_backup_quote_identifier($name);

        if ($table['data_file'] !== 'database/' . $name . '.json' || $zip->locateName($table['data_file']) === false) {
            throw new CommandBridgeBackupException('The backup is missing data for one or more database tables.');
        }

        $columns = array_values($table['columns']);
        if ($columns === [] || count($columns) !== count(array_unique($columns))) {
            throw new CommandBridgeBackupException('The backup contains invalid database column metadata.');
        }
        foreach ($columns as $column) {
            if (!is_string($column)) {
                throw new CommandBridgeBackupException('The backup contains invalid database column metadata.');
            }
            command_bridge_backup_quote_identifier($column);
        }

        if (isset($backupTables[$name])) {
            throw new CommandBridgeBackupException('The backup contains duplicate database tables.');
        }

        $currentColumns = command_bridge_backup_columns($name);
        if ($columns !== $currentColumns) {
            throw new CommandBridgeBackupException('The backup database structure does not match this Command Bridge installation.');
        }

        $backupTables[$name] = $table;
    }

    $backupTableNames = array_keys($backupTables);
    sort($backupTableNames, SORT_STRING);

    if ($backupTableNames !== $currentTables) {
        throw new CommandBridgeBackupException('The backup database table list does not match this Command Bridge installation.');
    }

    return $backupTables;
}

function command_bridge_backup_temp_directory(string $prefix): string
{
    $base = rtrim((string) sys_get_temp_dir(), DIRECTORY_SEPARATOR);
    if ($base === '' || !is_dir($base) || !is_writable($base)) {
        throw new CommandBridgeBackupException('The server temporary directory is not available for restore.');
    }

    try {
        $path = $base . DIRECTORY_SEPARATOR . $prefix . bin2hex(random_bytes(12));
    } catch (Throwable $exception) {
        throw new CommandBridgeBackupException('A secure temporary restore location could not be created.', 0, $exception);
    }

    if (!mkdir($path, 0750, true) && !is_dir($path)) {
        throw new CommandBridgeBackupException('A temporary restore location could not be created.');
    }

    return $path;
}

function command_bridge_backup_stage_files(ZipArchive $zip): string
{
    /*
     * Use PHP's temporary directory rather than the storage root. On a hardened
     * Apache deployment the storage root itself may not allow new top-level
     * directories even though the existing uploads and branding folders are writable.
     */
    $stage = command_bridge_backup_temp_directory('command_bridge_restore_stage_');

    try {
        foreach (COMMAND_BRIDGE_BACKUP_FILE_ROOTS as $root) {
            $rootPath = $stage . DIRECTORY_SEPARATOR . $root;
            if (!mkdir($rootPath, 0750, true) && !is_dir($rootPath)) {
                throw new CommandBridgeBackupException('The restore staging location could not be prepared.');
            }
        }

        $fileBytes = 0;
        for ($index = 0; $index < $zip->numFiles; $index++) {
            $stat = $zip->statIndex($index);
            if ($stat === false || !isset($stat['name'])) {
                throw new CommandBridgeBackupException('The backup archive could not be read.');
            }

            $name = (string) $stat['name'];
            if (!str_starts_with($name, 'files/')) {
                continue;
            }

            if (!preg_match('#^files/(uploads|branding)/(.+)$#', $name, $matches)
                || !command_bridge_backup_safe_relative_path($matches[2])) {
                throw new CommandBridgeBackupException('The backup contains an unsafe application-file path.');
            }

            $fileBytes += (int) ($stat['size'] ?? 0);
            if ($fileBytes > COMMAND_BRIDGE_BACKUP_MAX_BYTES) {
                throw new CommandBridgeBackupException('The backup contains too much application-file data to restore.');
            }

            $destination = $stage . DIRECTORY_SEPARATOR . $matches[1] . DIRECTORY_SEPARATOR
                . str_replace('/', DIRECTORY_SEPARATOR, $matches[2]);
            $destinationDirectory = dirname($destination);

            if (!is_dir($destinationDirectory) && !mkdir($destinationDirectory, 0750, true) && !is_dir($destinationDirectory)) {
                throw new CommandBridgeBackupException('A restore directory could not be created.');
            }

            $input = $zip->getStream($name);
            $output = fopen($destination, 'wb');
            if ($input === false || $output === false) {
                if (is_resource($input)) {
                    fclose($input);
                }
                if (is_resource($output)) {
                    fclose($output);
                }
                throw new CommandBridgeBackupException('A backup application file could not be extracted.');
            }

            try {
                while (!feof($input)) {
                    $chunk = fread($input, 8192);
                    if ($chunk === false || ($chunk !== '' && fwrite($output, $chunk) === false)) {
                        throw new CommandBridgeBackupException('A backup application file could not be restored.');
                    }
                }
            } finally {
                fclose($input);
                fclose($output);
            }
            @chmod($destination, 0640);
        }

        return $stage;
    } catch (Throwable $exception) {
        command_bridge_backup_delete_tree($stage);
        if ($exception instanceof CommandBridgeBackupException) {
            throw $exception;
        }
        throw new CommandBridgeBackupException('Application files could not be prepared for restore.', 0, $exception);
    }
}

function command_bridge_backup_copy_tree(string $source, string $destination): void
{
    if (!is_dir($source)) {
        throw new CommandBridgeBackupException('A required application storage folder is unavailable.');
    }
    if (!is_dir($destination) && !mkdir($destination, 0750, true) && !is_dir($destination)) {
        throw new CommandBridgeBackupException('An application storage folder could not be prepared.');
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );

    foreach ($iterator as $item) {
        if ($item->isLink()) {
            continue;
        }
        $relative = substr($item->getPathname(), strlen($source) + 1);
        $target = $destination . DIRECTORY_SEPARATOR . $relative;

        if ($item->isDir()) {
            if (!is_dir($target) && !mkdir($target, 0750, true) && !is_dir($target)) {
                throw new CommandBridgeBackupException('A restore directory could not be created.');
            }
            continue;
        }

        $parent = dirname($target);
        if (!is_dir($parent) && !mkdir($parent, 0750, true) && !is_dir($parent)) {
            throw new CommandBridgeBackupException('A restore directory could not be created.');
        }
        if (!copy($item->getPathname(), $target)) {
            throw new CommandBridgeBackupException('An application file could not be restored.');
        }
        @chmod($target, 0640);
    }
}

function command_bridge_backup_clear_directory(string $directory): void
{
    if (!is_dir($directory)) {
        throw new CommandBridgeBackupException('A required application storage folder is unavailable.');
    }

    $items = scandir($directory);
    if ($items === false) {
        throw new CommandBridgeBackupException('An application storage folder could not be read.');
    }

    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $path = $directory . DIRECTORY_SEPARATOR . $item;
        if (is_dir($path) && !is_link($path)) {
            command_bridge_backup_delete_tree($path);
        } elseif (!@unlink($path)) {
            throw new CommandBridgeBackupException('Existing application files could not be replaced.');
        }
    }
}

function command_bridge_backup_replace_files(string $stage): void
{
    $storageRoot = storage_root_path();
    $rollback = command_bridge_backup_temp_directory('command_bridge_restore_rollback_');
    $replaced = [];

    try {
        foreach (COMMAND_BRIDGE_BACKUP_FILE_ROOTS as $root) {
            $target = $storageRoot . DIRECTORY_SEPARATOR . $root;
            $replacement = $stage . DIRECTORY_SEPARATOR . $root;
            $previous = $rollback . DIRECTORY_SEPARATOR . $root;

            if (!is_dir($target) || !is_writable($target)) {
                throw new CommandBridgeBackupException('The ' . $root . ' storage folder is not writable by the web server.');
            }
            if (!is_dir($replacement)) {
                throw new CommandBridgeBackupException('The backup is missing required application-file data.');
            }

            command_bridge_backup_copy_tree($target, $previous);
            command_bridge_backup_clear_directory($target);
            $replaced[] = [$target, $previous];
            command_bridge_backup_copy_tree($replacement, $target);
        }
    } catch (Throwable $exception) {
        foreach (array_reverse($replaced) as [$target, $previous]) {
            try {
                command_bridge_backup_clear_directory($target);
                command_bridge_backup_copy_tree($previous, $target);
            } catch (Throwable) {
                // Preserve the original failure for the server error log.
            }
        }

        if ($exception instanceof CommandBridgeBackupException) {
            throw $exception;
        }
        throw new CommandBridgeBackupException('Application files could not be restored.', 0, $exception);
    } finally {
        command_bridge_backup_delete_tree($stage);
        command_bridge_backup_delete_tree($rollback);
    }
}

function command_bridge_backup_restore(array $upload): array
{
    command_bridge_backup_upload_error($upload);

    $temporaryName = (string) ($upload['tmp_name'] ?? '');
    $size = (int) ($upload['size'] ?? 0);

    if ($temporaryName === '' || !is_uploaded_file($temporaryName)) {
        throw new CommandBridgeBackupException('The uploaded backup file is unavailable.');
    }
    if ($size <= 0 || $size > COMMAND_BRIDGE_BACKUP_MAX_BYTES) {
        throw new CommandBridgeBackupException('The backup file is too large or empty.');
    }
    if (!class_exists('ZipArchive')) {
        throw new CommandBridgeBackupException('The PHP ZipArchive extension is required for backup and restore.');
    }

    $zip = new ZipArchive();
    if ($zip->open($temporaryName, ZipArchive::RDONLY) !== true) {
        throw new CommandBridgeBackupException('The uploaded file is not a readable ZIP archive.');
    }

    $stage = null;
    try {
        $manifest = command_bridge_backup_read_manifest($zip);
        $tables = command_bridge_backup_validate_restore($zip, $manifest);
        $stage = command_bridge_backup_stage_files($zip);

        $pdo = db();
        $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
        $pdo->beginTransaction();

        try {
            foreach (array_reverse(array_keys($tables)) as $table) {
                $pdo->exec('DELETE FROM ' . command_bridge_backup_quote_identifier($table));
            }

            foreach ($tables as $tableName => $table) {
                $raw = $zip->getFromName($table['data_file']);
                if ($raw === false) {
                    throw new CommandBridgeBackupException('The backup is missing database data.');
                }

                try {
                    $rows = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
                } catch (JsonException $exception) {
                    throw new CommandBridgeBackupException('The backup contains invalid database data.', 0, $exception);
                }

                if (!is_array($rows) || count($rows) !== $table['rows']) {
                    throw new CommandBridgeBackupException('The backup database row count is invalid.');
                }

                $quotedColumns = array_map('command_bridge_backup_quote_identifier', $table['columns']);
                $sql = 'INSERT INTO ' . command_bridge_backup_quote_identifier($tableName)
                    . ' (' . implode(', ', $quotedColumns) . ') VALUES ('
                    . implode(', ', array_fill(0, count($quotedColumns), '?')) . ')';
                $insert = $pdo->prepare($sql);

                foreach ($rows as $row) {
                    if (!is_array($row) || array_keys($row) !== $table['columns']) {
                        throw new CommandBridgeBackupException('The backup contains invalid database row data.');
                    }

                    $values = [];
                    foreach ($table['columns'] as $column) {
                        $value = $row[$column];
                        if (!is_null($value) && !is_scalar($value)) {
                            throw new CommandBridgeBackupException('The backup contains invalid database values.');
                        }
                        $values[] = $value;
                    }
                    $insert->execute($values);
                }
            }

            $pdo->commit();
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        } finally {
            $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
        }

        command_bridge_backup_replace_files($stage);
        $stage = null;

        return [
            'tables' => count($tables),
            'files' => (int) ($manifest['file_count'] ?? 0),
        ];
    } catch (Throwable $exception) {
        if ($stage !== null) {
            command_bridge_backup_delete_tree($stage);
        }
        if ($exception instanceof CommandBridgeBackupException) {
            throw $exception;
        }
        throw new CommandBridgeBackupException('The backup could not be restored.', 0, $exception);
    } finally {
        $zip->close();
    }
}
