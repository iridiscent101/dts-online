<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\DocumentMovement;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Orchid\Platform\Models\Role;
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

    public function test_dashboard_queue_offers_the_same_fixes_for_a_forwarded_document(): void
    {
        // An administrator's queue lists every office's routes, so the forwarded Budget route shows with its fixes.
        $adminRole = Role::create(['name' => 'Admin', 'slug' => User::ADMIN_ROLE_SLUG]);
        $admin = User::factory()->create(['office' => null]);
        $admin->replaceRoles([$adminRole->id]);
        $document = $this->forwardedDocument();
        // Link the Budget route to the forward that created it, as the routing service does.
        $document->officeAssignments()->updateOrCreate(
            ['office' => 'SDO-Budget Unit'],
            ['status' => DocumentMovement::STATUS_FORWARDED, 'movement_id' => $document->movements()->value('id')],
        );

        $this->actingAs($admin)
            ->get(route('platform.main'))
            ->assertOk()
            ->assertSee('Send '.$document->tracking_number.' to another office')
            ->assertSee('Correct the route of '.$document->tracking_number)
            ->assertSee('id="additional-route-offices"', false);

        // Receiving offices see no fixes for documents sent to them.
        $budget = User::factory()->create(['office' => 'SDO-Budget Unit', 'permissions' => ['platform.index' => true]]);
        $this->actingAs($budget)
            ->get(route('platform.main'))
            ->assertOk()
            ->assertDontSee('Correct the route of '.$document->tracking_number)
            ->assertDontSee('Send '.$document->tracking_number.' to another office');
    }

    public function test_each_office_route_card_names_who_received_it(): void
    {
        $adminRole = Role::create(['name' => 'Admin', 'slug' => User::ADMIN_ROLE_SLUG]);
        $admin = User::factory()->create(['office' => null]);
        $admin->replaceRoles([$adminRole->id]);
        $laura = User::factory()->create(['name' => 'Laura C. Bergonia', 'office' => 'SDO-Budget Unit', 'permissions' => ['platform.index' => true]]);
        $ramon = User::factory()->create(['name' => 'Ramon Dizon', 'office' => 'SDO-Accounting Unit', 'permissions' => ['platform.index' => true]]);
        $document = Document::factory()->create(['status' => DocumentMovement::STATUS_RECEIVED, 'current_office' => 'SDO-Budget Unit']);
        foreach ([[$laura, 'SDO-Budget Unit'], [$ramon, 'SDO-Accounting Unit']] as [$receiver, $office]) {
            $document->officeAssignments()->updateOrCreate(['office' => $office], ['status' => DocumentMovement::STATUS_RECEIVED]);
            $document->movements()->create([
                'action' => DocumentMovement::ACTION_RECEIVED,
                'from_office' => $office,
                'to_office' => $office,
                'from_status' => DocumentMovement::STATUS_FORWARDED,
                'to_status' => DocumentMovement::STATUS_RECEIVED,
                'acted_by' => $receiver->id,
            ]);
        }

        $page = $this->actingAs($admin)->get(route('platform.documents.workspace', $document))->assertOk();
        $this->assertMatchesRegularExpression('/Received [^<]+ by Laura C\. Bergonia</', $page->getContent());
        $this->assertMatchesRegularExpression('/Received [^<]+ by Ramon Dizon</', $page->getContent());
    }

    public function test_administrator_sends_to_another_office_from_the_office_that_forwarded_it(): void
    {
        $adminRole = Role::create(['name' => 'Admin', 'slug' => User::ADMIN_ROLE_SLUG]);
        $admin = User::factory()->create(['office' => null]);
        $admin->replaceRoles([$adminRole->id]);
        $document = $this->forwardedDocument();

        // The admin has no office of their own, so the sending office offered is the one that actually forwarded it.
        $page = $this->actingAs($admin)->get(route('platform.outgoing'))->assertOk();
        preg_match("/data-additional-route='([^']+)'/", $page->getContent(), $match);
        $details = json_decode(html_entity_decode($match[1] ?? ''), true);
        $this->assertSame(['Administrative Unit'], $details['sources'] ?? null);
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
