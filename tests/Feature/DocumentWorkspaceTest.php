<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\DocumentMovement;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Orchid\Platform\Models\Role;
use Tests\TestCase;

class DocumentWorkspaceTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_registry_opens_an_authorized_document_workspace_without_loading_it_into_the_table(): void
    {
        $user = User::factory()->create(['office' => 'Records Unit', 'permissions' => ['platform.index' => true]]);
        $document = Document::factory()->create([
            'tracking_number' => 'DTS-WORKSPACE-001',
            'subject' => 'Workspace document',
            'current_office' => 'Records Unit',
        ]);

        $this->actingAs($user)
            ->get(route('platform.documents'))
            ->assertOk()
            ->assertSee(route('platform.documents.workspace', $document))
            ->assertSee('data-workspace-office="Records Unit"', false);

        $this->get(route('platform.documents.workspace', $document))
            ->assertOk()
            ->assertSee('data-workspace-tab="details">Details</button>', false)
            ->assertSee('Routing &amp; History', false)
            ->assertSee('DTS-WORKSPACE-001')
            ->assertSee('Workspace document')
            ->assertSee('Office routes');
    }

    public function test_workspace_does_not_disclose_documents_outside_the_users_offices(): void
    {
        $user = User::factory()->create(['office' => 'Records Unit', 'permissions' => ['platform.index' => true]]);
        $document = Document::factory()->create(['current_office' => 'Administrative Unit']);

        $this->actingAs($user)
            ->get(route('platform.documents.workspace', $document))
            ->assertNotFound();
    }

    public function test_records_completion_is_not_offered_while_another_office_is_working(): void
    {
        $records = User::factory()->create(['office' => 'SDO-Records Unit', 'permissions' => ['platform.index' => true]]);
        $document = Document::factory()->received()->create(['current_office' => 'SDO-Records Unit']);
        $document->officeAssignments()->create(['office' => 'Administrative Unit', 'status' => DocumentMovement::STATUS_FORWARDED]);

        $this->actingAs($records)
            ->get(route('platform.documents.workspace', $document))
            ->assertViewHas('workflowActionOptions', fn (array $options): bool => ! isset($options['SDO-Records Unit'][DocumentMovement::ACTION_COMPLETED]));

        $this->get(route('platform.documents.view', $document))
            ->assertDontSee('"completed":"Completed"', false);
    }

    public function test_only_the_records_unit_is_offered_release(): void
    {
        $budget = User::factory()->create(['office' => 'SDO-Budget Unit', 'permissions' => ['platform.index' => true]]);
        $document = Document::factory()->received()->create(['current_office' => 'SDO-Budget Unit']);

        // Another office can forward or complete what it received, but releasing to the client is the Records Unit's job.
        foreach ([route('platform.documents.workspace', $document), route('platform.documents.view', $document)] as $url) {
            $page = $this->actingAs($budget)->get($url)->assertOk();
            preg_match("/data-action-options='([^']+)'/", $page->getContent(), $match);
            $options = json_decode(html_entity_decode($match[1] ?? ''), true);
            $this->assertSame(['forwarded', 'completed'], array_keys($options['SDO-Budget Unit']));
        }
    }

    public function test_records_only_paper_receipt_offers_complete_and_release_in_the_workspace(): void
    {
        $records = User::factory()->create(['office' => 'SDO-Records Unit', 'permissions' => ['platform.index' => true]]);
        $document = Document::factory()->received()->create(['current_office' => 'SDO-Records Unit']);
        $receipt = $document->movements()->create([
            'action' => DocumentMovement::ACTION_RECEIVED,
            'from_office' => 'SDO-Records Unit',
            'to_office' => 'SDO-Records Unit',
            'from_status' => DocumentMovement::STATUS_AWAITING_PHYSICAL_DOCUMENT,
            'to_status' => DocumentMovement::STATUS_RECEIVED,
            'acted_by' => $records->id,
        ]);
        $document->officeAssignments()->where('office', 'SDO-Records Unit')->update(['movement_id' => $receipt->id]);

        $this->actingAs($records)
            ->get(route('platform.documents.workspace', $document))
            ->assertSee('"released":"Release"', false)
            ->assertSee('"completed":"Complete"', false);

        $this->postJson(route('platform.documents.movements.store', $document), [
            'workflow' => ['action' => 'released', 'source_office' => 'SDO-Records Unit', 'released_to' => 'Juan Dela Cruz'],
        ])->assertOk();

        $this->assertSame(DocumentMovement::STATUS_RELEASED, $document->fresh()->status);
    }

    public function test_records_only_route_can_be_released_after_receipt_from_the_office_queue(): void
    {
        $records = User::factory()->create(['office' => 'SDO-Records Unit', 'permissions' => ['platform.index' => true]]);
        $document = Document::factory()->incoming()->create(['current_office' => 'SDO-Records Unit']);

        $this->actingAs($records)->postJson(route('platform.documents.movements.store', $document), [
            'workflow' => ['action' => 'received', 'source_office' => 'SDO-Records Unit'],
        ])->assertOk();

        $this->get(route('platform.documents.workspace', $document))
            ->assertSee('"released":"Release"', false);

        $this->get(route('platform.main'))
            ->assertSee('data-workspace-action="released"', false);

        $this->postJson(route('platform.documents.movements.store', $document), [
            'workflow' => ['action' => 'released', 'source_office' => 'SDO-Records Unit', 'released_to' => 'Juan Dela Cruz'],
        ])->assertOk();

        $this->assertSame(DocumentMovement::STATUS_RELEASED, $document->fresh()->status);
    }

    public function test_records_only_paper_receipt_can_complete_without_recording_a_release(): void
    {
        $records = User::factory()->create(['office' => 'SDO-Records Unit', 'permissions' => ['platform.index' => true]]);
        $document = Document::factory()->received()->create(['current_office' => 'SDO-Records Unit']);
        $receipt = $document->movements()->create([
            'action' => DocumentMovement::ACTION_RECEIVED,
            'from_office' => 'SDO-Records Unit',
            'to_office' => 'SDO-Records Unit',
            'from_status' => DocumentMovement::STATUS_AWAITING_PHYSICAL_DOCUMENT,
            'to_status' => DocumentMovement::STATUS_RECEIVED,
            'acted_by' => $records->id,
        ]);
        $document->officeAssignments()->where('office', 'SDO-Records Unit')->update(['movement_id' => $receipt->id]);

        $this->actingAs($records)->postJson(route('platform.documents.movements.store', $document), [
            'workflow' => ['action' => 'completed', 'source_office' => 'SDO-Records Unit'],
        ])->assertOk();

        $this->assertSame(DocumentMovement::STATUS_COMPLETED, $document->fresh()->status);
        $this->assertSame(0, $document->movements()->where('action', DocumentMovement::ACTION_RELEASED)->count());
    }

    public function test_records_paper_receipt_does_not_offer_release_for_a_document_with_other_office_routes(): void
    {
        $records = User::factory()->create(['office' => 'SDO-Records Unit', 'permissions' => ['platform.index' => true]]);
        $document = Document::factory()->received()->create(['current_office' => 'SDO-Records Unit']);
        $receipt = $document->movements()->create([
            'action' => DocumentMovement::ACTION_RECEIVED,
            'from_office' => 'SDO-Records Unit',
            'to_office' => 'SDO-Records Unit',
            'from_status' => DocumentMovement::STATUS_AWAITING_PHYSICAL_DOCUMENT,
            'to_status' => DocumentMovement::STATUS_RECEIVED,
            'acted_by' => $records->id,
        ]);
        $document->officeAssignments()->where('office', 'SDO-Records Unit')->update(['movement_id' => $receipt->id]);
        $document->officeAssignments()->create([
            'office' => 'Administrative Unit',
            'status' => DocumentMovement::STATUS_COMPLETED,
        ]);

        $this->actingAs($records)
            ->get(route('platform.documents.workspace', $document))
            ->assertDontSee('"released":"Release"', false);

        $this->postJson(route('platform.documents.movements.store', $document), [
            'workflow' => ['action' => 'released', 'source_office' => 'SDO-Records Unit', 'released_to' => 'Juan Dela Cruz'],
        ])->assertUnprocessable()->assertJsonValidationErrors('workflow.action');
    }

    public function test_restore_is_not_offered_to_staff_outside_records(): void
    {
        $staff = User::factory()->create(['office' => 'Administrative Unit', 'permissions' => ['platform.index' => true]]);
        $document = Document::factory()->archived()->create(['current_office' => 'Administrative Unit']);

        $this->actingAs($staff)
            ->get(route('platform.documents.workspace', $document))
            ->assertViewHas('workflowActionOptions', fn (array $options): bool => ! isset($options['Administrative Unit'][DocumentMovement::ACTION_RESTORED]));
    }

    public function test_modal_detail_save_reuses_validation_and_does_not_change_routes(): void
    {
        $user = User::factory()->create(['office' => 'Records Unit', 'permissions' => [
            'platform.index' => true,
            'platform.documents.edit' => true,
            'platform.documents.routes.manage' => true,
        ]]);
        $document = Document::factory()->received()->create(['subject' => 'Before edit', 'current_office' => 'Records Unit']);
        $beforeRoutes = $document->officeAssignments()->pluck('office')->all();

        $this->actingAs($user)
            ->postJson(route('platform.documents.edit', $document).'/save', [
                'details_only' => true,
                'document' => $this->validDocumentData($document),
            ])
            ->assertOk()
            ->assertJsonPath('message', 'Document details saved.');

        $this->assertSame('Updated workspace title', $document->fresh()->subject);
        $this->assertSame($beforeRoutes, $document->officeAssignments()->pluck('office')->all());
        $this->assertSame(0, $document->movements()->count());
    }

    public function test_modal_reports_a_stale_details_save_as_a_validation_conflict(): void
    {
        $user = User::factory()->create([
            'office' => 'Records Unit',
            'permissions' => ['platform.index' => true, 'platform.documents.edit' => true],
        ]);
        $document = Document::factory()->received()->create([
            'subject' => 'Original title',
            'current_office' => 'Records Unit',
        ]);
        $data = $this->validDocumentData($document);

        $this->actingAs($user)
            ->get(route('platform.documents.workspace', $document))
            ->assertSee('name="document[details_fingerprint]"', false);

        $this->postJson(route('platform.documents.edit', $document).'/save', [
            'details_only' => true,
            'document' => $data,
        ])->assertOk();

        $data['title'] = 'Stale title';
        $this->postJson(route('platform.documents.edit', $document).'/save', [
            'details_only' => true,
            'document' => $data,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('document.details_fingerprint');

        $this->assertSame('Updated workspace title', $document->fresh()->subject);
    }

    public function test_workspace_is_the_visible_editor_and_includes_routes_for_administrators(): void
    {
        $adminRole = Role::create(['name' => 'Admin', 'slug' => User::ADMIN_ROLE_SLUG]);
        $user = User::factory()->create(['office' => 'Records Unit']);
        $user->replaceRoles([$adminRole->id]);
        $document = Document::factory()->received()->create(['current_office' => 'Records Unit', 'registered_by' => $user->id]);

        $this->actingAs($user)
            ->get(route('platform.documents'))
            ->assertSee('data-workspace-edit-open', false)
            ->assertSee('data-workspace-url="'.route('platform.documents.workspace', $document).'"', false);

        $this->get(route('platform.documents.view', $document))
            ->assertSee('data-workspace-edit-open', false)
            ->assertSee('data-workspace-auto-open', false)
            ->assertSee('data-workspace-return-url="'.route('platform.documents').'"', false)
            ->assertSee('data-document-workspace', false);

        $this->get(route('platform.documents.workspace', $document))
            ->assertSee('Edit document')
            ->assertSee('data-edit-route-add', false)
            ->assertSee('name="document[destinations][]"', false)
            ->assertSee('Save changes');
    }

    public function test_registry_can_open_a_bookmarked_document_directly_in_the_workspace(): void
    {
        $user = User::factory()->create(['office' => 'Records Unit', 'permissions' => ['platform.index' => true]]);
        $document = Document::factory()->received()->create(['current_office' => 'Records Unit']);

        $this->actingAs($user)
            ->get(route('platform.documents', [
                'open_document' => $document->id,
                'source_office' => 'Records Unit',
            ]))
            ->assertSee('data-workspace-auto-open', false)
            ->assertSee('data-workspace-url="'.route('platform.documents.workspace', $document).'"', false)
            ->assertSee('data-workspace-office="Records Unit"', false);
    }

    public function test_modal_can_save_document_details_and_routes_together(): void
    {
        $adminRole = Role::create(['name' => 'Admin', 'slug' => User::ADMIN_ROLE_SLUG]);
        $user = User::factory()->create(['office' => 'Records Unit']);
        $user->replaceRoles([$adminRole->id]);
        $document = Document::factory()->received()->create(['subject' => 'Before edit', 'current_office' => 'Records Unit', 'registered_by' => $user->id]);
        $data = $this->validDocumentData($document);
        $data['destinations'] = ['Records Unit', 'Administrative Unit'];
        $data['route_fingerprint'] = $document->routeFingerprint();
        $data['correction_reason'] = 'Adding the office omitted at registration.';

        $this->actingAs($user)
            ->postJson(route('platform.documents.edit', $document).'/save', ['document' => $data])
            ->assertOk();

        $this->assertSame('Updated workspace title', $document->fresh()->subject);
        $this->assertEqualsCanonicalizing(
            ['Records Unit', 'Administrative Unit'],
            $document->officeAssignments()->pluck('office')->all(),
        );
    }

    public function test_workspace_hides_route_editing_from_detail_only_editors(): void
    {
        $user = User::factory()->create(['office' => 'Records Unit', 'permissions' => [
            'platform.index' => true,
            'platform.documents.edit' => true,
        ]]);
        $document = Document::factory()->received()->create(['current_office' => 'Records Unit']);

        $this->actingAs($user)
            ->get(route('platform.documents.workspace', $document))
            ->assertSee('Edit document')
            ->assertDontSee('data-edit-route-add', false)
            ->assertDontSee('name="document[destinations][]"', false);
    }

    public function test_assigned_recipient_cannot_edit_other_office_assignments(): void
    {
        $registrant = User::factory()->create(['office' => 'Records Unit']);
        $recipient = User::factory()->create(['office' => 'Budget Unit', 'permissions' => [
            'platform.index' => true,
            'platform.documents.edit' => true,
            'platform.documents.routes.manage' => true,
        ]]);
        $document = Document::factory()->received()->create([
            'registered_by' => $registrant->id,
            'current_office' => 'Records Unit',
        ]);
        $document->officeAssignments()->create(['office' => 'Budget Unit', 'status' => 'Awaiting receipt']);

        $data = $this->validDocumentData($document);
        $data['destinations'] = ['Budget Unit'];

        $this->actingAs($recipient)
            ->postJson(route('platform.documents.edit', $document).'/save', ['document' => $data])
            ->assertForbidden();

        $this->get(route('platform.documents.workspace', $document))
            ->assertOk()
            ->assertSee('data-workspace-routing', false)
            ->assertDontSee('name="document[destinations][]"', false);

        $this->assertEqualsCanonicalizing(
            ['Records Unit', 'Budget Unit'],
            $document->officeAssignments()->pluck('office')->all(),
        );
    }

    public function test_registrant_who_receives_a_route_cannot_edit_other_office_assignments(): void
    {
        $recipient = User::factory()->create(['office' => 'Budget Unit', 'permissions' => [
            'platform.index' => true,
            'platform.documents.edit' => true,
            'platform.documents.routes.manage' => true,
        ]]);
        $document = Document::factory()->received()->create([
            'registered_by' => $recipient->id,
            'current_office' => 'Records Unit',
        ]);
        $document->officeAssignments()->create(['office' => 'Budget Unit', 'status' => 'Awaiting receipt']);
        $data = $this->validDocumentData($document);
        $data['destinations'] = ['Budget Unit'];

        $this->actingAs($recipient)
            ->get(route('platform.documents.workspace', $document))
            ->assertSee('data-workspace-routing', false)
            ->assertSee('data-workspace-route-readonly', false)
            ->assertSee('permission to edit this office', false)
            ->assertDontSee('name="document[destinations][]"', false);

        $this->postJson(route('platform.documents.edit', $document).'/save', ['document' => $data])
            ->assertForbidden();

        $this->postJson(route('platform.documents.movements.store', $document), [
            'workflow' => ['source_office' => 'Records Unit', 'action' => 'reviewed'],
        ])->assertForbidden();

        $this->postJson(route('platform.documents.movements.store', $document), [
            'workflow' => ['source_office' => 'Budget Unit', 'action' => 'received'],
        ])->assertOk();

        $this->assertSame('Received', $document->officeAssignments()->where('office', 'Budget Unit')->sole()->status);
        $this->assertSame('Received', $document->officeAssignments()->where('office', 'Records Unit')->sole()->status);
    }

    public function test_modal_detail_save_rejects_invalid_values_and_route_changes(): void
    {
        $user = User::factory()->create(['office' => 'Records Unit', 'permissions' => [
            'platform.index' => true,
            'platform.documents.edit' => true,
            'platform.documents.routes.manage' => true,
        ]]);
        $document = Document::factory()->received()->create(['subject' => 'Original title', 'current_office' => 'Records Unit']);
        $data = $this->validDocumentData($document);
        $data['title'] = '';
        $data['destinations'] = ['Administrative Unit'];

        $this->actingAs($user)
            ->postJson(route('platform.documents.edit', $document).'/save', [
                'details_only' => true,
                'document' => $data,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['document.title', 'document.destinations']);

        $this->assertSame('Original title', $document->fresh()->subject);
        $this->assertSame(['Records Unit'], $document->officeAssignments()->pluck('office')->all());
    }

    public function test_document_wide_remark_form_and_endpoint_are_unavailable(): void
    {
        $user = User::factory()->create(['office' => 'Records Unit', 'permissions' => [
            'platform.index' => true,
            'platform.documents.edit' => true,
        ]]);
        $document = Document::factory()->received()->create(['current_office' => 'Records Unit']);

        $this->actingAs($user)
            ->postJson(route('platform.documents.workspace', $document).'/remarks', ['remark' => 'Please review the attachment.'])
            ->assertNotFound();

        $this->get(route('platform.documents.workspace', $document))
            ->assertDontSee('Add a remark')
            ->assertDontSee('data-workspace-remarks-list', false);
        $this->assertSame(0, $document->activities()->count());
    }

    public function test_modal_shows_routing_controls_and_route_filter_data_for_assigned_staff(): void
    {
        $user = User::factory()->create(['office' => 'Records Unit', 'permissions' => ['platform.index' => true]]);
        $document = Document::factory()->received()->create(['current_office' => 'Records Unit']);

        $this->actingAs($user)
            ->get(route('platform.documents.workspace', $document))
            ->assertSee('data-workspace-routing', false)
            ->assertSee('data-workspace-route="Records Unit"', false)
            ->assertSee('data-workspace-history-mode="selected"', false)
            ->assertSee('data-workspace-history-loading', false)
            ->assertSee('data-workspace-routing-save', false);
    }

    public function test_modal_routing_returns_json_and_preserves_the_existing_transition_rules(): void
    {
        $user = User::factory()->create(['office' => 'Records Unit', 'permissions' => ['platform.index' => true]]);
        $document = Document::factory()->received()->create(['current_office' => 'Records Unit']);

        $this->actingAs($user)
            ->postJson(route('platform.documents.movements.store', $document), [
                'workflow' => [
                    'source_office' => 'Records Unit',
                    'action' => 'completed',
                    'remarks' => 'Completed in the workspace.',
                ],
            ])
            ->assertOk()
            ->assertJsonPath('visible', true);

        $this->assertSame('Completed', $document->fresh()->status);
        $this->assertSame('Completed', $document->officeAssignments()->sole()->status);
        $this->assertSame('Completed in the workspace.', $document->movements()->sole()->remarks);
        $this->get(route('platform.documents.workspace', $document))
            ->assertSee('Completed in the workspace.')
            ->assertSee('<dt>Status</dt><dd><span class="dts-record-status" data-status="completed"', false)
            ->assertSee('data-history-to="Records Unit"', false);
    }

    public function test_invalid_modal_routing_returns_validation_errors_without_a_movement(): void
    {
        $user = User::factory()->create(['office' => 'Records Unit', 'permissions' => ['platform.index' => true]]);
        $document = Document::factory()->received()->create(['current_office' => 'Records Unit']);

        $this->actingAs($user)
            ->postJson(route('platform.documents.movements.store', $document), [
                'workflow' => ['source_office' => 'Records Unit', 'action' => 'unknown', 'remarks' => 'Invalid action'],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('workflow.action');

        $this->assertSame('Received', $document->fresh()->status);
        $this->assertSame(0, $document->movements()->count());
    }

    public function test_workspace_loads_older_activity_in_pages(): void
    {
        $user = User::factory()->create(['office' => 'Records Unit', 'permissions' => ['platform.index' => true]]);
        $document = Document::factory()->create(['current_office' => 'Records Unit']);
        foreach (range(1, 25) as $number) {
            DocumentMovement::factory()->create([
                'document_id' => $document->id,
                'acted_by' => $user->id,
                'from_office' => 'Records Unit',
                'to_office' => 'Office '.$number,
                'created_at' => now()->subMinutes(25 - $number),
            ]);
        }

        $this->actingAs($user)
            ->get(route('platform.documents.workspace', $document))
            ->assertSee('Load older activity');

        $this->get(route('platform.documents.workspace', $document).'?history_page=1')
            // The newest entry carries the Fast Track style "Latest" tag.
            ->assertSee('Forwarded to Office 25 <span class="dts-timeline-latest">Latest</span></strong>', false)
            ->assertDontSee('Forwarded to Office 1</strong>', false)
            ->assertSee('Showing 20 of 25 entries');

        $this->get(route('platform.documents.workspace', $document).'?history_page=2')
            ->assertSee('Forwarded to Office 1</strong>', false)
            ->assertDontSee('Forwarded to Office 25</strong>', false)
            ->assertDontSee('Load older activity')
            ->assertSee('All 25 entries shown');
    }

    public function test_workspace_accepts_history_pages_beyond_the_old_limit(): void
    {
        $user = User::factory()->create(['office' => 'Records Unit', 'permissions' => ['platform.index' => true]]);
        $document = Document::factory()->create(['current_office' => 'Records Unit']);

        $this->actingAs($user)
            ->get(route('platform.documents.workspace', $document).'?history_page=1001')
            ->assertOk()
            ->assertDontSee('Load older activity');
    }

    public function test_workspace_can_reach_retained_activity_after_twenty_thousand_events(): void
    {
        $user = User::factory()->create(['office' => 'Records Unit', 'permissions' => ['platform.index' => true]]);
        $document = Document::factory()->create(['current_office' => 'Records Unit']);
        $timestamp = now()->toDateTimeString();

        foreach (range(0, 40) as $batch) {
            $rows = [];
            foreach (range(1, $batch === 40 ? 1 : 500) as $position) {
                $rows[] = [
                    'document_id' => $document->id,
                    'action' => DocumentMovement::ACTION_FORWARDED,
                    'from_office' => 'Records Unit',
                    'to_office' => $batch === 0 && $position === 1 ? 'First office' : 'Other office',
                    'to_status' => DocumentMovement::STATUS_FORWARDED,
                    'acted_by' => $user->id,
                    'created_at' => $timestamp,
                    'updated_at' => $timestamp,
                ];
            }
            DB::table('document_movements')->insert($rows);
        }

        $this->actingAs($user)
            ->get(route('platform.documents.workspace', $document).'?history_page=1001')
            ->assertSee('Forwarded to First office</strong>', false)
            ->assertDontSee('Load older activity');
    }

    public function test_workspace_history_pages_filter_by_route_and_keep_visibility_rules(): void
    {
        $user = User::factory()->create(['office' => 'Legal Unit', 'permissions' => ['platform.index' => true]]);
        $document = Document::factory()->create(['current_office' => 'Records Unit']);
        $document->officeAssignments()->create(['office' => 'Legal Unit', 'status' => 'Awaiting receipt']);
        DocumentMovement::factory()->create(['document_id' => $document->id, 'acted_by' => $user->id, 'from_office' => 'Records Unit', 'to_office' => 'Legal Unit']);
        DocumentMovement::factory()->create(['document_id' => $document->id, 'acted_by' => $user->id, 'from_office' => 'Records Unit', 'to_office' => 'Budget Unit']);
        activity('documents')->causedBy($user)->performedOn($document)->withProperties(['office' => 'Legal Unit'])->log('Remark added');
        $hiddenDocument = Document::factory()->create(['current_office' => 'Administrative Unit']);

        $this->actingAs($user)
            ->get(route('platform.documents.workspace', $document).'?history_page=1&office=Legal%20Unit')
            ->assertSee('Forwarded to Legal Unit')
            ->assertSee('Remark added')
            ->assertDontSee('Forwarded to Budget Unit');

        $this->get(route('platform.documents.workspace', $document).'?history_page=1&office=No%20History%20Unit')
            ->assertForbidden();

        $this->get(route('platform.documents.workspace', $hiddenDocument).'?history_page=1')->assertNotFound();
        $this->getJson(route('platform.documents.workspace', $document).'?history_page=0')->assertUnprocessable();
    }

    public function test_recipient_history_excludes_other_offices_in_modal_and_document_page(): void
    {
        $user = User::factory()->create(['office' => 'Records Unit', 'permissions' => ['platform.index' => true]]);
        $document = Document::factory()->received()->create(['current_office' => 'Records Unit', 'remarks' => 'Other office latest remark']);
        $document->officeAssignments()->create(['office' => 'Budget Unit', 'status' => 'Received']);
        DocumentMovement::factory()->create([
            'document_id' => $document->id,
            'acted_by' => $user->id,
            'from_office' => 'Records Unit',
            'to_office' => 'Records Unit',
            'remarks' => 'My office remark',
        ]);
        DocumentMovement::factory()->create([
            'document_id' => $document->id,
            'acted_by' => $user->id,
            'from_office' => 'Budget Unit',
            'to_office' => 'Budget Unit',
            'remarks' => 'Budget private remark',
        ]);
        activity('documents')->causedBy($user)->performedOn($document)->withProperties(['office' => 'Budget Unit', 'remark' => 'Budget private activity'])->log('Remark added');
        $document->recordActivity('Document routes updated', $user, ['office' => 'Budget Unit', 'remark' => 'Budget private route update']);
        $document->recordActivity('Document details updated', $user, ['action' => 'update']);

        $this->actingAs($user)
            ->get(route('platform.documents.workspace', $document))
            ->assertSee('My office remark')
            ->assertSee('Document details updated')
            ->assertSee('data-workspace-route="Records Unit" data-workspace-route-history-access="true"', false)
            ->assertSee('data-workspace-route="Budget Unit" data-workspace-route-history-access="false"', false)
            ->assertDontSee('Budget private remark')
            ->assertDontSee('Budget private activity')
            ->assertDontSee('Document routes updated')
            ->assertDontSee('Budget private route update');

        $this->get(route('platform.documents.workspace', $document).'?history_page=1')
            ->assertSee('My office remark')
            ->assertSee('Document details updated')
            ->assertDontSee('Budget private remark')
            ->assertDontSee('Budget private activity')
            ->assertDontSee('Document routes updated')
            ->assertDontSee('Budget private route update');

        $this->get(route('platform.documents.workspace', $document).'?history_page=1&office=Budget%20Unit')
            ->assertForbidden();

        $this->get(route('platform.documents.view', $document))
            ->assertSee('My office remark')
            ->assertSee('Document details updated')
            ->assertDontSee('Document routes updated')
            ->assertDontSee('Budget private remark')
            ->assertDontSee('Other office latest remark');
    }

    public function test_administrator_can_view_every_office_history(): void
    {
        $adminRole = Role::create(['name' => 'Admin', 'slug' => User::ADMIN_ROLE_SLUG]);
        $admin = User::factory()->create(['office' => null]);
        $admin->replaceRoles([$adminRole->id]);
        $document = Document::factory()->received()->create(['current_office' => 'Records Unit']);
        DocumentMovement::factory()->create([
            'document_id' => $document->id,
            'acted_by' => $admin->id,
            'from_office' => 'Budget Unit',
            'to_office' => 'Budget Unit',
            'remarks' => 'Budget private remark',
        ]);

        $this->actingAs($admin)
            ->get(route('platform.documents.workspace', $document))
            ->assertSee('All document history')
            ->assertSee('Budget private remark');

        $this->get(route('platform.documents.workspace', $document).'?history_page=1&office=Budget%20Unit')
            ->assertSee('Budget private remark');
    }

    public function test_workspace_history_expands_saved_remarks(): void
    {
        $user = User::factory()->create(['office' => 'Records Unit', 'permissions' => ['platform.index' => true]]);
        $document = Document::factory()->create(['current_office' => 'Records Unit']);
        DocumentMovement::factory()->create([
            'document_id' => $document->id,
            'acted_by' => $user->id,
            'from_office' => 'Records Unit',
            'to_office' => 'Records Unit',
            'remarks' => '<script>Movement note</script>',
        ]);
        activity('documents')->causedBy($user)->performedOn($document)->withProperties(['remark' => 'Activity note', 'office' => 'Records Unit'])->log('Remark added');

        $this->actingAs($user)
            ->get(route('platform.documents.workspace', $document).'?history_page=1')
            // Remarks are shown in full, not behind a "View remarks" toggle.
            ->assertSee('<div class="dts-workspace-history-remarks"><strong>Remarks</strong>', false)
            ->assertDontSee('View remarks')
            ->assertSee('Activity note')
            ->assertSee('&lt;script&gt;Movement note&lt;/script&gt;', false)
            ->assertDontSee('<script>Movement note</script>', false);
    }

    public function test_workspace_route_dates_use_the_first_send_and_last_receipt(): void
    {
        $user = User::factory()->create(['office' => 'Records Unit', 'permissions' => ['platform.index' => true]]);
        $document = Document::factory()->received()->create(['current_office' => 'Records Unit']);
        foreach ([
            ['sent', '2026-10-09 00:00:00'],
            ['sent', '2026-10-09 05:00:00'],
            ['received', '2026-10-09 10:00:00'],
            ['received', '2026-10-10 02:00:00'],
        ] as [$action, $createdAt]) {
            DocumentMovement::factory()->create([
                'document_id' => $document->id,
                'acted_by' => $user->id,
                'action' => $action,
                'from_office' => 'Legal Unit',
                'to_office' => 'Records Unit',
                'remarks' => null,
                'created_at' => $createdAt,
            ]);
        }

        $this->actingAs($user)
            ->get(route('platform.documents.workspace', $document))
            ->assertSee('Sent Oct 09, 2026 · 8:00 AM')
            ->assertSee('Received Oct 10, 2026 · 10:00 AM');
    }

    /**
     * @return array<string, string>
     */
    private function validDocumentData(Document $document): array
    {
        return [
            'details_fingerprint' => $document->detailsFingerprint(),
            'title' => 'Updated workspace title',
            'type' => 'Memorandum',
            'reference' => 'DTS-TEST',
            'description' => 'Updated description',
            'origin' => 'Records Unit',
            'sender' => 'Test sender',
            'received' => '2026-10-01',
            'priority' => 'Urgent',
        ];
    }
}
