<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\DocumentMovement;
use App\Models\User;
use App\Services\DocumentRoutingService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Orchid\Platform\Models\Role;
use Tests\TestCase;

class DocumentIntakeTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_records_staff_can_find_a_pending_submission_case_insensitively(): void
    {
        $this->travelTo(Carbon::parse('2026-10-13 02:15:00', 'UTC'));
        $staff = User::factory()->create(['office' => 'SDO-Records Unit', 'permissions' => ['platform.index' => true]]);
        $document = $this->pendingDocument();

        $this->actingAs($staff)
            ->getJson(route('platform.documents.intake', ['tracking' => strtolower($document->tracking_number)]))
            ->assertOk()
            ->assertJsonPath('state', 'awaiting')
            ->assertJsonPath('source_office', 'SDO-Records Unit')
            ->assertJsonPath('document.tracking_number', $document->tracking_number)
            ->assertJsonPath('document.subject', 'Budget request')
            ->assertJsonPath('document.document_type', 'Letter')
            ->assertJsonPath('document.priority', 'Standard')
            ->assertJsonPath('document.sender', 'Alex Rivera')
            ->assertJsonPath('document.origin', 'School Office')
            ->assertJsonPath('document.submitted_on', 'Oct 13, 2026')
            ->assertJsonPath('document.status', DocumentMovement::STATUS_AWAITING_PHYSICAL_DOCUMENT)
            ->assertJsonPath('document.routes.0.office', 'SDO-Budget Unit')
            ->assertJsonPath('document.routes.0.remarks', 'Review budget.')
            ->assertJsonPath('document.routes.1.office', 'SDO-Legal Unit')
            ->assertJsonPath('document.routes.1.remarks', 'Review terms.')
            ->assertJsonPath('document.confirm_url', route('platform.documents.movements.store', $document))
            ->assertJsonPath('document.view_url', route('platform.documents.view', $document))
            ->assertJsonPath('document.label_url', route('platform.documents.label', $document))
            ->assertJsonPath('receipt', null);
    }

    public function test_records_receipt_uses_the_manila_business_date(): void
    {
        $this->travelTo(Carbon::parse('2026-10-13 23:30:00', 'UTC'));
        $staff = User::factory()->create(['office' => 'SDO-Records Unit', 'permissions' => ['platform.index' => true]]);
        $document = $this->pendingDocument();

        $this->actingAs($staff)
            ->postJson(route('platform.documents.movements.store', $document), [
                'workflow' => ['action' => 'received', 'source_office' => 'SDO-Records Unit'],
            ])
            ->assertOk();

        $this->assertSame('2026-10-14', $document->fresh()->received_at->toDateString());
    }

    public function test_receiving_a_new_office_arrival_does_not_replace_the_records_clerk_on_a_stale_request(): void
    {
        $recordsClerk = User::factory()->create(['office' => 'SDO-Records Unit']);
        $adminRole = Role::create(['name' => 'Admin', 'slug' => User::ADMIN_ROLE_SLUG]);
        $administrator = User::factory()->create(['office' => null, 'permissions' => ['platform.index' => true]]);
        $administrator->replaceRoles([$adminRole->id]);
        $document = Document::factory()->create([
            'current_office' => 'SDO-Records Unit',
            'status' => DocumentMovement::STATUS_AWAITING_PHYSICAL_DOCUMENT,
            'received_at' => null,
            'registered_by' => null,
        ]);

        $routing = $this->mock(DocumentRoutingService::class)->makePartial();
        $routing->shouldReceive('route')->once()->andReturnUsing(function (Document $staleDocument, User $actor, array $workflow) use ($recordsClerk): array {
            $staleDocument->movements()->create([
                'action' => DocumentMovement::ACTION_RECEIVED,
                'from_office' => 'SDO-Records Unit',
                'to_office' => 'SDO-Records Unit',
                'from_status' => DocumentMovement::STATUS_AWAITING_PHYSICAL_DOCUMENT,
                'to_status' => DocumentMovement::STATUS_RECEIVED,
                'acted_by' => $recordsClerk->id,
            ]);
            $forward = $staleDocument->movements()->create([
                'action' => DocumentMovement::ACTION_FORWARDED,
                'from_office' => 'SDO-Records Unit',
                'to_office' => 'SDO-Budget Unit',
                'from_status' => DocumentMovement::STATUS_RECEIVED,
                'to_status' => DocumentMovement::STATUS_FORWARDED,
                'acted_by' => $recordsClerk->id,
            ]);
            $staleDocument->officeAssignments()->where('office', 'SDO-Records Unit')->delete();
            $staleDocument->officeAssignments()->create([
                'office' => 'SDO-Budget Unit',
                'status' => DocumentMovement::STATUS_FORWARDED,
                'movement_id' => $forward->id,
            ]);
            Document::query()->whereKey($staleDocument->id)->update([
                'current_office' => 'SDO-Budget Unit',
                'status' => DocumentMovement::STATUS_FORWARDED,
                'received_at' => today('Asia/Manila')->toDateString(),
                'registered_by' => $recordsClerk->id,
                'registered_office' => 'SDO-Records Unit',
            ]);

            return (new DocumentRoutingService)->route($staleDocument, $actor, $workflow);
        });

        $this->actingAs($administrator)
            ->postJson(route('platform.documents.movements.store', $document), [
                'workflow' => ['action' => 'received', 'source_office' => 'SDO-Budget Unit'],
            ])
            ->assertOk()
            ->assertJsonPath('message', "{$document->tracking_number} was received.");

        $this->assertSame($recordsClerk->id, $document->fresh()->registered_by);
        $this->assertSame(1, $document->movements()
            ->where('action', DocumentMovement::ACTION_RECEIVED)
            ->where('from_status', DocumentMovement::STATUS_AWAITING_PHYSICAL_DOCUMENT)
            ->count());
    }

    public function test_lookup_reports_a_received_paper_copy(): void
    {
        $this->travelTo(Carbon::parse('2026-10-13 02:15:00', 'UTC'));
        $staff = User::factory()->create([
            'name' => 'Records Clerk',
            'office' => 'SDO-Records Unit',
            'permissions' => ['platform.index' => true],
        ]);
        $document = $this->pendingDocument();

        $this->actingAs($staff)
            ->postJson(route('platform.documents.movements.store', $document), [
                'workflow' => ['action' => 'received', 'source_office' => 'SDO-Records Unit'],
            ])
            ->assertOk()
            ->assertJsonPath('message', 'Document received by Records Unit and sent to SDO-Budget Unit and SDO-Legal Unit.');

        $this->assertSame($staff->id, $document->fresh()->registered_by);
        $this->getJson(route('platform.documents.intake', ['tracking' => $document->tracking_number]))
            ->assertOk()
            ->assertJsonPath('state', 'received')
            ->assertJsonPath('document.routes', [])
            ->assertJsonPath('receipt.received_on', 'Oct 13, 2026 at 10:15 AM')
            ->assertJsonPath('receipt.received_by', 'Records Clerk')
            ->assertJsonPath('receipt.offices', ['SDO-Budget Unit', 'SDO-Legal Unit']);

        $this->postJson(route('platform.documents.movements.store', $document), [
            'workflow' => ['action' => 'received', 'source_office' => 'SDO-Records Unit'],
        ])->assertForbidden();
        $this->assertSame(1, $document->movements()
            ->where('action', DocumentMovement::ACTION_RECEIVED)
            ->where('from_status', DocumentMovement::STATUS_AWAITING_PHYSICAL_DOCUMENT)
            ->count());
    }

    public function test_receipt_summary_excludes_a_corrected_destination(): void
    {
        $staff = User::factory()->create(['office' => 'SDO-Records Unit', 'permissions' => ['platform.index' => true]]);
        $document = $this->pendingDocument();

        $this->actingAs($staff)->postJson(route('platform.documents.movements.store', $document), [
            'workflow' => ['action' => 'received', 'source_office' => 'SDO-Records Unit'],
        ])->assertOk();
        $this->postJson(route('platform.documents.routes.correct', $document), [
            'source_office' => 'SDO-Records Unit',
            'destination_office' => 'SDO-Budget Unit',
            'replacement_office' => 'Administrative Unit',
            'correction_reason' => 'Wrong destination selected.',
        ])->assertOk();

        $this->getJson(route('platform.documents.intake', ['tracking' => $document->tracking_number]))
            ->assertOk()
            ->assertJsonPath('receipt.offices', ['SDO-Legal Unit', 'Administrative Unit']);
    }

    public function test_lookup_reports_non_paper_documents_and_missing_tracking_numbers(): void
    {
        $staff = User::factory()->create(['office' => 'SDO-Records Unit', 'permissions' => ['platform.index' => true]]);
        $document = Document::factory()->received()->create(['tracking_number' => 'DTS-2026-NO-PAPER', 'current_office' => 'SDO-Records Unit']);

        $this->actingAs($staff)
            ->getJson(route('platform.documents.intake', ['tracking' => $document->tracking_number]))
            ->assertOk()
            ->assertJsonPath('state', 'not_awaiting')
            ->assertJsonPath('receipt', null);
        $this->getJson(route('platform.documents.intake', ['tracking' => 'DTS-2026-MISSING']))
            ->assertNotFound()
            ->assertJsonPath('message', 'No document found for this tracking number.');
    }

    public function test_lookup_is_limited_to_records_staff_and_administrators(): void
    {
        $document = $this->pendingDocument();
        $otherStaff = User::factory()->create(['office' => 'Budget Unit', 'permissions' => ['platform.index' => true]]);
        $noAccessStaff = User::factory()->create(['office' => 'SDO-Records Unit', 'permissions' => []]);
        $supervisorRole = Role::create(['name' => 'Supervisor', 'slug' => 'supervisor', 'permissions' => ['platform.index' => true]]);
        $supervisor = User::factory()->create(['office' => 'SDO-Records Unit', 'permissions' => ['platform.index' => true]]);
        $supervisor->replaceRoles([$supervisorRole->id]);
        $adminRole = Role::create(['name' => 'Admin', 'slug' => User::ADMIN_ROLE_SLUG, 'permissions' => []]);
        $admin = User::factory()->create(['office' => null, 'permissions' => []]);
        $admin->replaceRoles([$adminRole->id]);
        $url = route('platform.documents.intake', ['tracking' => $document->tracking_number]);

        $this->getJson($url)->assertUnauthorized();
        $this->actingAs($otherStaff)->getJson($url)->assertForbidden();
        $this->actingAs($noAccessStaff)->getJson($url)->assertForbidden();
        $this->actingAs($supervisor)->getJson($url)->assertForbidden();
        $this->actingAs($admin)->getJson($url)->assertOk()->assertJsonPath('state', 'awaiting');
    }

    public function test_paper_waiting_at_records_tops_the_dashboard_queue_with_a_paper_received_button(): void
    {
        $records = User::factory()->create(['office' => 'SDO-Records Unit', 'permissions' => ['platform.index' => true]]);
        $budget = User::factory()->create(['office' => 'SDO-Budget Unit', 'permissions' => ['platform.index' => true]]);

        // A full queue of other work must not displace a paper submission waiting at Records.
        foreach (range(1, 6) as $item) {
            Document::factory()->incoming()->create()
                ->officeAssignments()->updateOrCreate(['office' => 'SDO-Records Unit'], ['status' => DocumentMovement::STATUS_AWAITING_RECEIPT]);
        }
        $document = $this->pendingDocument();
        $document->officeAssignments()->updateOrCreate(['office' => 'SDO-Records Unit'], ['status' => DocumentMovement::STATUS_AWAITING_PHYSICAL_DOCUMENT]);

        $this->actingAs($records)
            ->get(route('platform.main'))
            ->assertOk()
            ->assertSee('data-paper-received="'.$document->tracking_number.'"', false)
            ->assertSee(route('platform.documents.intake'), false)
            ->assertSee('Document/s received')
            ->assertDontSee('data-paper-receive-all', false);

        $this->actingAs($budget)
            ->get(route('platform.main'))
            ->assertOk()
            ->assertDontSee('data-paper-received', false);
    }

    public function test_held_routes_of_a_paper_submission_are_listed_as_waiting_rows_until_records_receives_it(): void
    {
        $staff = User::factory()->create(['office' => 'SDO-Records Unit', 'permissions' => ['platform.index' => true]]);
        $document = $this->pendingDocument();
        $document->update(['client_submission_key' => 'counter-test-key']);
        $document->officeAssignments()->updateOrCreate(['office' => 'SDO-Records Unit'], ['status' => DocumentMovement::STATUS_AWAITING_PHYSICAL_DOCUMENT]);
        // The Records Unit's holding row is left out: Paper received completes its part.
        $rows = ['SDO-Budget Unit', 'Waiting for document/s', 'SDO-Legal Unit', 'Waiting for document/s'];
        $recordsRow = 'aria-label="View SDO-Records Unit workflow, Awaiting physical document"';

        $this->actingAs($staff)->get(route('platform.main'))
            ->assertOk()
            ->assertSee('Client document/s: receive them, print the label, then send them to the offices.')
            ->assertSeeInOrder($rows)
            ->assertDontSee($recordsRow, false);
        $this->get(route('platform.documents'))->assertOk()->assertSeeInOrder($rows)->assertDontSee($recordsRow, false);
        $this->get(route('platform.documents.workspace', $document))->assertOk()->assertSeeInOrder($rows)->assertDontSee($recordsRow, false);
        $this->get(route('platform.documents.workspace', $document))->assertOk()->assertSeeInOrder($rows)
            ->assertDontSee('data-workspace-route="SDO-Records Unit"', false);

        // Fast Track: one card per office, while the overall status still reads "Pending" (awaiting the paper copy).
        $this->get(route('tracking.index', ['tracking' => $document->tracking_number]))
            ->assertOk()
            ->assertDontSee('Submit your document to the Records staff.')
            ->assertSee('data-route-state="waiting"', false)
            ->assertSee('>Pending</span>', false)
            ->assertDontSee('data-route-office="SDO-Records Unit"', false)
            ->assertSeeInOrder($rows)
            ->assertDontSee('This office receives the document/s after you bring them to the Records Unit.')
            ->assertSee(route('tracking.index', ['tracking' => $document->tracking_number, 'office' => 'SDO-Budget Unit']));

        // Waiting rows are clickable like the others: the office's page and its (still empty) history open normally.
        $this->get(route('platform.documents.workspace', ['document' => $document, 'source_office' => 'SDO-Budget Unit']))->assertOk();
        $this->get(route('tracking.index', ['tracking' => $document->tracking_number, 'office' => 'SDO-Budget Unit']))
            ->assertOk()
            ->assertSee('aria-current="true"', false);

        $this->postJson(route('platform.documents.movements.store', $document), [
            'workflow' => ['action' => 'received', 'source_office' => 'SDO-Records Unit'],
        ])->assertOk();

        $this->get(route('platform.documents.workspace', $document))->assertOk()->assertDontSee('data-status="waiting-for-paper-copy"', false);
    }

    public function test_records_follows_the_whole_history_of_documents_it_sent_on(): void
    {
        $records = User::factory()->create(['office' => 'SDO-Records Unit', 'permissions' => ['platform.index' => true]]);
        $legal = User::factory()->create(['office' => 'SDO-Legal Unit', 'permissions' => ['platform.index' => true]]);
        $document = $this->pendingDocument();
        $document->officeAssignments()->updateOrCreate(['office' => 'SDO-Records Unit'], ['status' => DocumentMovement::STATUS_AWAITING_PHYSICAL_DOCUMENT]);

        $this->actingAs($records)->postJson(route('platform.documents.movements.store', $document), [
            'workflow' => ['action' => 'received', 'source_office' => 'SDO-Records Unit'],
        ])->assertOk();
        // A later step between two other offices.
        $document->movements()->create([
            'action' => DocumentMovement::ACTION_FORWARDED,
            'from_office' => 'SDO-Budget Unit',
            'to_office' => 'SDO-Assistant Schools Division Superintendent (ASDS) Office',
            'from_status' => DocumentMovement::STATUS_RECEIVED,
            'to_status' => DocumentMovement::STATUS_FORWARDED,
            'remarks' => 'Budget cleared for ASDS approval.',
        ]);

        $this->get(route('platform.documents.workspace', $document))->assertOk()->assertSee('Budget cleared for ASDS approval.');
        $this->get(route('platform.documents.workspace', $document))->assertOk()->assertSee('Budget cleared for ASDS approval.');
        $this->get(route('platform.documents.workspace', ['document' => $document, 'history_page' => 1, 'office' => 'SDO-Budget Unit']))
            ->assertOk()
            ->assertSee('Budget cleared for ASDS approval.');

        // Other offices still only see the entries that involve them.
        $this->actingAs($legal)->get(route('platform.documents.workspace', $document))->assertOk()->assertDontSee('Budget cleared for ASDS approval.');
    }

    private function pendingDocument(): Document
    {
        $document = Document::factory()->create([
            'tracking_number' => 'DTS-2026-COUNTER-01',
            'subject' => 'Budget request',
            'document_type' => 'Letter',
            'priority' => 'Standard',
            'sender' => 'Alex Rivera',
            'origin' => 'School Office',
            'current_office' => 'SDO-Records Unit',
            'status' => DocumentMovement::STATUS_AWAITING_PHYSICAL_DOCUMENT,
            'registered_by' => null,
        ]);
        DB::table('document_route_steps')->insert([
            [
                'document_id' => $document->id,
                'sequence' => 1,
                'office' => 'SDO-Budget Unit',
                'remarks' => 'Review budget.',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'document_id' => $document->id,
                'sequence' => 2,
                'office' => 'SDO-Legal Unit',
                'remarks' => 'Review terms.',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
        activity('documents')
            ->performedOn($document)
            ->withProperties(['offices' => ['SDO-Budget Unit', 'SDO-Legal Unit']])
            ->log('Document submitted');

        return $document;
    }
}
