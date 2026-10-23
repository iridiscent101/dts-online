<?php

namespace App\Console\Commands;

use App\Auth\AccountActivity;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Spatie\Activitylog\Models\Activity;

#[Signature('accounts:prune-activity')]
#[Description('Delete sign-in activity (the "accounts" log) older than three months')]
class PruneSignInActivity extends Command
{
    public const KEEP_MONTHS = 3;

    public function handle(): int
    {
        // Only the account security log; document history (other log names) is never touched.
        $deleted = Activity::query()
            ->where('log_name', AccountActivity::LOG_NAME)
            ->where('created_at', '<', now()->subMonths(self::KEEP_MONTHS))
            ->delete();

        $this->info("Deleted {$deleted} sign-in activity entr".($deleted === 1 ? 'y' : 'ies').' older than '.self::KEEP_MONTHS.' months.');

        return self::SUCCESS;
    }
}
