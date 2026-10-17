<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\User;
use App\Notifications\DocumentAssignedNotification;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * users.office was dropped; offices come only from memberships. SQLite reads an unknown double-quoted column as a
 * string, so a stale `where office in (…)` passes here but fails on MySQL. These checks catch it in the SQL instead.
 */
class UserOfficeMembershipQueryTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_client_submission_notifies_records_staff_through_memberships_only(): void
    {
        Notification::fake();
        $records = User::factory()->create(['office' => 'SDO-Records Unit', 'permissions' => ['platform.index' => true]]);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->post(route('submission.store'), [
            'submission_key' => (string) Str::uuid(),
            'name' => 'Juan Dela Cruz',
            'designation' => 'School Head',
            'office' => 'Bayombong Central School',
            'delivery_method' => 'Hand carry',
            'title' => 'Request for certificate',
            'type' => 'Request',
            'privacy_consent' => '1',
        ])->assertRedirect();
        $queries = collect(DB::getQueryLog())->pluck('query');
        DB::disableQueryLog();

        $this->assertSame(1, Document::query()->count());
        Notification::assertSentTo($records, DocumentAssignedNotification::class);
        $this->assertSame([], $queries
            ->filter(fn (string $query): bool => str_contains($query, 'from "users"') && (bool) preg_match('/[(\s]"office"\s+(in|=)/', $query))
            ->values()
            ->all());
    }
}
