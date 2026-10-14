<?php

namespace App\Console\Commands;

use App\Console\SystemBackup;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Number;
use Throwable;

#[Signature('system:backup')]
#[Description('Create and verify an encrypted backup of the database and private documents')]
class CreateSystemBackup extends Command
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
        try {
            $result = $this->backups->create();
        } catch (Throwable $exception) {
            report($exception);
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info('System backup created and verified.');
        $this->line('Archive: '.$result['path']);
        $this->line('Contents: '.$result['files'].' files');
        $this->line('Size: '.Number::fileSize($result['bytes']));
        $this->line('Encryption: '.($result['encrypted'] ? 'AES-256' : 'disabled'));

        if ($result['pruned'] > 0) {
            $this->line("Expired backups removed: {$result['pruned']}");
        }

        return self::SUCCESS;
    }
}
