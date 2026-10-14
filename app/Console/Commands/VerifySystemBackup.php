<?php

namespace App\Console\Commands;

use App\Console\SystemBackup;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('system:backup:verify {archive : Absolute path to the backup ZIP archive}')]
#[Description('Verify the structure and SHA-256 integrity of a system backup')]
class VerifySystemBackup extends Command
{
    public function __construct(private readonly SystemBackup $backups)
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $archive = (string) $this->argument('archive');

        try {
            $result = $this->backups->verify($archive);
        } catch (Throwable $exception) {
            report($exception);
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info('Backup integrity verified.');
        $this->line("Created: {$result['created_at']}");
        $this->line("Contents: {$result['files']} files");
        $this->line('Encryption: '.($result['encrypted'] ? 'AES-256' : 'disabled'));

        return self::SUCCESS;
    }
}
