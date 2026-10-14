<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

#[Signature('notifications:prune-read')]
#[Description('Delete notifications more than 90 days after they were read')]
class PruneReadNotifications extends Command
{
    public function handle(): int
    {
        $deleted = DB::table('notifications')
            ->whereNotNull('read_at')
            ->where('read_at', '<', now()->subDays(90))
            ->delete();

        $this->info("Deleted {$deleted} expired read notification(s).");

        return self::SUCCESS;
    }
}
