<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class RegistryFiltersTest extends TestCase
{
    use LazilyRefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(now('Asia/Manila')->setDate(2026, 10, 14)->setTime(10, 0));
        $this->user = User::factory()->create([
            'office' => 'Records Unit',
            'permissions' => ['platform.index' => true, 'platform.documents.view-all' => true],
        ]);
    }

    public function test_search_matches_sender_origin_and_external_reference(): void
    {
        $this->document('DTS-SENDER', ['sender' => 'Maria Santos']);
        $this->document('DTS-ORIGIN', ['origin' => 'Bayombong Central School']);
        $this->document('DTS-REFERENCE', ['external_reference' => 'MEMO-2026-118']);
        $this->document('DTS-OTHER', ['sender' => 'Someone Else', 'origin' => 'Other School']);

        $this->registry(['search' => 'Maria'])->assertSee('DTS-SENDER')->assertDontSee('DTS-OTHER');
        $this->registry(['search' => 'Bayombong'])->assertSee('DTS-ORIGIN')->assertDontSee('DTS-OTHER');
        $this->registry(['search' => 'MEMO-2026'])->assertSee('DTS-REFERENCE')->assertDontSee('DTS-OTHER');
    }

    public function test_due_filter_lists_overdue_and_due_soon_documents_that_are_not_finished(): void
    {
        $this->document('DTS-OVERDUE', ['due_at' => '2026-10-13']);
        $this->document('DTS-DONE-LATE', ['due_at' => '2026-10-10', 'status' => 'Completed']);
        $this->document('DTS-DUE-TODAY', ['due_at' => '2026-10-14']);
        $this->document('DTS-DUE-IN-2', ['due_at' => '2026-10-16']);
        $this->document('DTS-DUE-LATER', ['due_at' => '2026-10-17']);

        $this->registry(['due' => 'overdue'])
            ->assertSee('DTS-OVERDUE')
            ->assertDontSee('DTS-DONE-LATE')
            ->assertDontSee('DTS-DUE-TODAY')
            ->assertSee('1 filter active');

        $this->registry(['due' => 'soon'])
            ->assertSee('DTS-DUE-TODAY')
            ->assertSee('DTS-DUE-IN-2')
            ->assertDontSee('DTS-OVERDUE')
            ->assertDontSee('DTS-DUE-LATER');

        $this->registry(['due' => 'anything'])->assertSee('DTS-DUE-LATER')->assertSee('No filters applied');
    }

    public function test_priority_filter_and_active_filter_count(): void
    {
        $this->document('DTS-URGENT', ['priority' => 'Urgent']);
        $this->document('DTS-STANDARD', ['priority' => 'Standard']);

        $this->registry(['priority' => 'Urgent', 'from' => '2026-10-01', 'to' => '2026-10-14'])
            ->assertSee('DTS-URGENT')
            ->assertDontSee('DTS-STANDARD')
            ->assertSee('<option value="Urgent" selected', false)
            ->assertSee('2 filters active');

        $this->registry(['priority' => 'Critical'])->assertSee('DTS-STANDARD')->assertSee('DTS-URGENT');
    }

    private function document(string $trackingNumber, array $attributes = []): Document
    {
        return Document::factory()->create([
            'tracking_number' => $trackingNumber,
            'current_office' => 'Records Unit',
            'status' => 'Received',
            'received_at' => '2026-10-05',
            ...$attributes,
        ]);
    }

    private function registry(array $query): TestResponse
    {
        return $this->actingAs($this->user)->get(route('platform.documents', $query))->assertOk();
    }
}
