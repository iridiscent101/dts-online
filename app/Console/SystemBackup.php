<?php

namespace App\Console;

use App\Exceptions\SystemBackupException;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use JsonException;
use Symfony\Component\Finder\SplFileInfo;
use ZipArchive;

class SystemBackup
{
    /**
     * @return array{path: string, files: int, bytes: int, encrypted: bool, pruned: int}
     */
    public function create(): array
    {
        $password = (string) config('backup.password', '');

        if (app()->isProduction() && $password === '') {
            throw SystemBackupException::because('BACKUP_PASSWORD must be configured in production.', [
                'operation' => 'create',
                'environment' => app()->environment(),
            ]);
        }

        $backupRoot = (string) config('backup.path');
        File::ensureDirectoryExists($backupRoot);

        $temporaryDirectory = $backupRoot.DIRECTORY_SEPARATOR.'.tmp-'.Str::uuid();
        File::ensureDirectoryExists($temporaryDirectory);

        $backupDirectory = $this->uniqueBackupDirectory($backupRoot);
        $temporaryArchive = $temporaryDirectory.DIRECTORY_SEPARATOR.'backup.zip';
        $finalArchive = $backupDirectory.DIRECTORY_SEPARATOR.'dts-backup.zip';

        try {
            $databaseEntry = $this->dumpDatabase($temporaryDirectory);
            $entries = [$this->fileMetadata($databaseEntry['path'], $databaseEntry['archive_name'])];
            $privateFileCount = 0;

            foreach ($this->privateFiles() as $privateFile) {
                $relativePath = $this->relativePath((string) config('backup.private_path'), $privateFile->getPathname());
                $entries[] = $this->fileMetadata($privateFile->getPathname(), 'private/'.$relativePath);
                $privateFileCount++;
            }

            $manifest = [
                'schema_version' => 1,
                'application' => (string) config('app.name'),
                'created_at' => now()->toIso8601String(),
                'database_connection' => (string) config('database.default'),
                'encrypted' => $password !== '',
                'private_file_count' => $privateFileCount,
                'files' => array_map(
                    fn (array $entry): array => [
                        'path' => $entry['archive_name'],
                        'bytes' => $entry['bytes'],
                        'sha256' => $entry['sha256'],
                    ],
                    $entries,
                ),
            ];

            $this->writeArchive($temporaryArchive, $entries, $manifest, $password);
            $verification = $this->verify($temporaryArchive);

            File::ensureDirectoryExists($backupDirectory);
            File::move($temporaryArchive, $finalArchive);
            $pruned = $this->prune();

            return [
                'path' => $finalArchive,
                'files' => $verification['files'],
                'bytes' => File::size($finalArchive),
                'encrypted' => $verification['encrypted'],
                'pruned' => $pruned,
            ];
        } finally {
            File::deleteDirectory($temporaryDirectory);

            if (File::isDirectory($backupDirectory) && ! File::isFile($finalArchive)) {
                File::deleteDirectory($backupDirectory);
            }
        }
    }

    /**
     * @return array<int, string>
     */
    public function archives(): array
    {
        $backupRoot = (string) config('backup.path');

        if (! File::isDirectory($backupRoot)) {
            return [];
        }

        return array_values(array_map(
            fn (SplFileInfo $file): string => $file->getPathname(),
            array_filter(
                File::allFiles($backupRoot),
                fn (SplFileInfo $file): bool => $file->getFilename() === 'dts-backup.zip'
                    || (str_starts_with($file->getFilename(), 'dts-backup-') && $file->getExtension() === 'zip'),
            ),
        ));
    }

