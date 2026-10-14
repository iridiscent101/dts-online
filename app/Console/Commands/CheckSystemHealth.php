<?php

namespace App\Console\Commands;

use App\Console\SystemHealth;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('system:health {--json : Return the health report as JSON}')]
#[Description('Check database, scheduler, queue, backup, storage, and production configuration health')]
class CheckSystemHealth extends Command
{
    public function __construct(private readonly SystemHealth $health)
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $report = $this->health->report();

        if ($this->option('json')) {
            $this->line((string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->info('System health: '.str($report['overall'])->headline());
            $this->table(
                ['Check', 'Status', 'Summary', 'Details'],
                array_map(
                    fn (array $check): array => [
                        $check['label'],
                        str($check['status'])->headline()->toString(),
                        $check['summary'],
                        $check['details'],
                    ],
                    $report['checks'],
                ),
            );
        }

        return $report['overall'] === SystemHealth::CRITICAL ? self::FAILURE : self::SUCCESS;
    }
}
