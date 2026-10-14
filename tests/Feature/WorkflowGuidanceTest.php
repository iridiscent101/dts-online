<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\DocumentMovement;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class WorkflowGuidanceTest extends TestCase
{
    use LazilyRefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create([
            'office' => 'Records Unit',
            'permissions' => ['platform.index' => true, 'platform.documents.edit' => true],
        ]);
    }

    public function test_actions_are_short_verbs(): void
    {
        $this->assertSame('Forward', DocumentMovement::actionOptions()[DocumentMovement::ACTION_FORWARDED]);
        $this->assertSame('Complete', DocumentMovement::actionOptions()[DocumentMovement::ACTION_COMPLETED]);
    }

    public function test_document_panel_points_an_office_that_can_act_to_the_routing_form(): void
    {
        $document = Document::factory()->received()->create(['current_office' => 'Records Unit']);

        $this->actingAs($this->user)
            ->get(route('platform.documents.workspace', $document))
            ->assertOk()
            ->assertSee('Your office can act on this document.')
            ->assertSee('data-workspace-take-action', false)
            ->assertSee('<span class="dts-tab-badge">Action needed</span>', false)
            ->assertSee('Fields marked * are required.');
    }

    public function test_dashboard_queue_says_why_each_document_is_waiting(): void
    {
        Document::factory()->create([
            'tracking_number' => 'DTS-QUEUE-1',
            'current_office' => 'Records Unit',
            'status' => DocumentMovement::STATUS_FORWARDED,
        ]);

        $this->actingAs($this->user)
            ->get(route('platform.main'))
            ->assertOk()
            ->assertSee('DTS-QUEUE-1')
            ->assertSee('Waiting to be received');
    }

    public function test_registry_has_a_status_guide(): void
    {
        $this->actingAs($this->user)
            ->get(route('platform.documents'))
            ->assertOk()
            ->assertSee('Status guide')
            ->assertSee('Waiting for their paper copy at the Records Unit.');
    }

    public function test_client_sees_what_to_do_next_while_the_paper_copy_is_pending(): void
    {
        Document::factory()->create([
            'tracking_number' => 'DTS-PAPER-NEXT',
            'client_submission_key' => (string) Str::uuid(),
            'status' => DocumentMovement::STATUS_AWAITING_PHYSICAL_DOCUMENT,
            'current_office' => 'SDO-Records Unit',
            'received_at' => null,
            'due_at' => null,
        ]);
        Document::factory()->received()->create(['tracking_number' => 'DTS-MOVING']);

        $this->get(route('tracking.index', ['tracking' => 'DTS-PAPER-NEXT']))
            ->assertOk()
            ->assertSee('What happens next')
            ->assertSee('Bring the paper copy');

        $this->get(route('tracking.index', ['tracking' => 'DTS-MOVING']))
            ->assertOk()
            ->assertDontSee('What happens next');

        $this->get(route('submission.create'))->assertSee('are required.');
    }
}