    /**
     * @return array{files: int, encrypted: bool, created_at: string}
     */
    public function verify(string $archivePath): array
    {
        if (! File::isFile($archivePath)) {
            throw SystemBackupException::because("Backup archive not found: {$archivePath}", [
                'operation' => 'verify',
                'archive_path' => $archivePath,
            ]);
        }

        $archive = new ZipArchive;
        $openResult = $archive->open($archivePath, ZipArchive::CHECKCONS);

        if ($openResult !== true) {
            throw SystemBackupException::because("Backup archive is invalid or unreadable: {$archivePath}", [
                'operation' => 'verify',
                'archive_path' => $archivePath,
                'zip_open_result' => $openResult,
            ]);
        }

        try {
            $password = (string) config('backup.password', '');

            if ($password !== '') {
                $archive->setPassword($password);
            }

            $manifestJson = $archive->getFromName('manifest.json');

            if ($manifestJson === false) {
                throw SystemBackupException::because('The backup manifest is missing or the backup password is incorrect.', [
                    'operation' => 'verify',
                    'archive_path' => $archivePath,
                ]);
            }

            try {
                $manifest = json_decode($manifestJson, true, flags: JSON_THROW_ON_ERROR);
            } catch (JsonException $exception) {
                throw SystemBackupException::because('The backup manifest is not valid JSON.', [
                    'operation' => 'verify',
                    'archive_path' => $archivePath,
                ], previous: $exception);
            }

            if (($manifest['schema_version'] ?? null) !== 1 || ! is_array($manifest['files'] ?? null)) {
                throw SystemBackupException::because('The backup manifest has an unsupported format.', [
                    'operation' => 'verify',
                    'archive_path' => $archivePath,
                    'schema_version' => $manifest['schema_version'] ?? null,
                ]);
            }

            foreach ($manifest['files'] as $file) {
                if (! is_array($file) || ! isset($file['path'], $file['bytes'], $file['sha256'])) {
                    throw SystemBackupException::because('The backup manifest contains an invalid file entry.', [
                        'operation' => 'verify',
                        'archive_path' => $archivePath,
                    ]);
                }

                $actual = $this->archiveEntryMetadata($archive, (string) $file['path']);

                if ($actual['bytes'] !== (int) $file['bytes'] || ! hash_equals((string) $file['sha256'], $actual['sha256'])) {
                    throw SystemBackupException::because("Backup integrity check failed for {$file['path']}.", [
                        'operation' => 'verify',
                        'archive_path' => $archivePath,
                        'entry' => $file['path'],
                    ]);
                }
            }

            return [
                'files' => count($manifest['files']),
                'encrypted' => (bool) ($manifest['encrypted'] ?? false),
                'created_at' => (string) ($manifest['created_at'] ?? ''),
            ];
        } finally {
            $archive->close();
        }
    }

    public function prune(): int
    {
        $retentionDays = max(1, (int) config('backup.retention_days', 30));
        $expiresBefore = now()->subDays($retentionDays)->getTimestamp();
        $deleted = 0;

        foreach ($this->archives() as $archivePath) {
            if (File::lastModified($archivePath) >= $expiresBefore) {
                continue;
            }

            $backupDirectory = dirname($archivePath);
            $isTimestampedBackup = basename($archivePath) === 'dts-backup.zip'
                && str_starts_with(basename($backupDirectory), 'backup_');
            $removed = $isTimestampedBackup
                ? File::deleteDirectory($backupDirectory)
                : File::delete($archivePath);

            if ($removed) {
                $deleted++;
            }
        }

        return $deleted;
    }

    /**
     * @return array{path: string, archive_name: string}
     */
    private function dumpDatabase(string $temporaryDirectory): array
    {
        $connectionName = (string) config('database.default');
        $connection = config("database.connections.{$connectionName}");

        if (! is_array($connection)) {
            throw SystemBackupException::because("Database connection {$connectionName} is not configured.", [
                'operation' => 'dump_database',
                'connection' => $connectionName,
            ]);
        }

        $driver = $connection['driver'] ?? null;

        if ($driver === 'sqlite') {
            $source = (string) ($connection['database'] ?? '');

            if ($source === '' || $source === ':memory:' || ! File::isFile($source)) {
                throw SystemBackupException::because('SQLite backups require a file-based database.', [
                    'operation' => 'dump_database',
                    'connection' => $connectionName,
                    'database' => $source,
                ]);
            }

            $destination = $temporaryDirectory.DIRECTORY_SEPARATOR.'database.sqlite';
            File::copy($source, $destination);

            return ['path' => $destination, 'archive_name' => 'database.sqlite'];
        }

        if (! in_array($driver, ['mysql', 'mariadb'], true)) {
            throw SystemBackupException::because("Database driver {$driver} is not supported by the backup command.", [
                'operation' => 'dump_database',
                'connection' => $connectionName,
                'driver' => $driver,
            ]);
        }

        $destination = $temporaryDirectory.DIRECTORY_SEPARATOR.'database.sql';
        $command = [
            (string) config('backup.mysqldump_path', 'mysqldump'),
            '--single-transaction',
            '--quick',
            '--routines',
            '--events',
            '--triggers',
            '--no-tablespaces',
            '--host='.(string) ($connection['host'] ?? '127.0.0.1'),
            '--port='.(string) ($connection['port'] ?? '3306'),
            '--user='.(string) ($connection['username'] ?? ''),
            '--result-file='.$destination,
            (string) ($connection['database'] ?? ''),
        ];

        if (filled($connection['unix_socket'] ?? null)) {
            $command[] = '--socket='.(string) $connection['unix_socket'];
        }

        $result = Process::env([
            'MYSQL_PWD' => (string) ($connection['password'] ?? ''),
        ])->timeout((int) config('backup.database_timeout', 300))->run($command);

        if ($result->failed()) {
            throw SystemBackupException::because('Database dump failed: '.trim($result->errorOutput()), [
                'operation' => 'dump_database',
                'connection' => $connectionName,
                'driver' => $driver,
            ]);
        }

        if (! File::isFile($destination) || File::size($destination) === 0) {
            throw SystemBackupException::because('Database dump completed without producing a usable file.', [
                'operation' => 'dump_database',
                'connection' => $connectionName,
                'destination' => $destination,
            ]);
        }

        return ['path' => $destination, 'archive_name' => 'database.sql'];
    }

