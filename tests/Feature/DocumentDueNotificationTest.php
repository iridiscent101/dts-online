<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class DocumentDueNotificationTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_due_command_notifies_the_assigned_office_once_for_each_due_state(): void
    {
        $this->travelTo(Carbon::parse('2026-09-28 08:00:00'));
        $recipient = User::factory()->create([
            'office' => 'Records Unit',
            'permissions' => ['platform.index' => true],
        ]);
        $otherOfficeUser = User::factory()->create(['office' => 'Administrative Unit']);
        $overdue = Document::factory()->received()->create([
            'tracking_number' => 'DTS-DUE-OVERDUE',
            'current_office' => 'Records Unit',
            'due_at' => today()->subDay(),
        ]);
        $dueSoon = Document::factory()->incoming()->create([
            'tracking_number' => 'DTS-DUE-SOON',
            'current_office' => 'Records Unit',
            'due_at' => today()->addDays(2),
        ]);
        Document::factory()->received()->create([
            'current_office' => 'Records Unit',
            'due_at' => today()->addDays(3),
        ]);
        Document::factory()->archived()->create([
            'current_office' => 'Records Unit',
            'due_at' => today()->subDays(4),
        ]);

        $this->artisan('documents:notify-due')
            ->expectsOutput('Sent 2 document due notification(s).')
            ->assertSuccessful();

        $notifications = $recipient->notifications()->get();
        $this->assertCount(2, $notifications);
        $this->assertSame(
            ['due_soon', 'overdue'],
            $notifications->pluck('data.event')->sort()->values()->all(),
        );
        $this->assertSame(
            collect([$dueSoon->id, $overdue->id])->sort()->values()->all(),
            $notifications->pluck('data.document_id')->sort()->values()->all(),
        );
        $this->assertSame(0, $otherOfficeUser->notifications()->count());

        $this->artisan('documents:notify-due')
            ->expectsOutput('Sent 0 document due notification(s).')
            ->assertSuccessful();

        $this->assertSame(2, $recipient->notifications()->count());
    }

    public function test_due_command_uses_manila_date_before_utc_midnight(): void
    {
        $this->travelTo(Carbon::parse('2026-10-13 23:30:00', 'UTC'));
        $recipient = User::factory()->create(['office' => 'Records Unit']);
        Document::factory()->received()->create(['current_office' => 'Records Unit', 'due_at' => '2026-10-13']);
        Document::factory()->received()->create(['current_office' => 'Records Unit', 'due_at' => '2026-10-14']);
        Document::factory()->received()->create(['current_office' => 'Records Unit', 'due_at' => '2026-10-16']);
        Document::factory()->received()->create(['current_office' => 'Records Unit', 'due_at' => '2026-10-17']);

        $this->artisan('documents:notify-due')
            ->expectsOutput('Sent 3 document due notification(s).')
            ->assertSuccessful();

        $notifications = $recipient->notifications()->get();
        $this->assertCount(3, $notifications);
        $this->assertSame(1, $notifications->where('data.event', 'overdue')->count());
        $this->assertSame(1, $notifications->filter(fn ($notification): bool => str_contains($notification->data['message'], 'is due today.'))->count());
    }

    public function test_due_command_notifies_only_the_unfinished_office_when_another_route_is_complete(): void
    {
        $this->travelTo(Carbon::parse('2026-09-28 08:00:00'));
        $completedOfficeUser = User::factory()->create([
            'office' => 'Administrative Unit',
            'permissions' => ['platform.index' => true],
        ]);
        $activeOfficeUser = User::factory()->create(['office' => 'Legal Unit']);
        $document = Document::factory()->received()->create([
            'current_office' => 'Administrative Unit',
            'due_at' => today()->addDay(),
        ]);
        $document->officeAssignments()->create([
            'office' => 'Legal Unit',
            'status' => 'Forwarded',
        ]);
        $this->actingAs($completedOfficeUser)->post(route('platform.documents.movements.store', $document), [
            'workflow' => ['action' => 'completed', 'remarks' => 'Records work is complete.'],
        ])->assertRedirectToRoute('platform.documents.view', $document);

        $this->assertSame('Forwarded', $document->fresh()->status);

        $this->artisan('documents:notify-due')
            ->expectsOutput('Sent 1 document due notification(s).')
            ->assertSuccessful();

        $this->assertSame(0, $completedOfficeUser->notifications()->count());
        $this->assertSame($document->id, $activeOfficeUser->notifications()->sole()->data['document_id']);

        $this->artisan('documents:notify-due')
            ->expectsOutput('Sent 0 document due notification(s).')
            ->assertSuccessful();
    }

    public function test_due_command_notifies_offices_with_reviewed_and_on_hold_routes(): void
    {
        $this->travelTo(Carbon::parse('2026-09-28 08:00:00'));
        $reviewedOfficeUser = User::factory()->create(['office' => 'Records Unit']);
        $onHoldOfficeUser = User::factory()->create(['office' => 'Administrative Unit']);
        $document = Document::factory()->create([
            'current_office' => 'Records Unit',
            'status' => 'Reviewed',
            'due_at' => today()->addDay(),
        ]);
        $document->officeAssignments()->create(['office' => 'Administrative Unit', 'status' => 'On Hold']);

        $this->artisan('documents:notify-due')
            ->expectsOutput('Sent 2 document due notification(s).')
            ->assertSuccessful();

        $this->assertSame($document->id, $reviewedOfficeUser->notifications()->sole()->data['document_id']);
        $this->assertSame($document->id, $onHoldOfficeUser->notifications()->sole()->data['document_id']);
    }

    public function test_due_command_still_notifies_the_current_office_for_a_legacy_document_without_assignments(): void
    {
        $this->travelTo(Carbon::parse('2026-09-28 08:00:00'));
        $recipient = User::factory()->create(['office' => 'Records Unit']);
        $document = Document::factory()->incoming()->create([
            'current_office' => 'Records Unit',
            'due_at' => today()->addDay(),
        ]);
        $document->officeAssignments()->delete();

        $this->artisan('documents:notify-due')
            ->expectsOutput('Sent 1 document due notification(s).')
            ->assertSuccessful();

        $this->assertSame($document->id, $recipient->notifications()->sole()->data['document_id']);
    }

    public function test_dashboard_distinguishes_due_soon_and_overdue_work(): void
    {
        $this->travelTo(Carbon::parse('2026-09-28 08:00:00'));
        $user = User::factory()->create([
            'office' => 'Records Unit',
            'permissions' => ['platform.index' => true],
        ]);
        Document::factory()->received()->create([
            'current_office' => 'Records Unit',
            'due_at' => today()->subDay(),
        ]);
        Document::factory()->incoming()->create([
            'current_office' => 'Records Unit',
            'due_at' => today()->addDay(),
        ]);

        $this->actingAs($user)->get(route('platform.main'))
            ->assertOk()
            ->assertSeeInOrder(['<span>Overdue</span>', '<strong>1</strong>', '<span>Due within 2 days</span>', '<strong>1</strong>'], false);
    }

    public function test_dashboard_due_counts_use_the_manila_business_date(): void
    {
        $this->travelTo(Carbon::parse('2026-10-13 23:30:00', 'UTC'));
        $user = User::factory()->create(['office' => 'Records Unit', 'permissions' => ['platform.index' => true]]);
        Document::factory()->received()->create(['current_office' => 'Records Unit', 'due_at' => '2026-10-13']);
        Document::factory()->received()->create(['current_office' => 'Records Unit', 'due_at' => '2026-10-16']);
        Document::factory()->received()->create(['current_office' => 'Records Unit', 'due_at' => '2026-10-17']);

        $response = $this->actingAs($user)->get(route('platform.main'))->assertOk();
        preg_match_all('/<div class="dts-metric[^"]*" role="listitem" data-summary-card>\s*<div class="dts-metric-heading"><span>([^<]+)<\/span>.*?<strong>(\d+)<\/strong>/s', $response->getContent(), $cards, PREG_SET_ORDER);

        $this->assertSame(['Overdue' => '1', 'Due within 2 days' => '1'], collect($cards)->pluck(2, 1)->only(['Overdue', 'Due within 2 days'])->all());
    }
}
