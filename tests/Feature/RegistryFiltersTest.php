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

    public function test_obsolete_due_filter_does_not_filter_documents(): void
    {
        $this->document('DTS-FIRST');
        $this->document('DTS-SECOND');

        $this->registry(['due' => 'overdue'])
            ->assertSee('DTS-FIRST')
            ->assertSee('DTS-SECOND')
            ->assertSee('No filters applied');
    }

    public function test_priority_is_no_longer_a_filter(): void
    {
        $this->document('DTS-URGENT', ['priority' => 'Urgent']);
        $this->document('DTS-STANDARD', ['priority' => 'Standard']);

        // Old links with ?priority= still open the registry; the value is ignored and only the date range counts.
        $this->registry(['priority' => 'Urgent', 'from' => '2026-10-01', 'to' => '2026-10-14'])
            ->assertSee('DTS-URGENT')
            ->assertSee('DTS-STANDARD')
            ->assertDontSee('id="registry-priority"', false)
            ->assertSee('1 filter active')
            ->assertSee('Date registered')
            ->assertSee('Time registered')
            ->assertSee('data-column="registered-time"', false)
            ->assertDontSee('Last updated');
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
