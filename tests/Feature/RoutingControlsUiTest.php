<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\DocumentMovement;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Screens for Receive-first routing, Add remark, and Send to another office.
 */
class RoutingControlsUiTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_workspace_and_document_page_offer_add_remark_for_the_acting_office(): void
    {
        $user = User::factory()->create(['office' => 'Records Unit', 'permissions' => ['platform.index' => true]]);
        $document = Document::factory()->received()->create(['current_office' => 'Records Unit']);

        $this->actingAs($user)
            ->get(route('platform.documents.workspace', $document))
            ->assertOk()
            ->assertSee('data-office-remark', false)
            ->assertSee(route('platform.documents.remarks.store', $document), false)
            ->assertSee('Add remark');

        $this->get(route('platform.documents.view', $document))
            ->assertOk()
            ->assertSee('id="document-office-remark-panel"', false);
    }

    public function test_sending_office_sees_send_to_another_office_in_outgoing(): void
    {
        $sender = User::factory()->create(['office' => 'Administrative Unit', 'permissions' => ['platform.index' => true]]);
        $document = $this->forwardedDocument();

        $this->actingAs($sender)
            ->get(route('platform.outgoing'))
            ->assertOk()
            ->assertSee($document->tracking_number)
            ->assertSee('Send '.$document->tracking_number.' to another office')
            ->assertSee('data-additional-route=', false)
            ->assertSee(str_replace('/', '\/', route('platform.documents.additional-routes.store', $document)), false)
            ->assertSee('id="additional-route-offices"', false);

        // The same document in the full registry has no such button: it belongs to the Outgoing list.
        $this->get(route('platform.documents'))
            ->assertOk()
            ->assertDontSee('to another office');
    }

    public function test_outgoing_list_runs_no_distinct_query_with_an_order_by(): void
    {
        // MySQL (the online database) rejects DISTINCT with ORDER BY on an unselected column; SQLite allows it, so
        // the query text is checked here instead.
        $sender = User::factory()->create(['office' => 'Administrative Unit', 'permissions' => ['platform.index' => true]]);
        $this->forwardedDocument();
        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = strtolower($query->sql);
        });

        $this->actingAs($sender)->get(route('platform.outgoing'))->assertOk();

        $this->assertEmpty(array_filter($queries, fn (string $sql): bool => str_contains($sql, 'select distinct') && str_contains($sql, 'order by')));
    }

    public function test_finished_documents_cannot_get_another_office(): void
    {
        $sender = User::factory()->create(['office' => 'Administrative Unit', 'permissions' => ['platform.index' => true]]);
        $document = $this->forwardedDocument();
        $document->officeAssignments()->update(['status' => DocumentMovement::STATUS_COMPLETED]);

        $this->actingAs($sender)
            ->get(route('platform.outgoing'))
            ->assertOk()
            ->assertDontSee('to another office');
    }

    public function test_profile_does_not_offer_a_signature_upload(): void
    {
        $user = User::factory()->create(['permissions' => ['platform.index' => true]]);

        $this->actingAs($user)
            ->get(route('platform.profile'))
            ->assertOk()
            ->assertSee('Account access')
            ->assertDontSee('Signature on labels')
            ->assertDontSee('data-profile-signature', false);
    }

    public function test_former_signature_endpoints_are_unavailable(): void
    {
        $user = User::factory()->create(['permissions' => ['platform.index' => true]]);

        $this->actingAs($user)
            ->get('/admin/profile/signature')
            ->assertNotFound();

        $this->post('/admin/profile/signature', ['signature' => 'unused'])
            ->assertNotFound();
    }

    private function forwardedDocument(): Document
    {
        $document = Document::factory()->create([
            'current_office' => 'SDO-Budget Unit',
            'status' => DocumentMovement::STATUS_FORWARDED,
        ]);
        $document->movements()->create([
            'action' => DocumentMovement::ACTION_FORWARDED,
            'from_office' => 'Administrative Unit',
            'to_office' => 'SDO-Budget Unit',
            'from_status' => DocumentMovement::STATUS_RECEIVED,
            'to_status' => DocumentMovement::STATUS_FORWARDED,
        ]);

        return $document;
    }
}
