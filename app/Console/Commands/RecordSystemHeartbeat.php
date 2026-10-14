<?php

namespace App\Console\Commands;

use App\Console\SystemHealth;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('system:heartbeat')]
#[Description('Record a scheduler heartbeat for operational health monitoring')]
class RecordSystemHeartbeat extends Command
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
        $this->health->recordSchedulerHeartbeat();
        $this->info('Scheduler heartbeat recorded.');

        return self::SUCCESS;
    }
}