    /**
     * @return array<int, SplFileInfo>
     */
    private function privateFiles(): array
    {
        $privatePath = (string) config('backup.private_path');

        if (! File::isDirectory($privatePath)) {
            return [];
        }

        return array_values(array_filter(
            File::allFiles($privatePath),
            fn ($file): bool => $file->getFilename() !== '.gitignore',
        ));
    }

    /**
     * @return array{source: string, archive_name: string, bytes: int, sha256: string}
     */
    private function fileMetadata(string $source, string $archiveName): array
    {
        $hash = hash_file('sha256', $source);

        if ($hash === false) {
            throw SystemBackupException::because("Unable to hash backup source: {$source}", [
                'operation' => 'hash_file',
                'source' => $source,
            ]);
        }

        return [
            'source' => $source,
            'archive_name' => str_replace('\\', '/', $archiveName),
            'bytes' => File::size($source),
            'sha256' => $hash,
        ];
    }

    /**
     * @param  array<int, array{source: string, archive_name: string, bytes: int, sha256: string}>  $entries
     * @param  array<string, mixed>  $manifest
     */
    private function writeArchive(string $archivePath, array $entries, array $manifest, string $password): void
    {
        $archive = new ZipArchive;

        if ($archive->open($archivePath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw SystemBackupException::because("Unable to create backup archive: {$archivePath}", [
                'operation' => 'write_archive',
                'archive_path' => $archivePath,
            ]);
        }

        try {
            if ($password !== '') {
                $archive->setPassword($password);
            }

            foreach ($entries as $entry) {
                if (! $archive->addFile($entry['source'], $entry['archive_name'])) {
                    throw SystemBackupException::because("Unable to add {$entry['archive_name']} to the backup archive.", [
                        'operation' => 'write_archive',
                        'archive_path' => $archivePath,
                        'entry' => $entry['archive_name'],
                    ]);
                }

                $this->encryptEntry($archive, $entry['archive_name'], $password);
            }

            $manifestJson = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

            if (! $archive->addFromString('manifest.json', $manifestJson)) {
                throw SystemBackupException::because('Unable to add the manifest to the backup archive.', [
                    'operation' => 'write_archive',
                    'archive_path' => $archivePath,
                ]);
            }

            $this->encryptEntry($archive, 'manifest.json', $password);
        } finally {
            if (! $archive->close()) {
                throw SystemBackupException::because('Unable to finalize the backup archive.', [
                    'operation' => 'write_archive',
                    'archive_path' => $archivePath,
                ]);
            }
        }
    }

    private function encryptEntry(ZipArchive $archive, string $entryName, string $password): void
    {
        if ($password !== '' && ! $archive->setEncryptionName($entryName, ZipArchive::EM_AES_256)) {
            throw SystemBackupException::because("Unable to encrypt {$entryName} in the backup archive.", [
                'operation' => 'encrypt_archive_entry',
                'entry' => $entryName,
            ]);
        }
    }

    /**
     * @return array{bytes: int, sha256: string}
     */
    private function archiveEntryMetadata(ZipArchive $archive, string $entryName): array
    {
        $stream = $archive->getStream($entryName);

        if ($stream === false) {
            throw SystemBackupException::because("Backup entry is missing or unreadable: {$entryName}", [
                'operation' => 'read_archive_entry',
                'entry' => $entryName,
            ]);
        }

        $hash = hash_init('sha256');
        $bytes = 0;

        try {
            while (! feof($stream)) {
                $chunk = fread($stream, 1024 * 1024);

                if ($chunk === false) {
                    throw SystemBackupException::because("Unable to read backup entry: {$entryName}", [
                        'operation' => 'read_archive_entry',
                        'entry' => $entryName,
                    ]);
                }

                $bytes += strlen($chunk);
                hash_update($hash, $chunk);
            }
        } finally {
            fclose($stream);
        }

        return ['bytes' => $bytes, 'sha256' => hash_final($hash)];
    }

    private function relativePath(string $root, string $path): string
    {
        $normalizedRoot = rtrim(str_replace('\\', '/', $root), '/').'/';
        $normalizedPath = str_replace('\\', '/', $path);

        if (! str_starts_with($normalizedPath, $normalizedRoot)) {
            throw SystemBackupException::because("Private file is outside the configured storage path: {$path}", [
                'operation' => 'resolve_private_file',
                'root' => $root,
                'path' => $path,
            ]);
        }

        return Str::after($normalizedPath, $normalizedRoot);
    }

    private function uniqueBackupDirectory(string $backupRoot): string
    {
        $baseName = 'backup_'.now()->timezone('Asia/Manila')->format('H-i-s_Y-m-d');
        $directory = $backupRoot.DIRECTORY_SEPARATOR.$baseName;
        $suffix = 2;

        while (File::exists($directory)) {
            $directory = $backupRoot.DIRECTORY_SEPARATOR.$baseName.'_'.($suffix++);
        }

        return $directory;
    }
}
