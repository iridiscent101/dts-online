<?php

namespace Tests\Feature;

use App\Console\SystemBackup;
use App\Exceptions\SystemBackupException;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Tests\TestCase;

class SystemBackupTest extends TestCase
{
    public function test_it_creates_encrypts_verifies_and_prunes_system_backups(): void
    {
        $workspace = storage_path('framework/testing/system-backup-'.Str::lower(Str::random(8)));
        $backupPath = $workspace.DIRECTORY_SEPARATOR.'backups';
        $privatePath = $workspace.DIRECTORY_SEPARATOR.'private';
        $databasePath = $workspace.DIRECTORY_SEPARATOR.'database.sqlite';

        try {
            CarbonImmutable::setTestNow('2026-09-30 14:32:08 UTC');
            File::ensureDirectoryExists($backupPath);
            File::ensureDirectoryExists($privatePath.DIRECTORY_SEPARATOR.'documents');
            File::put($databasePath, 'sqlite-backup-fixture');
            File::put($privatePath.DIRECTORY_SEPARATOR.'documents'.DIRECTORY_SEPARATOR.'memorandum.pdf', '%PDF-1.4 fixture');

            $expiredDirectory = $backupPath.DIRECTORY_SEPARATOR.'backup_01-01-01_2026-01-01';
            $expiredArchive = $expiredDirectory.DIRECTORY_SEPARATOR.'dts-backup.zip';
            File::ensureDirectoryExists($expiredDirectory);
            File::put($expiredArchive, 'expired');
            touch($expiredArchive, now()->subDays(31)->getTimestamp());

            config([
                'backup.path' => $backupPath,
                'backup.private_path' => $privatePath,
                'backup.password' => 'test-backup-password',
                'backup.retention_days' => 30,
                'database.default' => 'sqlite',
                'database.connections.sqlite.database' => $databasePath,
            ]);

            $this->artisan('system:backup')
                ->expectsOutput('System backup created and verified.')
                ->expectsOutput('Encryption: AES-256')
                ->expectsOutput('Expired backups removed: 1')
                ->assertSuccessful();

            $archives = app(SystemBackup::class)->archives();
            $this->assertCount(1, $archives);
            $this->assertDirectoryDoesNotExist($expiredDirectory);
            $this->assertSame('dts-backup.zip', basename($archives[0]));
            $this->assertSame('backup_22-32-08_2026-09-30', basename(dirname($archives[0])));

            $verification = app(SystemBackup::class)->verify($archives[0]);
            $this->assertSame(2, $verification['files']);
            $this->assertTrue($verification['encrypted']);

            $this->artisan('system:backup:verify', ['archive' => $archives[0]])
                ->expectsOutput('Backup integrity verified.')
                ->assertSuccessful();
        } finally {
            CarbonImmutable::setTestNow();
            File::deleteDirectory($workspace);
        }
    }

    public function test_production_backups_require_an_encryption_password(): void
    {
        $originalEnvironment = $this->app->environment();
        $this->app->instance('env', 'production');
        config(['backup.password' => null]);

        try {
            $this->artisan('system:backup')
                ->expectsOutput('BACKUP_PASSWORD must be configured in production.')
                ->assertExitCode(Command::FAILURE);
        } finally {
            $this->app->instance('env', $originalEnvironment);
        }
    }

    public function test_backup_failures_use_a_domain_exception_with_context(): void
    {
        $originalEnvironment = $this->app->environment();
        $this->app->instance('env', 'production');
        config(['backup.password' => null]);

        try {
            app(SystemBackup::class)->create();

            $this->fail('Expected a system backup exception.');
        } catch (SystemBackupException $exception) {
            $this->assertSame('BACKUP_PASSWORD must be configured in production.', $exception->getMessage());
            $this->assertSame([
                'operation' => 'create',
                'environment' => 'production',
            ], $exception->context());
        } finally {
            $this->app->instance('env', $originalEnvironment);
        }
    }
}
