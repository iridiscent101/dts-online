<?php

namespace Tests\Feature;

use App\Auth\AccountActivity;
use App\Models\Document;
use App\Models\User;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class SignInActivityRetentionTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_only_sign_in_entries_older_than_three_months_are_deleted(): void
    {
        $this->travelTo(Carbon::parse('2026-10-22 12:00:00'));
        $user = User::factory()->create();
        $document = Document::factory()->create();

        $this->travelTo(Carbon::parse('2026-07-21 12:00:00'));
        AccountActivity::record(AccountActivity::SIGNED_IN, $user, $user);
        $old = Activity::query()->latest('id')->value('id');
        $document->recordActivity('Remark added', $user, ['office' => 'Records Unit', 'remark' => 'Old document history']);
        $oldDocumentHistory = Activity::query()->latest('id')->value('id');

        $this->travelTo(Carbon::parse('2026-07-23 12:00:00'));
        AccountActivity::record(AccountActivity::SIGN_IN_FAILED, $user, null);
        $recent = Activity::query()->latest('id')->value('id');

        $this->travelTo(Carbon::parse('2026-10-22 12:00:00'));
        $this->artisan('accounts:prune-activity')
            ->expectsOutput('Deleted 1 sign-in activity entry older than 3 months.')
            ->assertSuccessful();

        $this->assertDatabaseMissing('activity_log', ['id' => $old]);
        $this->assertDatabaseHas('activity_log', ['id' => $recent]);
        // Document history is never pruned.
        $this->assertDatabaseHas('activity_log', ['id' => $oldDocumentHistory]);
    }

    public function test_pruning_is_scheduled_daily(): void
    {
        $scheduled = collect(app(Schedule::class)->events())
            ->contains(fn (Event $event): bool => str_contains($event->command ?? '', 'accounts:prune-activity') && $event->expression === '15 2 * * *');

        $this->assertTrue($scheduled);
    }
}
