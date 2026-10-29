<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\DocumentMovement;
use App\Models\Office;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Testing\TestResponse;
use Orchid\Platform\Models\Role;
use Tests\TestCase;

class DocumentMovementTest extends TestCase
{
    use LazilyRefreshDatabase;

    private const SIGNATURE = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAEAAAAAYCAYAAABKtPtEAAAACXBIWXMAAA7EAAAOxAGVKw4bAAAAQUlEQVRYhe3QsRGAMAwDQCcbpGMD9h8nG9AxQuhyTIC583+nTlIEAACU1N5hHOfKKvKl+5p7d88s8gflDwAAoKoH38oEBtR+TtAAAAAASUVORK5CYII=';

    public function test_office_must_receive_before_processing_a_forwarded_document(): void
    {
        $user = User::factory()->create([
            'office' => 'Administrative Unit',
            'permissions' => ['platform.index' => true],
        ]);
        $document = Document::factory()->outgoing()->create([
            'current_office' => 'Administrative Unit',
            'remarks' => null,
        ]);

        $url = route('platform.documents.movements.store', $document);
        $this->actingAs($user)->postJson($url, [
            'workflow' => ['action' => 'completed'],
        ])->assertUnprocessable()->assertJsonValidationErrors('workflow.action');

        $this->postJson($url, [
            'workflow' => ['action' => 'forwarded', 'destinations' => ['Records Unit']],
        ])->assertUnprocessable()->assertJsonValidationErrors('workflow.action');

        $this->assertDatabaseCount('document_movements', 0);

        $this->postJson($url, [
            'workflow' => ['action' => 'received'],
        ])->assertOk();

        $document->refresh();

        $this->assertSame('Administrative Unit', $document->current_office);
        $this->assertSame('Received', $document->status);
        $this->assertDatabaseHas('document_movements', [
            'document_id' => $document->id,
            'action' => 'received',
            'from_office' => 'Administrative Unit',
            'to_office' => 'Administrative Unit',
            'from_status' => 'Forwarded',
            'to_status' => 'Received',
            'remarks' => null,
            'acted_by' => $user->id,
        ]);
        $this->assertSame(1, $document->movements()->count());

        $this->get(route('platform.documents.workspace', $document))
            ->assertOk()
            ->assertSee('Document received');
    }

    public function test_stale_current_office_cannot_act_when_another_office_has_the_only_assignment(): void
    {
        $budget = User::factory()->create(['office' => 'SDO-Budget Unit', 'permissions' => ['platform.index' => true]]);
        $document = Document::factory()->incoming()->create(['current_office' => 'SDO-Budget Unit']);
        $document->officeAssignments()->delete();
        $document->officeAssignments()->create([
            'office' => 'SDO-Legal Unit',
            'status' => DocumentMovement::STATUS_AWAITING_RECEIPT,
        ]);

        $this->assertFalse(Document::query()->visibleTo($budget)->whereKey($document->id)->exists());
        $this->actingAs($budget)->get(route('platform.documents.workspace', $document))->assertNotFound();
        $this->actingAs($budget)->postJson(route('platform.documents.movements.store', $document), [
            'workflow' => ['action' => 'received', 'source_office' => 'SDO-Budget Unit'],
        ])->assertForbidden();

        $this->assertDatabaseCount('document_movements', 0);
        $this->assertDatabaseHas('document_office_assignments', [
            'document_id' => $document->id,
            'office' => 'SDO-Legal Unit',
            'status' => DocumentMovement::STATUS_AWAITING_RECEIPT,
        ]);
    }

    public function test_legacy_document_without_assignments_still_uses_its_current_office(): void
    {
        $budget = User::factory()->create(['office' => 'SDO-Budget Unit', 'permissions' => ['platform.index' => true]]);
        $document = Document::factory()->incoming()->create(['current_office' => 'SDO-Budget Unit']);
        $document->officeAssignments()->delete();

        $this->assertTrue(Document::query()->visibleTo($budget)->whereKey($document->id)->exists());
        $this->actingAs($budget)->postJson(route('platform.documents.movements.store', $document), [
            'workflow' => ['action' => 'received', 'source_office' => 'SDO-Budget Unit'],
        ])->assertOk();

        $this->assertDatabaseHas('document_office_assignments', [
            'document_id' => $document->id,
            'office' => 'SDO-Budget Unit',
            'status' => DocumentMovement::STATUS_RECEIVED,
        ]);
    }

    public function test_every_assigned_office_can_receive_independently(): void
    {
        $recordsOffice = Office::create(['name' => 'Records Unit', 'sort_order' => 1, 'is_active' => true]);
        $administrativeOffice = Office::create(['name' => 'Administrative Unit', 'sort_order' => 2, 'is_active' => true]);
        $curriculumOffice = Office::create(['name' => 'Curriculum Implementation Division', 'sort_order' => 3, 'is_active' => true]);
        $user = User::factory()->create([
            'office' => null,
            'permissions' => ['platform.index' => true],
        ]);
        $user->offices()->sync([$recordsOffice->id, $administrativeOffice->id, $curriculumOffice->id]);

        $offices = ['Records Unit', 'Administrative Unit', 'Curriculum Implementation Division'];
        $document = Document::factory()->incoming()->create([
            'current_office' => 'Records Unit',
            'status' => 'Awaiting receipt',
            'remarks' => null,
        ]);
        foreach ($offices as $office) {
            $document->officeAssignments()->updateOrCreate(
                ['office' => $office],
                ['status' => 'Awaiting receipt', 'assigned_by' => $user->id],
            );
        }

        foreach ($offices as $office) {
            $this->actingAs($user)
                ->post(route('platform.documents.movements.store', $document), [
                    'workflow' => [
                        'source_office' => $office,
                        'action' => 'received',
                        'remarks' => "Received by {$office}.",
                    ],
                ])
                ->assertRedirectToRoute('platform.documents.view', $document)
                ->assertSessionHasNoErrors();

            foreach ($offices as $assignedOffice) {
                $expected = array_search($assignedOffice, $offices, true) <= array_search($office, $offices, true)
                    ? 'Received'
                    : 'Awaiting receipt';

                $this->assertDatabaseHas('document_office_assignments', [
                    'document_id' => $document->id,
                    'office' => $assignedOffice,
                    'status' => $expected,
                ]);
            }
        }

        $this->assertDatabaseCount('document_movements', 3);

        $this->get(route('platform.documents.workspace', $document))
            ->assertOk()
            ->assertSee('value="Curriculum Implementation Division"', false)
            ->assertSee('value="Administrative Unit"', false)
            ->assertSee('value="Records Unit"', false);
    }

    public function test_document_view_defaults_to_an_office_still_awaiting_receipt(): void
    {
        $recordsOffice = Office::create(['name' => 'Records Unit', 'sort_order' => 1, 'is_active' => true]);
        $administrativeOffice = Office::create(['name' => 'Administrative Unit', 'sort_order' => 2, 'is_active' => true]);
        $user = User::factory()->create([
            'office' => null,
            'permissions' => ['platform.index' => true],
        ]);
        $user->offices()->sync([$recordsOffice->id, $administrativeOffice->id]);

        $document = Document::factory()->incoming()->create([
            'current_office' => 'Records Unit',
            'status' => 'Awaiting receipt',
            'remarks' => null,
        ]);
        $document->officeAssignments()->create([
            'office' => 'Administrative Unit',
            'status' => 'Awaiting receipt',
            'assigned_by' => $user->id,
        ]);

        $this->actingAs($user)
            ->post(route('platform.documents.movements.store', $document), [
                'workflow' => [
                    'source_office' => 'Records Unit',
                    'action' => 'received',
                    'remarks' => 'Received by Records Unit.',
                ],
            ])
            ->assertRedirectToRoute('platform.documents.view', $document);

        $this->assertDatabaseHas('document_office_assignments', [
            'document_id' => $document->id,
            'office' => 'Records Unit',
            'status' => 'Received',
        ]);
        $this->assertDatabaseHas('document_office_assignments', [
            'document_id' => $document->id,
            'office' => 'Administrative Unit',
            'status' => 'Awaiting receipt',
        ]);

        // The dialog offers both offices; the one still awaiting receipt can only receive it.
        $response = $this->get(route('platform.documents.workspace', $document))
            ->assertOk()
            ->assertSee('name="workflow[source_office]" data-workflow-source-office', false)
            ->assertSee('<option value="Administrative Unit"', false)
            ->assertSee('<option value="Records Unit"', false);
        $options = $this->workspaceActionOptions($response);
        $this->assertArrayHasKey('received', $options['Administrative Unit']);
        $this->assertArrayNotHasKey('reviewed', $options['Administrative Unit']);
    }

    /**
     * The actions the workspace dialog offers each acting office (the data-action-options JSON).
     *
     * @return array<string, array<string, mixed>>
     */
    private function workspaceActionOptions(TestResponse $response): array
    {
        preg_match("/data-action-options='([^']+)'/", $response->getContent(), $match);

        return json_decode(html_entity_decode($match[1] ?? '[]'), true) ?? [];
    }

    public function test_workflow_validates_destination_without_requiring_remarks(): void
    {
        $user = User::factory()->create([
            'office' => 'Records Unit',
            'permissions' => ['platform.index' => true],
        ]);
        $document = Document::factory()->received()->create(['current_office' => 'Records Unit']);

        $this->actingAs($user)
            ->from(route('platform.documents.view', $document))
            ->post(route('platform.documents.movements.store', $document), [
                'workflow' => [
                    'action' => 'archive',
                    'destination' => 'Unknown Office',
                    'remarks' => '',
                ],
            ])
            ->assertRedirect(route('platform.documents.view', $document))
            ->assertSessionHasErrors(['workflow.destinations.0' => 'Select recognized destination offices.']);

        $this->assertSame('Received', $document->fresh()->status);
        $this->assertDatabaseCount('document_movements', 0);
    }

    public function test_staff_cannot_act_on_a_document_assigned_to_another_office(): void
    {
        $user = User::factory()->create([
            'office' => 'Records Unit',
            'permissions' => ['platform.index' => true],
        ]);
        $document = Document::factory()->outgoing()->create(['current_office' => 'Administrative Unit']);

        $this->actingAs($user)
            ->post(route('platform.documents.movements.store', $document), [
                'workflow' => [
                    'action' => 'received',
                    'remarks' => 'Attempted receipt.',
                ],
            ])
            ->assertForbidden();

        $this->assertSame('Forwarded', $document->fresh()->status);
        $this->assertDatabaseCount('document_movements', 0);
    }

    public function test_staff_can_act_on_documents_in_any_assigned_office(): void
    {
        $recordsOffice = Office::create(['name' => 'Records Unit', 'sort_order' => 1, 'is_active' => true]);
        $administrativeOffice = Office::create(['name' => 'Administrative Unit', 'sort_order' => 2, 'is_active' => true]);
        $user = User::factory()->create([
            'office' => null,
            'permissions' => ['platform.index' => true],
        ]);
        $user->offices()->sync([$recordsOffice->id, $administrativeOffice->id]);
        $document = Document::factory()->outgoing()->create([
            'current_office' => 'Administrative Unit',
        ]);

        $this->actingAs($user)
            ->post(route('platform.documents.movements.store', $document), [
                'workflow' => [
                    'action' => 'received',
                    'remarks' => 'Received by shared office handler.',
                ],
            ])
            ->assertRedirectToRoute('platform.documents.view', $document);

        $this->assertSame('Received', $document->fresh()->status);
    }

    public function test_multi_office_staff_can_choose_which_assigned_office_is_acting(): void
    {
        $recordsOffice = Office::create(['name' => 'Records Unit', 'sort_order' => 1, 'is_active' => true]);
        $administrativeOffice = Office::create(['name' => 'Administrative Unit', 'sort_order' => 2, 'is_active' => true]);
        $user = User::factory()->create([
            'office' => null,
            'permissions' => ['platform.index' => true],
        ]);
        $user->offices()->sync([$recordsOffice->id, $administrativeOffice->id]);
        $document = Document::factory()->received()->create([
            'current_office' => 'Records Unit',
            'status' => 'Received',
        ]);
        $document->officeAssignments()->where('office', 'Records Unit')->update(['status' => 'Received']);
        $document->officeAssignments()->create([
            'office' => 'Administrative Unit',
            'status' => 'Forwarded',
        ]);

        $response = $this->actingAs($user)
            ->get(route('platform.documents.workspace', $document))
            ->assertOk()
            ->assertSee('Acting office')
            ->assertSee('<option value="Records Unit"', false)
            ->assertSee('<option value="Administrative Unit"', false)
            ->assertSee('Choose an action');
        $options = $this->workspaceActionOptions($response);
        $this->assertArrayHasKey('forwarded', $options['Records Unit']);
        foreach (['reviewed', 'approved'] as $action) {
            $this->assertArrayNotHasKey($action, $options['Records Unit']);
        }

        $options = $this->workspaceActionOptions($this->get(route('platform.documents.workspace', $document))->assertOk());
        $this->assertArrayHasKey('received', $options['Administrative Unit']);
        $this->assertArrayNotHasKey('reviewed', $options['Administrative Unit']);

        $this->post(route('platform.documents.movements.store', $document), [
            'workflow' => [
                'source_office' => 'Administrative Unit',
                'action' => 'received',
                'remarks' => 'Administrative office received this assignment.',
            ],
        ])->assertRedirectToRoute('platform.documents.view', $document);

        $this->assertDatabaseHas('document_movements', [
            'document_id' => $document->id,
            'action' => 'received',
            'from_office' => 'Administrative Unit',
            'to_office' => 'Administrative Unit',
            'from_status' => 'Forwarded',
            'to_status' => 'Received',
            'remarks' => 'Administrative office received this assignment.',
            'acted_by' => $user->id,
        ]);
        $this->assertDatabaseHas('document_office_assignments', [
            'document_id' => $document->id,
            'office' => 'Records Unit',
            'status' => 'Received',
        ]);
        $this->assertDatabaseHas('document_office_assignments', [
            'document_id' => $document->id,
            'office' => 'Administrative Unit',
            'status' => 'Received',
        ]);
    }

    public function test_staff_cannot_choose_an_office_they_are_not_assigned_to_as_the_actor(): void
    {
        $user = User::factory()->create([
            'office' => 'Records Unit',
            'permissions' => ['platform.index' => true],
        ]);
        $document = Document::factory()->received()->create([
            'current_office' => 'Records Unit',
            'status' => 'Received',
        ]);
        $document->officeAssignments()->create([
            'office' => 'Administrative Unit',
            'status' => 'Forwarded',
        ]);

        $this->actingAs($user)
            ->post(route('platform.documents.movements.store', $document), [
                'workflow' => [
                    'source_office' => 'Administrative Unit',
                    'action' => 'received',
                    'remarks' => 'Attempted to act for admin.',
                ],
            ])
            ->assertForbidden();

        $this->assertDatabaseCount('document_movements', 0);
        $this->assertDatabaseHas('document_office_assignments', [
            'document_id' => $document->id,
            'office' => 'Administrative Unit',
            'status' => 'Forwarded',
        ]);
    }

    public function test_user_without_platform_access_cannot_update_the_workflow(): void
    {
        $user = User::factory()->create([
            'office' => 'Records Unit',
            'permissions' => [],
        ]);
        $document = Document::factory()->incoming()->create(['current_office' => 'Records Unit']);

        $this->actingAs($user)
            ->post(route('platform.documents.movements.store', $document), [
                'workflow' => ['action' => 'received'],
            ])
            ->assertForbidden();

        $this->assertDatabaseCount('document_movements', 0);
    }

    public function test_workflow_requires_sign_in(): void
    {
        $document = Document::factory()->create();

        $this->post(route('platform.documents.movements.store', $document), [
            'workflow' => ['action' => 'received'],
        ])->assertRedirectToRoute('platform.login');

        $this->assertDatabaseCount('document_movements', 0);
    }

    public function test_assigned_staff_can_forward_a_received_document(): void
    {
        $user = User::factory()->create([
            'office' => 'Records Unit',
            'permissions' => ['platform.index' => true],
        ]);
        $document = Document::factory()->received()->create(['current_office' => 'Records Unit']);

        $this->actingAs($user)
            ->post(route('platform.documents.movements.store', $document), [
                'workflow' => [
                    'action' => 'forwarded',
                    'destination' => 'Administrative Unit',
                    'remarks' => 'For review and appropriate action.',
                ],
            ])
            ->assertRedirectToRoute('platform.main');

        $document->refresh();

        $this->assertSame('Administrative Unit', $document->current_office);
        $this->assertSame('Forwarded', $document->status);
        $this->assertDatabaseHas('document_movements', [
            'document_id' => $document->id,
            'action' => 'forwarded',
            'from_office' => 'Records Unit',
            'to_office' => 'Administrative Unit',
            'from_status' => 'Received',
            'to_status' => 'Forwarded',
        ]);
        $this->assertDatabaseHas('document_office_assignments', [
            'document_id' => $document->id,
            'office' => 'Administrative Unit',
            'status' => 'Forwarded',
            'assigned_by' => $user->id,
        ]);
        $this->assertDatabaseMissing('activity_log', [
            'description' => 'Document forwarded',
            'subject_type' => Document::class,
            'subject_id' => $document->id,
            'causer_type' => User::class,
            'causer_id' => $user->id,
        ]);

    }

    public function test_forwarding_allows_destinations_without_remarks(): void
    {
        $user = User::factory()->create([
            'office' => 'Records Unit',
            'permissions' => ['platform.index' => true],
        ]);
        $document = Document::factory()->received()->create(['current_office' => 'Records Unit']);

        $this->actingAs($user)
            ->from(route('platform.documents.view', $document))
            ->post(route('platform.documents.movements.store', $document), [
                'workflow' => [
                    'action' => 'forwarded',
                    'destinations' => ['Administrative Unit', 'Curriculum Implementation Division'],
                ],
            ])
            ->assertRedirectToRoute('platform.main');

        $this->assertDatabaseHas('document_movements', [
            'document_id' => $document->id,
            'to_office' => 'Administrative Unit',
            'remarks' => null,
        ]);
        $this->assertDatabaseHas('document_movements', [
            'document_id' => $document->id,
            'to_office' => 'Curriculum Implementation Division',
            'remarks' => null,
        ]);
    }

    public function test_forwarding_allows_an_empty_destination_remark(): void
    {
        $user = User::factory()->create([
            'office' => 'Records Unit',
            'permissions' => ['platform.index' => true],
        ]);
        $document = Document::factory()->received()->create(['current_office' => 'Records Unit']);

        $this->actingAs($user)
            ->from(route('platform.documents.view', $document))
            ->post(route('platform.documents.movements.store', $document), [
                'workflow' => [
                    'action' => 'forwarded',
                    'destinations' => ['Administrative Unit', 'Curriculum Implementation Division'],
                    'destination_remarks' => ['For admin review.', ''],
                ],
            ])
            ->assertRedirectToRoute('platform.main');

        $this->assertDatabaseHas('document_movements', [
            'document_id' => $document->id,
            'to_office' => 'Administrative Unit',
            'remarks' => 'For admin review.',
        ]);
        $this->assertDatabaseHas('document_movements', [
            'document_id' => $document->id,
            'to_office' => 'Curriculum Implementation Division',
            'remarks' => null,
        ]);
    }

    public function test_staff_can_complete_a_received_document_without_remarks(): void
    {
        $user = User::factory()->create([
            'office' => 'Records Unit',
            'permissions' => ['platform.index' => true],
        ]);
        $document = Document::factory()->received()->create(['current_office' => 'Records Unit']);

        $this->actingAs($user)
            ->post(route('platform.documents.movements.store', $document), [
                'workflow' => ['action' => 'completed'],
            ])
            ->assertRedirectToRoute('platform.documents.view', $document);

        $this->assertDatabaseHas('document_movements', [
            'document_id' => $document->id,
            'action' => 'completed',
            'remarks' => null,
        ]);
    }

    public function test_assigned_staff_can_forward_a_received_document_to_multiple_offices(): void
    {
        $recordsUser = User::factory()->create([
            'office' => 'Records Unit',
            'permissions' => ['platform.index' => true],
        ]);
        $administrativeUser = User::factory()->create([
            'office' => 'Administrative Unit',
            'permissions' => ['platform.index' => true],
        ]);
        $curriculumUser = User::factory()->create([
            'office' => 'Curriculum Implementation Division',
            'permissions' => ['platform.index' => true],
        ]);
        $document = Document::factory()->received()->create(['current_office' => 'Records Unit']);

        $this->actingAs($recordsUser)
            ->post(route('platform.documents.movements.store', $document), [
                'workflow' => [
                    'action' => 'forwarded',
                    'destinations' => [
                        'Administrative Unit',
                        'Curriculum Implementation Division',
                    ],
                    'destination_remarks' => [
                        'Check the administrative requirements.',
                        'Review the curriculum content.',
                    ],
                ],
            ])
            ->assertRedirectToRoute('platform.main');

        $document->refresh();

        $this->assertSame('Administrative Unit', $document->current_office);
        $this->assertSame('Forwarded', $document->status);
        $this->assertDatabaseHas('document_movements', [
            'document_id' => $document->id,
            'action' => 'forwarded',
            'from_office' => 'Records Unit',
            'to_office' => 'Administrative Unit',
            'from_status' => 'Received',
            'to_status' => 'Forwarded',
            'remarks' => 'Check the administrative requirements.',
        ]);
        $this->assertDatabaseHas('document_movements', [
            'document_id' => $document->id,
            'action' => 'forwarded',
            'from_office' => 'Records Unit',
            'to_office' => 'Curriculum Implementation Division',
            'from_status' => 'Received',
            'to_status' => 'Forwarded',
            'remarks' => 'Review the curriculum content.',
        ]);
        $this->assertSame('Review the curriculum content.', $document->remarks);
        $this->assertDatabaseHas('document_office_assignments', [
            'document_id' => $document->id,
            'office' => 'Administrative Unit',
            'status' => 'Forwarded',
        ]);
        $this->assertDatabaseHas('document_office_assignments', [
            'document_id' => $document->id,
            'office' => 'Curriculum Implementation Division',
            'status' => 'Forwarded',
        ]);
        $this->assertDatabaseMissing('document_office_assignments', [
            'document_id' => $document->id,
            'office' => 'Records Unit',
        ]);

        // Records can still open what it sent on (its Outgoing tab), but can no longer act on it.
        $this->actingAs($recordsUser)
            ->get(route('platform.documents.workspace', $document))
            ->assertOk()
            ->assertDontSee('data-workspace-routing ', false);

        $this->post(route('platform.documents.movements.store', $document), [
            'workflow' => [
                'action' => 'received',
                'remarks' => 'Records should no longer act on this route.',
            ],
        ])->assertForbidden();

        $this->actingAs($administrativeUser)
            ->get(route('platform.documents.workspace', $document))
            ->assertOk()
            ->assertSee('Check the administrative requirements.')
            ->assertDontSee('Review the curriculum content.');

        $this->actingAs($administrativeUser)
            ->post(route('platform.documents.movements.store', $document), [
                'workflow' => [
                    'action' => 'received',
                    'remarks' => 'Administrative office received its copy.',
                ],
            ])
            ->assertRedirectToRoute('platform.documents.view', $document);

        $this->actingAs($curriculumUser)
            ->get(route('platform.documents.workspace', $document))
            ->assertOk()
            ->assertSee('Office routes')
            ->assertSee('Administrative Unit')
            ->assertSee('Curriculum Implementation Division')
            ->assertSee('Routing action')
            ->assertSee('Received');

        $this->post(route('platform.documents.movements.store', $document), [
            'workflow' => [
                'action' => 'received',
                'remarks' => 'Curriculum received its copy after admin.',
            ],
        ])->assertRedirectToRoute('platform.documents.view', $document);

        $this->assertDatabaseHas('document_office_assignments', [
            'document_id' => $document->id,
            'office' => 'Administrative Unit',
            'status' => 'Received',
        ]);
        $this->assertDatabaseHas('document_office_assignments', [
            'document_id' => $document->id,
            'office' => 'Curriculum Implementation Division',
            'status' => 'Received',
        ]);
    }

    public function test_later_forwarding_keeps_the_other_office_route_independent(): void
    {
        $recordsUser = User::factory()->create([
            'office' => 'Records Unit',
            'permissions' => ['platform.index' => true],
        ]);
        $administrativeUser = User::factory()->create([
            'office' => 'Administrative Unit',
            'permissions' => ['platform.index' => true],
        ]);
        $curriculumUser = User::factory()->create([
            'office' => 'Curriculum Implementation Division',
            'permissions' => ['platform.index' => true],
        ]);
        $budgetUser = User::factory()->create([
            'office' => 'SDO-Budget Unit',
            'permissions' => ['platform.index' => true],
        ]);
        $document = Document::factory()->received()->create(['current_office' => 'Records Unit']);

        $this->actingAs($recordsUser)
            ->post(route('platform.documents.movements.store', $document), [
                'workflow' => [
                    'action' => 'forwarded',
                    'destinations' => ['Administrative Unit', 'Curriculum Implementation Division'],
                    'destination_remarks' => ['Administrative review.', 'Curriculum review.'],
                ],
            ])
            ->assertRedirectToRoute('platform.main');

        $this->actingAs($administrativeUser)
            ->post(route('platform.documents.movements.store', $document), [
                'workflow' => [
                    'action' => 'received',
                    'remarks' => 'Administrative office received its copy.',
                ],
            ])
            ->assertRedirectToRoute('platform.documents.view', $document);

        $this->from(route('platform.documents.view', $document))
            ->post(route('platform.documents.movements.store', $document), [
                'workflow' => [
                    'action' => 'forwarded',
                    'source_office' => 'Administrative Unit',
                    'destinations' => ['Curriculum Implementation Division'],
                    'destination_remarks' => ['Duplicate route.'],
                ],
            ])
            ->assertRedirect(route('platform.documents.view', $document))
            ->assertSessionHasErrors('workflow.destinations');

        $this->assertDatabaseCount('document_movements', 3);
        $this->assertDatabaseHas('document_office_assignments', [
            'document_id' => $document->id,
            'office' => 'Administrative Unit',
            'status' => 'Received',
        ]);
        $this->assertDatabaseHas('document_office_assignments', [
            'document_id' => $document->id,
            'office' => 'Curriculum Implementation Division',
            'status' => 'Forwarded',
        ]);

        $this->post(route('platform.documents.movements.store', $document), [
            'workflow' => [
                'action' => 'forwarded',
                'source_office' => 'Administrative Unit',
                'destinations' => ['SDO-Budget Unit'],
                'destination_remarks' => ['Budget review after administrative receipt.'],
            ],
        ])->assertRedirectToRoute('platform.main');

        $this->assertDatabaseMissing('document_office_assignments', [
            'document_id' => $document->id,
            'office' => 'Administrative Unit',
        ]);
        $this->assertDatabaseHas('document_office_assignments', [
            'document_id' => $document->id,
            'office' => 'Curriculum Implementation Division',
            'status' => 'Forwarded',
        ]);
        $this->assertDatabaseHas('document_office_assignments', [
            'document_id' => $document->id,
            'office' => 'SDO-Budget Unit',
            'status' => 'Forwarded',
        ]);
        $this->assertDatabaseHas('document_movements', [
            'document_id' => $document->id,
            'action' => 'forwarded',
            'from_office' => 'Administrative Unit',
            'to_office' => 'SDO-Budget Unit',
            'remarks' => 'Budget review after administrative receipt.',
            'acted_by' => $administrativeUser->id,
        ]);

        // The administrative office can still open what it sent on, but can no longer act on it.
        $this->get(route('platform.documents.workspace', $document))
            ->assertOk()
            ->assertDontSee('data-workspace-routing ', false);
        $this->post(route('platform.documents.movements.store', $document), [
            'workflow' => [
                'action' => 'received',
                'remarks' => 'Administrative office should no longer act.',
            ],
        ])->assertForbidden();

        $this->actingAs($curriculumUser)
            ->get(route('platform.documents.workspace', $document))
            ->assertOk()
            ->assertSee('Curriculum review.')
            ->assertDontSee('Budget review after administrative receipt.');

        $this->actingAs($budgetUser)
            ->get(route('platform.documents.workspace', $document))
            ->assertOk();
    }

    public function test_two_offices_can_forward_separate_branches_without_losing_either_route(): void
    {
        $records = User::factory()->create(['office' => 'Records Unit', 'permissions' => ['platform.index' => true]]);
        $administrative = User::factory()->create(['office' => 'Administrative Unit', 'permissions' => ['platform.index' => true]]);
        $curriculum = User::factory()->create(['office' => 'Curriculum Implementation Division', 'permissions' => ['platform.index' => true]]);
        $document = Document::factory()->received()->create(['current_office' => 'Records Unit']);
        $url = route('platform.documents.movements.store', $document);

        $this->actingAs($records)->postJson($url, [
            'workflow' => ['action' => 'forwarded', 'source_office' => 'Records Unit', 'destinations' => ['Administrative Unit', 'Curriculum Implementation Division']],
        ])->assertOk();

        $this->actingAs($administrative)->postJson($url, [
            'workflow' => ['action' => 'received', 'source_office' => 'Administrative Unit'],
        ])->assertOk();
        $this->actingAs($curriculum)->postJson($url, [
            'workflow' => ['action' => 'received', 'source_office' => 'Curriculum Implementation Division'],
        ])->assertOk();

        $this->actingAs($administrative)->postJson($url, [
            'workflow' => ['action' => 'forwarded', 'source_office' => 'Administrative Unit', 'destinations' => ['SDO-Budget Unit']],
        ])->assertOk();
        $this->actingAs($curriculum)->postJson($url, [
            'workflow' => ['action' => 'forwarded', 'source_office' => 'Curriculum Implementation Division', 'destinations' => ['SDO-Legal Unit']],
        ])->assertOk();

        $this->assertDatabaseHas('document_office_assignments', ['document_id' => $document->id, 'office' => 'SDO-Budget Unit', 'status' => DocumentMovement::STATUS_FORWARDED]);
        $this->assertDatabaseHas('document_office_assignments', ['document_id' => $document->id, 'office' => 'SDO-Legal Unit', 'status' => DocumentMovement::STATUS_FORWARDED]);
        $this->assertDatabaseHas('document_movements', ['document_id' => $document->id, 'from_office' => 'Administrative Unit', 'to_office' => 'SDO-Budget Unit']);
        $this->assertDatabaseHas('document_movements', ['document_id' => $document->id, 'from_office' => 'Curriculum Implementation Division', 'to_office' => 'SDO-Legal Unit']);
        $this->assertSame(DocumentMovement::STATUS_FORWARDED, $document->fresh()->status);
    }

    public function test_multi_office_action_requires_an_explicit_acting_office(): void
    {
        $admin = User::factory()->create(['permissions' => ['platform.index' => true]]);
        $admin->replaceRoles([Role::query()->firstOrCreate(['slug' => User::ADMIN_ROLE_SLUG], ['name' => 'Admin', 'permissions' => []])->id]);
        $document = Document::factory()->incoming()->create(['current_office' => 'SDO-Budget Unit']);
        $document->officeAssignments()->create(['office' => 'SDO-Legal Unit', 'status' => DocumentMovement::STATUS_AWAITING_RECEIPT]);

        $url = route('platform.documents.movements.store', $document);
        $this->actingAs($admin->fresh())->postJson($url, [
            'workflow' => ['action' => 'received'],
        ])->assertUnprocessable()->assertJsonValidationErrors('workflow.source_office');

        $this->assertDatabaseCount('document_movements', 0);
        $this->postJson($url, [
            'workflow' => ['action' => 'received', 'source_office' => 'SDO-Legal Unit'],
        ])->assertOk();
        $this->assertDatabaseHas('document_office_assignments', [
            'document_id' => $document->id,
            'office' => 'SDO-Legal Unit',
            'status' => DocumentMovement::STATUS_RECEIVED,
        ]);
    }

    public function test_forwarding_to_an_already_assigned_office_preserves_its_independent_status(): void
    {
        $user = User::factory()->create([
            'office' => 'Records Unit',
            'permissions' => ['platform.index' => true],
        ]);
        $document = Document::factory()->received()->create(['current_office' => 'Records Unit']);
        $document->officeAssignments()->create(['office' => 'Administrative Unit', 'status' => 'Received']);

        $this->actingAs($user)
            ->from(route('platform.documents.view', $document))
            ->post(route('platform.documents.movements.store', $document), [
                'workflow' => [
                    'action' => 'forwarded',
                    'source_office' => 'Records Unit',
                    'destinations' => ['Administrative Unit'],
                    'destination_remarks' => ['Please review this document.'],
                ],
            ])
            ->assertRedirect(route('platform.documents.view', $document))
            ->assertSessionHasErrors([
                'workflow.destinations' => 'This document is already assigned to Administrative Unit. Choose another destination office.',
            ]);

        $this->assertSame('Records Unit', $document->fresh()->current_office);
        $this->assertDatabaseHas('document_office_assignments', [
            'document_id' => $document->id,
            'office' => 'Administrative Unit',
            'status' => 'Received',
        ]);
        $this->assertDatabaseCount('document_movements', 0);
    }

    public function test_document_is_completed_only_after_every_assigned_office_completes(): void
    {
        $recordsUser = User::factory()->create([
            'office' => 'Records Unit',
            'permissions' => ['platform.index' => true],
        ]);
        $administrativeUser = User::factory()->create([
            'office' => 'Administrative Unit',
            'permissions' => ['platform.index' => true],
        ]);
        $document = Document::factory()->received()->create(['current_office' => 'Records Unit']);
        $document->officeAssignments()->create([
            'office' => 'Administrative Unit',
            'status' => 'Forwarded',
        ]);

        $this->actingAs($recordsUser)
            ->from(route('platform.documents.view', $document))
            ->post(route('platform.documents.movements.store', $document), [
                'workflow' => [
                    'action' => 'completed',
                    'remarks' => 'Records work is complete.',
                ],
            ])
            ->assertRedirectToRoute('platform.documents.view', $document)
            ->assertSessionHasErrors('workflow.action');

        $document->refresh();

        $this->assertSame('Records Unit', $document->current_office);
        $this->assertSame('Received', $document->status);
        $this->assertDatabaseHas('document_office_assignments', [
            'document_id' => $document->id,
            'office' => 'Records Unit',
            'status' => 'Received',
        ]);

        $this->actingAs($administrativeUser)
            ->post(route('platform.documents.movements.store', $document), [
                'workflow' => ['action' => 'received', 'source_office' => 'Administrative Unit'],
            ])
            ->assertRedirectToRoute('platform.documents.view', $document);

        $this->actingAs($administrativeUser)
            ->post(route('platform.documents.movements.store', $document), [
                'workflow' => [
                    'action' => 'completed',
                    'remarks' => 'Administrative work is complete.',
                ],
            ])
            ->assertRedirectToRoute('platform.documents.view', $document);

        $this->assertSame('Records Unit', $document->fresh()->current_office);
        $this->assertSame('Received', $document->fresh()->status);

        $this->actingAs($recordsUser)
            ->post(route('platform.documents.movements.store', $document), [
                'workflow' => ['action' => 'completed'],
            ])
            ->assertRedirectToRoute('platform.documents.view', $document);

        $this->assertSame('Completed', $document->fresh()->status);
    }

    public function test_parallel_route_keeps_an_unfinished_processing_office_current_before_records(): void
    {
        $administrative = User::factory()->create(['office' => 'Administrative Unit', 'permissions' => ['platform.index' => true]]);
        $document = Document::factory()->received()->create(['current_office' => 'SDO-Records Unit']);
        $document->officeAssignments()->create(['office' => 'Administrative Unit', 'status' => DocumentMovement::STATUS_RECEIVED]);
        $document->officeAssignments()->create(['office' => 'Curriculum Implementation Division', 'status' => DocumentMovement::STATUS_FORWARDED]);

        $this->actingAs($administrative)->post(route('platform.documents.movements.store', $document), [
            'workflow' => ['action' => 'completed', 'source_office' => 'Administrative Unit'],
        ])->assertRedirectToRoute('platform.documents.view', $document);

        $this->assertSame('Curriculum Implementation Division', $document->fresh()->current_office);
        $this->assertSame(DocumentMovement::STATUS_FORWARDED, $document->fresh()->status);
        $this->assertDatabaseHas('document_office_assignments', [
            'document_id' => $document->id,
            'office' => 'SDO-Records Unit',
            'status' => DocumentMovement::STATUS_RECEIVED,
        ]);
    }

    public function test_last_office_can_complete_without_automatically_returning_to_records(): void
    {
        $firstOffice = User::factory()->create(['office' => 'Administrative Unit', 'permissions' => ['platform.index' => true]]);
        $secondOffice = User::factory()->create(['office' => 'Curriculum Implementation Division', 'permissions' => ['platform.index' => true]]);
        $records = User::factory()->create(['office' => 'SDO-Records Unit', 'permissions' => ['platform.index' => true]]);
        $document = Document::factory()->received()->create(['current_office' => 'Administrative Unit']);
        $document->officeAssignments()->create(['office' => 'Curriculum Implementation Division', 'status' => 'Received']);

        $this->actingAs($firstOffice)->post(route('platform.documents.movements.store', $document), [
            'workflow' => ['action' => 'completed', 'source_office' => 'Administrative Unit'],
        ])->assertRedirectToRoute('platform.documents.view', $document);

        $this->assertSame('Curriculum Implementation Division', $document->fresh()->current_office);
        $this->assertSame('Received', $document->fresh()->status);

        $this->actingAs($secondOffice)->post(route('platform.documents.movements.store', $document), [
            'workflow' => ['action' => 'completed', 'source_office' => 'Curriculum Implementation Division'],
        ])->assertRedirectToRoute('platform.documents.view', $document);

        $this->assertSame('Curriculum Implementation Division', $document->fresh()->current_office);
        $this->assertSame('Completed', $document->fresh()->status);
        $this->assertDatabaseMissing('document_office_assignments', ['document_id' => $document->id, 'office' => 'SDO-Records Unit']);
        $this->assertSame(0, $records->notifications()->count());
    }

    public function test_office_can_explicitly_forward_to_records_for_release(): void
    {
        $staff = User::factory()->create(['office' => 'Administrative Unit', 'permissions' => ['platform.index' => true]]);
        $records = User::factory()->create(['office' => 'SDO-Records Unit', 'permissions' => ['platform.index' => true]]);
        $document = Document::factory()->received()->create(['current_office' => 'Administrative Unit']);

        $this->actingAs($staff)->post(route('platform.documents.movements.store', $document), [
            'workflow' => [
                'action' => 'forwarded',
                'source_office' => 'Administrative Unit',
                'destination' => 'SDO-Records Unit',
                'remarks' => 'For release at the counter.',
            ],
        ])->assertRedirectToRoute('platform.main');

        $this->assertSame('SDO-Records Unit', $document->fresh()->current_office);
        $this->assertSame(DocumentMovement::STATUS_FORWARDED, $document->fresh()->status);
        $this->assertDatabaseMissing('document_office_assignments', ['document_id' => $document->id, 'office' => 'Administrative Unit']);
        $this->assertDatabaseHas('document_office_assignments', [
            'document_id' => $document->id,
            'office' => 'SDO-Records Unit',
            'status' => DocumentMovement::STATUS_FORWARDED,
        ]);
        $this->assertDatabaseHas('document_movements', [
            'document_id' => $document->id,
            'action' => DocumentMovement::ACTION_FORWARDED,
            'from_office' => 'Administrative Unit',
            'to_office' => 'SDO-Records Unit',
            'remarks' => 'For release at the counter.',
        ]);
        $this->assertSame(1, $records->notifications()->count());
    }

    public function test_only_records_can_release_an_explicitly_forwarded_document_after_receipt(): void
    {
        $staff = User::factory()->create(['office' => 'Administrative Unit', 'permissions' => ['platform.index' => true]]);
        $records = User::factory()->create(['office' => 'SDO-Records Unit', 'permissions' => ['platform.index' => true]]);
        $document = Document::factory()->received()->create(['current_office' => 'Administrative Unit']);
        $url = route('platform.documents.movements.store', $document);

        $this->actingAs($staff)->postJson($url, [
            'workflow' => ['action' => 'released', 'source_office' => 'Administrative Unit', 'released_to' => 'Juan Dela Cruz', 'recipient_signature' => self::SIGNATURE],
        ])->assertUnprocessable()->assertJsonValidationErrors('workflow.action');

        $this->postJson($url, [
            'workflow' => ['action' => 'forwarded', 'source_office' => 'Administrative Unit', 'destination' => 'SDO-Records Unit'],
        ])->assertOk();

        $this->actingAs($records)->postJson($url, [
            'workflow' => ['action' => 'released', 'source_office' => 'SDO-Records Unit', 'released_to' => 'Juan Dela Cruz', 'recipient_signature' => self::SIGNATURE],
        ])->assertUnprocessable()->assertJsonValidationErrors('workflow.action');

        $this->postJson($url, [
            'workflow' => ['action' => 'received', 'source_office' => 'SDO-Records Unit'],
        ])->assertOk();

        $this->postJson($url, [
            'workflow' => ['action' => 'released', 'source_office' => 'SDO-Records Unit', 'released_to' => 'Juan Dela Cruz', 'recipient_signature' => self::SIGNATURE],
        ])->assertOk();

        $this->assertSame('SDO-Records Unit', $document->fresh()->current_office);
        $this->assertSame(DocumentMovement::STATUS_RELEASED, $document->fresh()->status);
        $this->assertSame(['forwarded', 'received', 'released'], $document->movements()->reorder('id')->pluck('action')->all());
        $this->assertSame('Juan Dela Cruz', $document->movements()->latest('id')->value('released_to'));

        $this->get(route('platform.documents.workspace', $document))
            ->assertSee('<dt>Released to</dt><dd>Juan Dela Cruz</dd>', false);

        $this->get(route('tracking.index', ['tracking' => $document->tracking_number]))
            ->assertSee('Released to')
            ->assertSee('Juan Dela Cruz');
    }

    public function test_an_administrator_can_release_a_received_document_from_any_office(): void
    {
        $adminRole = Role::create(['name' => 'Admin', 'slug' => User::ADMIN_ROLE_SLUG, 'permissions' => ['platform.index' => true]]);
        $admin = User::factory()->create(['permissions' => ['platform.index' => true]]);
        $admin->replaceRoles([$adminRole->id]);
        $budget = User::factory()->create(['office' => 'SDO-Budget Unit', 'permissions' => ['platform.index' => true]]);
        $document = Document::factory()->received()->create(['current_office' => 'Administrative Unit']);
        $url = route('platform.documents.movements.store', $document);
        $this->actingAs($admin)->postJson($url, [
            'workflow' => ['action' => 'forwarded', 'source_office' => 'Administrative Unit', 'destination' => 'SDO-Budget Unit'],
        ])->assertOk();
        $this->actingAs($budget)->postJson($url, [
            'workflow' => ['action' => 'received', 'source_office' => 'SDO-Budget Unit'],
        ])->assertOk();

        // The office's own staff cannot release; only Records and administrators can.
        $this->postJson($url, [
            'workflow' => ['action' => 'released', 'source_office' => 'SDO-Budget Unit', 'released_to' => 'Juan Dela Cruz', 'recipient_signature' => self::SIGNATURE],
        ])->assertUnprocessable()->assertJsonValidationErrors('workflow.action');

        $this->actingAs($admin)->get(route('platform.documents.workspace', $document))
            ->assertSee('"released":"Release"', false);
        $this->postJson($url, [
            'workflow' => ['action' => 'released', 'source_office' => 'SDO-Budget Unit', 'released_to' => 'Juan Dela Cruz', 'recipient_signature' => self::SIGNATURE],
        ])->assertOk();

        $this->assertSame(DocumentMovement::STATUS_RELEASED, $document->fresh()->status);
        $this->assertSame('Juan Dela Cruz', $document->movements()->latest('id')->value('released_to'));
    }

    public function test_an_administrator_cannot_release_while_another_office_is_still_working(): void
    {
        $adminRole = Role::create(['name' => 'Admin', 'slug' => User::ADMIN_ROLE_SLUG, 'permissions' => ['platform.index' => true]]);
        $admin = User::factory()->create(['permissions' => ['platform.index' => true]]);
        $admin->replaceRoles([$adminRole->id]);
        $budget = User::factory()->create(['office' => 'SDO-Budget Unit', 'permissions' => ['platform.index' => true]]);
        $document = Document::factory()->received()->create(['current_office' => 'Administrative Unit']);
        $url = route('platform.documents.movements.store', $document);
        $this->actingAs($admin)->postJson($url, [
            'workflow' => ['action' => 'forwarded', 'source_office' => 'Administrative Unit', 'destinations' => ['SDO-Budget Unit', 'SDO-Legal Unit']],
        ])->assertOk();
        $this->actingAs($budget)->postJson($url, [
            'workflow' => ['action' => 'received', 'source_office' => 'SDO-Budget Unit'],
        ])->assertOk();

        $this->actingAs($admin)->postJson($url, [
            'workflow' => ['action' => 'released', 'source_office' => 'SDO-Budget Unit', 'released_to' => 'Juan Dela Cruz', 'recipient_signature' => self::SIGNATURE],
        ])->assertUnprocessable()->assertJsonValidationErrors('workflow.action');
    }

    public function test_release_works_without_the_receivers_name_or_signature_for_now(): void
    {
        $records = User::factory()->create(['office' => 'SDO-Records Unit', 'permissions' => ['platform.index' => true]]);
        $document = Document::factory()->incoming()->create(['current_office' => 'SDO-Records Unit']);
        $url = route('platform.documents.movements.store', $document);
        $this->actingAs($records)->postJson($url, ['workflow' => ['action' => 'received', 'source_office' => 'SDO-Records Unit']])->assertOk();

        // A signature that is given must still be a real PNG.
        $this->postJson($url, [
            'workflow' => ['action' => 'released', 'source_office' => 'SDO-Records Unit', 'recipient_signature' => 'data:image/png;base64,bm90IGEgcG5n'],
        ])->assertUnprocessable()->assertJsonValidationErrors('workflow.recipient_signature');
        $this->assertSame(DocumentMovement::STATUS_RECEIVED, $document->fresh()->status);

        // A blank name and no signature: released with neither.
        $this->postJson($url, [
            'workflow' => ['action' => 'released', 'source_office' => 'SDO-Records Unit', 'released_to' => '   '],
        ])->assertOk();

        $release = $document->movements()->latest('id')->first();
        $this->assertSame(DocumentMovement::STATUS_RELEASED, $document->fresh()->status);
        $this->assertSame(DocumentMovement::ACTION_RELEASED, $release->action);
        $this->assertNull($release->released_to);
        $this->assertNull($release->recipient_signature);

        $this->get(route('tracking.index', ['tracking' => $document->tracking_number]))
            ->assertDontSee('Recipient signature</dt>', false);
    }

    public function test_release_forms_ask_for_the_counter_signature(): void
    {
        $records = User::factory()->create(['office' => 'SDO-Records Unit', 'permissions' => ['platform.index' => true]]);
        $document = Document::factory()->received()->create(['current_office' => 'SDO-Records Unit']);

        foreach ([route('platform.documents.workspace', $document)] as $url) {
            $this->actingAs($records)->get($url)
                ->assertOk()
                ->assertSee('<dts-signature-pad', false)
                ->assertSee('name="workflow[recipient_signature]"', false)
                ->assertSee("Recipient's signature", false)
                ->assertDontSee('sign on the Fast Track page')
                ->assertDontSee('signing on the Fast Track page');
        }
    }

    public function test_release_stores_a_valid_recipient_signature_encrypted(): void
    {
        $records = User::factory()->create(['office' => 'SDO-Records Unit', 'permissions' => ['platform.index' => true]]);
        $document = Document::factory()->incoming()->create(['current_office' => 'SDO-Records Unit']);
        $signature = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAEAAAAAYCAYAAABKtPtEAAAACXBIWXMAAA7EAAAOxAGVKw4bAAAAQUlEQVRYhe3QsRGAMAwDQCcbpGMD9h8nG9AxQuhyTIC583+nTlIEAACU1N5hHOfKKvKl+5p7d88s8gflDwAAoKoH38oEBtR+TtAAAAAASUVORK5CYII=';

        $this->actingAs($records)->postJson(route('platform.documents.movements.store', $document), [
            'workflow' => ['action' => 'received', 'source_office' => 'SDO-Records Unit'],
        ])->assertOk();

        $this->postJson(route('platform.documents.movements.store', $document), [
            'workflow' => [
                'action' => 'released',
                'source_office' => 'SDO-Records Unit',
                'released_to' => 'Juan Dela Cruz',
                'recipient_signature' => $signature,
            ],
        ])->assertOk();

        $movement = $document->movements()->latest('id')->firstOrFail();
        $this->assertSame($signature, $movement->recipient_signature);
        $this->assertNotSame($signature, $movement->getRawOriginal('recipient_signature'));
        $this->assertArrayNotHasKey('recipient_signature', $movement->toArray());

        $this->get(route('platform.documents.workspace', $document))
            ->assertSee('<dt>Signature</dt>', false)
            ->assertSee('src="'.$signature.'"', false);
        $this->get(route('platform.documents.workspace', $document))
            ->assertSee('class="dts-history-signature" src="'.$signature.'"', false);
        $this->get(route('tracking.index', ['tracking' => $document->tracking_number]))
            ->assertDontSee($signature, false);
    }

    public function test_release_rejects_a_non_png_signature_without_changing_the_document(): void
    {
        $records = User::factory()->create(['office' => 'SDO-Records Unit', 'permissions' => ['platform.index' => true]]);
        $document = Document::factory()->received()->create(['current_office' => 'SDO-Records Unit']);

        $this->actingAs($records)->postJson(route('platform.documents.movements.store', $document), [
            'workflow' => [
                'action' => 'released',
                'source_office' => 'SDO-Records Unit',
                'released_to' => 'Juan Dela Cruz',
                'recipient_signature' => 'data:image/svg+xml;base64,'.base64_encode('<svg onload="alert(1)"/>'),
            ],
        ])->assertUnprocessable()->assertJsonValidationErrors('workflow.recipient_signature');

        $this->assertSame(DocumentMovement::STATUS_RECEIVED, $document->fresh()->status);
        $this->assertSame(0, $document->movements()->where('action', DocumentMovement::ACTION_RELEASED)->count());
    }

    public function test_release_rejects_a_one_pixel_or_blank_signature(): void
    {
        $records = User::factory()->create(['office' => 'SDO-Records Unit', 'permissions' => ['platform.index' => true]]);
        $blank = imagecreatetruecolor(64, 24);
        imagesavealpha($blank, true);
        imagefill($blank, 0, 0, imagecolorallocatealpha($blank, 0, 0, 0, 127));
        ob_start();
        imagepng($blank);
        $blankSignature = 'data:image/png;base64,'.base64_encode(ob_get_clean());

        foreach ([
            'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/lXcAAAAASUVORK5CYII=',
            $blankSignature,
        ] as $signature) {
            $document = Document::factory()->incoming()->create(['current_office' => 'SDO-Records Unit']);
            $url = route('platform.documents.movements.store', $document);
            $this->actingAs($records)->postJson($url, [
                'workflow' => ['action' => 'received', 'source_office' => 'SDO-Records Unit'],
            ])->assertOk();

            $this->postJson($url, [
                'workflow' => [
                    'action' => 'released',
                    'source_office' => 'SDO-Records Unit',
                    'released_to' => 'Juan Dela Cruz',
                    'recipient_signature' => $signature,
                ],
            ])->assertUnprocessable()->assertJsonValidationErrors('workflow.recipient_signature');

            $this->assertSame(DocumentMovement::STATUS_RECEIVED, $document->fresh()->status);
            $this->assertSame(0, $document->movements()->where('action', DocumentMovement::ACTION_RELEASED)->count());
        }
    }

    public function test_records_cannot_release_while_another_office_route_is_active(): void
    {
        $staff = User::factory()->create(['office' => 'Administrative Unit', 'permissions' => ['platform.index' => true]]);
        $otherStaff = User::factory()->create(['office' => 'Curriculum Implementation Division', 'permissions' => ['platform.index' => true]]);
        $records = User::factory()->create(['office' => 'SDO-Records Unit', 'permissions' => ['platform.index' => true]]);
        $document = Document::factory()->received()->create(['current_office' => 'Administrative Unit']);
        $document->officeAssignments()->create(['office' => 'Curriculum Implementation Division', 'status' => DocumentMovement::STATUS_RECEIVED]);
        $url = route('platform.documents.movements.store', $document);

        $this->actingAs($staff)->postJson($url, [
            'workflow' => ['action' => 'forwarded', 'source_office' => 'Administrative Unit', 'destination' => 'SDO-Records Unit'],
        ])->assertOk();
        $this->actingAs($records)->postJson($url, [
            'workflow' => ['action' => 'received', 'source_office' => 'SDO-Records Unit'],
        ])->assertOk();

        $this->postJson($url, [
            'workflow' => ['action' => 'released', 'source_office' => 'SDO-Records Unit', 'released_to' => 'Juan Dela Cruz', 'recipient_signature' => self::SIGNATURE],
        ])->assertUnprocessable()->assertJsonValidationErrors('workflow.action');

        $this->actingAs($otherStaff)->postJson($url, [
            'workflow' => ['action' => 'completed', 'source_office' => 'Curriculum Implementation Division'],
        ])->assertOk();
        $this->actingAs($records)->postJson($url, [
            'workflow' => ['action' => 'released', 'source_office' => 'SDO-Records Unit', 'released_to' => 'Juan Dela Cruz', 'recipient_signature' => self::SIGNATURE],
        ])->assertOk();

        $this->assertSame(DocumentMovement::STATUS_RELEASED, $document->fresh()->status);
        $this->assertSame(1, $document->movements()->where('action', 'released')->count());
    }

    public function test_office_can_return_paper_to_records_when_records_retained_an_initial_route(): void
    {
        $records = User::factory()->create(['office' => 'SDO-Records Unit', 'permissions' => ['platform.index' => true]]);
        $budget = User::factory()->create(['office' => 'SDO-Budget Unit', 'permissions' => ['platform.index' => true]]);
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
        $document->officeAssignments()->create(['office' => 'SDO-Budget Unit', 'status' => DocumentMovement::STATUS_RECEIVED]);
        $url = route('platform.documents.movements.store', $document);

        $this->actingAs($budget)->postJson($url, [
            'workflow' => ['action' => 'forwarded', 'source_office' => 'SDO-Budget Unit', 'destination' => 'SDO-Records Unit'],
        ])->assertOk();

        $this->assertDatabaseHas('document_office_assignments', [
            'document_id' => $document->id,
            'office' => 'SDO-Records Unit',
            'status' => DocumentMovement::STATUS_FORWARDED,
        ]);
        $this->actingAs($records)->postJson($url, [
            'workflow' => ['action' => 'released', 'source_office' => 'SDO-Records Unit', 'released_to' => 'Juan Dela Cruz', 'recipient_signature' => self::SIGNATURE],
        ])->assertUnprocessable()->assertJsonValidationErrors('workflow.action');
        $this->postJson($url, [
            'workflow' => ['action' => 'received', 'source_office' => 'SDO-Records Unit'],
        ])->assertOk();
        $this->postJson($url, [
            'workflow' => ['action' => 'released', 'source_office' => 'SDO-Records Unit', 'released_to' => 'Juan Dela Cruz', 'recipient_signature' => self::SIGNATURE],
        ])->assertOk();

        $this->assertSame(DocumentMovement::STATUS_RELEASED, $document->fresh()->status);
        $this->assertSame('Juan Dela Cruz', $document->movements()->latest('id')->value('released_to'));
    }

    public function test_two_offices_can_return_paper_to_records_sequentially(): void
    {
        $records = User::factory()->create(['office' => 'SDO-Records Unit', 'permissions' => ['platform.index' => true]]);
        $budget = User::factory()->create(['office' => 'SDO-Budget Unit', 'permissions' => ['platform.index' => true]]);
        $legal = User::factory()->create(['office' => 'SDO-Legal Unit', 'permissions' => ['platform.index' => true]]);
        $document = Document::factory()->received()->create(['current_office' => 'SDO-Budget Unit']);
        $document->officeAssignments()->create(['office' => 'SDO-Legal Unit', 'status' => DocumentMovement::STATUS_RECEIVED]);
        $url = route('platform.documents.movements.store', $document);

        $this->actingAs($budget)->postJson($url, [
            'workflow' => ['action' => 'forwarded', 'source_office' => 'SDO-Budget Unit', 'destination' => 'SDO-Records Unit'],
        ])->assertOk();

        $this->actingAs($legal)->postJson($url, [
            'workflow' => ['action' => 'forwarded', 'source_office' => 'SDO-Legal Unit', 'destination' => 'SDO-Records Unit'],
        ])->assertUnprocessable()->assertJsonValidationErrors('workflow.destinations');

        $this->actingAs($records)->postJson($url, [
            'workflow' => ['action' => 'received', 'source_office' => 'SDO-Records Unit'],
        ])->assertOk();

        $this->actingAs($legal)->postJson($url, [
            'workflow' => ['action' => 'forwarded', 'source_office' => 'SDO-Legal Unit', 'destination' => 'SDO-Records Unit'],
        ])->assertOk();

        $this->actingAs($records)->postJson($url, [
            'workflow' => ['action' => 'received', 'source_office' => 'SDO-Records Unit'],
        ])->assertOk();
        $this->postJson($url, [
            'workflow' => ['action' => 'released', 'source_office' => 'SDO-Records Unit', 'released_to' => 'Juan Dela Cruz', 'recipient_signature' => self::SIGNATURE],
        ])->assertOk();

        $this->assertSame(DocumentMovement::STATUS_RELEASED, $document->fresh()->status);
        $this->assertSame(2, $document->movements()->where('action', DocumentMovement::ACTION_FORWARDED)->count());
    }

    public function test_office_cannot_replace_a_records_route_without_a_receipt_movement(): void
    {
        $budget = User::factory()->create(['office' => 'SDO-Budget Unit', 'permissions' => ['platform.index' => true]]);
        $document = Document::factory()->received()->create(['current_office' => 'SDO-Budget Unit']);
        $document->officeAssignments()->create([
            'office' => 'SDO-Records Unit',
            'status' => DocumentMovement::STATUS_RECEIVED,
        ]);

        $this->actingAs($budget)->postJson(route('platform.documents.movements.store', $document), [
            'workflow' => ['action' => 'forwarded', 'source_office' => 'SDO-Budget Unit', 'destination' => 'SDO-Records Unit'],
        ])->assertUnprocessable()->assertJsonValidationErrors('workflow.destinations');

        $this->assertDatabaseHas('document_office_assignments', [
            'document_id' => $document->id,
            'office' => 'SDO-Records Unit',
            'status' => DocumentMovement::STATUS_RECEIVED,
        ]);
        $this->assertSame(0, $document->movements()->where('action', DocumentMovement::ACTION_FORWARDED)->count());
    }

    public function test_records_can_restore_a_released_document_if_pickup_was_recorded_in_error(): void
    {
        $records = User::factory()->create(['office' => 'SDO-Records Unit', 'permissions' => ['platform.index' => true]]);
        $document = Document::factory()->create([
            'current_office' => 'SDO-Records Unit',
            'status' => DocumentMovement::STATUS_RELEASED,
        ]);

        $this->actingAs($records)->postJson(route('platform.documents.movements.store', $document), [
            'workflow' => [
                'action' => 'restore',
                'source_office' => 'SDO-Records Unit',
                'destination' => 'SDO-Records Unit',
                'remarks' => 'Pickup was recorded in error.',
            ],
        ])->assertOk();

        $this->assertSame(DocumentMovement::STATUS_RECEIVED, $document->fresh()->status);
        $this->assertDatabaseHas('document_movements', [
            'document_id' => $document->id,
            'action' => 'restore',
            'from_status' => DocumentMovement::STATUS_RELEASED,
            'remarks' => 'Pickup was recorded in error.',
        ]);
    }

    public function test_a_historical_approved_route_can_finish_under_the_new_actions(): void
    {
        $staff = User::factory()->create(['office' => 'Administrative Unit', 'permissions' => ['platform.index' => true]]);
        $document = Document::factory()->create([
            'current_office' => 'Administrative Unit',
            'status' => DocumentMovement::STATUS_APPROVED,
        ]);

        $this->actingAs($staff)->postJson(route('platform.documents.movements.store', $document), [
            'workflow' => ['action' => 'completed', 'source_office' => 'Administrative Unit'],
        ])->assertOk();

        $this->assertSame(DocumentMovement::STATUS_COMPLETED, $document->fresh()->status);
        $this->assertDatabaseHas('document_movements', [
            'document_id' => $document->id,
            'action' => 'completed',
            'from_status' => DocumentMovement::STATUS_APPROVED,
        ]);
    }

    public function test_old_processing_actions_are_no_longer_accepted(): void
    {
        $staff = User::factory()->create(['office' => 'Administrative Unit', 'permissions' => ['platform.index' => true]]);
        $document = Document::factory()->received()->create(['current_office' => 'Administrative Unit']);
        $url = route('platform.documents.movements.store', $document);

        foreach (['acknowledge', 'forward', 'approved', 'rejected', 'on_hold', 'reviewed', 'returned', 'resubmitted', 'archive'] as $action) {
            $this->actingAs($staff)->postJson($url, [
                'workflow' => ['action' => $action, 'destination' => 'Records Unit'],
            ])->assertUnprocessable()->assertJsonValidationErrors('workflow.action');
        }

        $this->assertDatabaseCount('document_movements', 0);
        $this->assertSame(DocumentMovement::STATUS_RECEIVED, $document->fresh()->status);
    }

    public function test_staff_handoff_cannot_exceed_five_destinations(): void
    {
        $staff = User::factory()->create(['office' => 'Administrative Unit', 'permissions' => ['platform.index' => true]]);
        $document = Document::factory()->received()->create(['current_office' => 'Administrative Unit']);

        $this->actingAs($staff)->from(route('platform.documents.view', $document))
            ->post(route('platform.documents.movements.store', $document), [
                'workflow' => [
                    'action' => 'forwarded',
                    'source_office' => 'Administrative Unit',
                    'destinations' => array_slice(Document::officeOptions(), 0, 6),
                ],
            ])
            ->assertRedirect(route('platform.documents.view', $document))
            ->assertSessionHasErrors(['workflow.destinations' => 'Select no more than five destination offices.']);

        $this->assertSame('Administrative Unit', $document->fresh()->current_office);
        $this->assertDatabaseCount('document_movements', 0);
    }

    public function test_handoff_cannot_exceed_five_active_office_routes_in_total(): void
    {
        $staff = User::factory()->create(['office' => 'Administrative Unit', 'permissions' => ['platform.index' => true]]);
        $document = Document::factory()->received()->create(['current_office' => 'Administrative Unit']);
        $offices = array_values(array_diff(Document::officeOptions(), ['Administrative Unit']));
        foreach (array_slice($offices, 0, 4) as $office) {
            $document->officeAssignments()->create(['office' => $office, 'status' => DocumentMovement::STATUS_FORWARDED]);
        }

        $this->actingAs($staff)->from(route('platform.documents.view', $document))
            ->post(route('platform.documents.movements.store', $document), [
                'workflow' => [
                    'action' => 'forwarded',
                    'source_office' => 'Administrative Unit',
                    'destinations' => array_slice($offices, 4, 2),
                ],
            ])
            ->assertRedirect(route('platform.documents.view', $document))
            ->assertSessionHasErrors('workflow.destinations');

        $this->assertSame(5, $document->officeAssignments()->count());
        $this->assertSame(0, $document->movements()->count());
    }

    public function test_handoff_can_replace_its_source_at_the_five_active_route_limit(): void
    {
        $staff = User::factory()->create(['office' => 'Administrative Unit', 'permissions' => ['platform.index' => true]]);
        $document = Document::factory()->received()->create(['current_office' => 'Administrative Unit']);
        $offices = array_values(array_diff(Document::officeOptions(), ['Administrative Unit', 'SDO-Records Unit']));
        foreach (array_slice($offices, 0, 4) as $office) {
            $document->officeAssignments()->create(['office' => $office, 'status' => DocumentMovement::STATUS_FORWARDED]);
        }

        $this->actingAs($staff)->post(route('platform.documents.movements.store', $document), [
            'workflow' => [
                'action' => 'forwarded',
                'source_office' => 'Administrative Unit',
                'destinations' => [$offices[4]],
            ],
        ])->assertRedirectToRoute('platform.main');

        $this->assertSame(5, $document->officeAssignments()->count());
        $this->assertDatabaseHas('document_office_assignments', [
            'document_id' => $document->id,
            'office' => $offices[4],
            'status' => DocumentMovement::STATUS_FORWARDED,
        ]);
    }

    public function test_stale_second_completion_from_the_same_office_does_not_create_another_movement(): void
    {
        $firstUser = User::factory()->create(['office' => 'Records Unit', 'permissions' => ['platform.index' => true]]);
        $secondUser = User::factory()->create(['office' => 'Records Unit', 'permissions' => ['platform.index' => true]]);
        $document = Document::factory()->received()->create(['current_office' => 'Records Unit']);
        $url = route('platform.documents.movements.store', $document);
        $workflow = ['workflow' => ['source_office' => 'Records Unit', 'action' => 'completed']];

        $this->actingAs($firstUser)->postJson($url, $workflow)->assertOk();
        $this->actingAs($secondUser)->postJson($url, $workflow)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('workflow.action');

        $this->assertSame('Completed', $document->fresh()->status);
        $this->assertSame(1, $document->movements()->where('action', 'completed')->count());
    }

    public function test_completion_records_a_reason_without_duplicate_activity(): void
    {
        $user = User::factory()->create([
            'office' => 'Records Unit',
            'permissions' => ['platform.index' => true],
        ]);
        $document = Document::factory()->received()->create(['current_office' => 'Records Unit']);

        $this->actingAs($user)
            ->post(route('platform.documents.movements.store', $document), [
                'workflow' => [
                    'action' => 'completed',
                    'remarks' => 'Processing is complete.',
                ],
            ])
            ->assertRedirectToRoute('platform.documents.view', $document);

        $this->assertSame('Completed', $document->fresh()->status);
        $this->assertDatabaseHas('document_movements', [
            'document_id' => $document->id,
            'action' => 'completed',
            'to_office' => 'Records Unit',
            'to_status' => 'Completed',
            'remarks' => 'Processing is complete.',
        ]);
        $this->assertDatabaseMissing('activity_log', [
            'description' => 'Document completed',
            'subject_type' => Document::class,
            'subject_id' => $document->id,
        ]);

        $this->get(route('platform.documents.workspace', $document))
            ->assertOk()
            ->assertSee($user->name);
    }

    public function test_completing_one_office_route_keeps_another_unfinished_route_active(): void
    {
        $user = User::factory()->create(['office' => 'Administrative Unit', 'permissions' => ['platform.index' => true]]);
        $document = Document::factory()->received()->create(['current_office' => 'Administrative Unit']);
        $document->officeAssignments()->create([
            'office' => 'Records Unit',
            'status' => 'Awaiting receipt',
        ]);

        $this->actingAs($user)
            ->post(route('platform.documents.movements.store', $document), [
                'workflow' => ['action' => 'completed', 'source_office' => 'Administrative Unit'],
            ])
            ->assertRedirectToRoute('platform.documents.view', $document);

        $this->assertSame('Records Unit', $document->fresh()->current_office);
        $this->assertSame('Awaiting receipt', $document->fresh()->status);
        $this->assertDatabaseHas('document_office_assignments', [
            'document_id' => $document->id,
            'office' => 'Administrative Unit',
            'status' => 'Completed',
        ]);
        $this->assertDatabaseHas('document_office_assignments', [
            'document_id' => $document->id,
            'office' => 'Records Unit',
            'status' => 'Awaiting receipt',
        ]);
    }

    public function test_records_can_complete_after_another_office_route_is_archived(): void
    {
        $user = User::factory()->create(['office' => 'Records Unit', 'permissions' => ['platform.index' => true]]);
        $document = Document::factory()->received()->create(['current_office' => 'Records Unit']);
        $document->officeAssignments()->create([
            'office' => 'Administrative Unit',
            'status' => 'Archived',
        ]);

        $this->actingAs($user)
            ->post(route('platform.documents.movements.store', $document), [
                'workflow' => ['action' => 'completed', 'source_office' => 'Records Unit'],
            ])
            ->assertRedirectToRoute('platform.documents.view', $document);

        $this->assertSame('Completed', $document->fresh()->status);
    }

    public function test_records_cannot_complete_while_another_office_is_still_working(): void
    {
        $user = User::factory()->create(['office' => 'Records Unit', 'permissions' => ['platform.index' => true]]);
        $document = Document::factory()->received()->create(['current_office' => 'Records Unit']);
        $document->officeAssignments()->create(['office' => 'Administrative Unit', 'status' => 'Archived']);
        $document->officeAssignments()->create(['office' => 'Legal Unit', 'status' => 'Received']);

        $this->actingAs($user)
            ->from(route('platform.documents.view', $document))
            ->post(route('platform.documents.movements.store', $document), [
                'workflow' => ['action' => 'completed', 'source_office' => 'Records Unit'],
            ])
            ->assertRedirectToRoute('platform.documents.view', $document)
            ->assertSessionHasErrors('workflow.action');

        $this->assertSame('Records Unit', $document->fresh()->current_office);
        $this->assertSame('Received', $document->fresh()->status);
    }

    public function test_administrator_can_restore_an_archived_document_to_an_office(): void
    {
        $adminRole = Role::create(['name' => 'Admin', 'slug' => User::ADMIN_ROLE_SLUG, 'permissions' => []]);
        $user = User::factory()->create(['permissions' => []]);
        $user->replaceRoles([$adminRole->id]);
        $document = Document::factory()->archived()->create(['current_office' => 'Records Unit']);

        $this->actingAs($user)
            ->post(route('platform.documents.movements.store', $document), [
                'workflow' => [
                    'action' => 'restore',
                    'destination' => 'Curriculum Implementation Division',
                    'remarks' => 'Additional supporting records were requested.',
                ],
            ])
            ->assertRedirectToRoute('platform.documents.view', $document);

        $document->refresh();

        $this->assertSame('Curriculum Implementation Division', $document->current_office);
        $this->assertSame('Received', $document->status);
        $this->assertDatabaseHas('document_movements', [
            'document_id' => $document->id,
            'action' => 'restore',
            'from_status' => 'Archived',
            'to_status' => 'Received',
            'remarks' => 'Additional supporting records were requested.',
        ]);
    }

    public function test_assigned_staff_outside_records_cannot_restore_an_archived_document(): void
    {
        $staff = User::factory()->create(['office' => 'Administrative Unit', 'permissions' => ['platform.index' => true]]);
        $document = Document::factory()->archived()->create(['current_office' => 'Administrative Unit']);

        $this->actingAs($staff)->post(route('platform.documents.movements.store', $document), [
            'workflow' => ['action' => 'restore', 'destination' => 'Curriculum Implementation Division'],
        ])->assertForbidden();

        $this->assertSame(DocumentMovement::STATUS_ARCHIVED, $document->fresh()->status);
        $this->assertSame(0, $document->movements()->count());
    }

    public function test_records_staff_can_restore_an_archived_document(): void
    {
        $records = User::factory()->create(['office' => 'SDO-Records Unit', 'permissions' => ['platform.index' => true]]);
        $document = Document::factory()->archived()->create(['current_office' => 'SDO-Records Unit']);

        $this->actingAs($records)->post(route('platform.documents.movements.store', $document), [
            'workflow' => ['action' => 'restore', 'destination' => 'Curriculum Implementation Division'],
        ])->assertRedirectToRoute('platform.main');

        $this->assertSame('Curriculum Implementation Division', $document->fresh()->current_office);
        $this->assertSame(DocumentMovement::STATUS_RECEIVED, $document->fresh()->status);
    }

    public function test_records_can_restore_a_completed_document_to_an_office_that_finished_earlier(): void
    {
        $records = User::factory()->create(['office' => 'SDO-Records Unit', 'permissions' => ['platform.index' => true]]);
        $document = Document::factory()->create([
            'current_office' => 'SDO-Records Unit',
            'status' => DocumentMovement::STATUS_COMPLETED,
        ]);
        $document->officeAssignments()->create([
            'office' => 'Administrative Unit',
            'status' => DocumentMovement::STATUS_COMPLETED,
        ]);

        $this->actingAs($records)->postJson(route('platform.documents.movements.store', $document), [
            'workflow' => [
                'action' => 'restore',
                'source_office' => 'SDO-Records Unit',
                'destination' => 'Administrative Unit',
            ],
        ])->assertOk();

        $this->assertSame('Administrative Unit', $document->fresh()->current_office);
        $this->assertSame(DocumentMovement::STATUS_RECEIVED, $document->fresh()->status);
        $this->assertDatabaseHas('document_office_assignments', [
            'document_id' => $document->id,
            'office' => 'Administrative Unit',
            'status' => DocumentMovement::STATUS_RECEIVED,
        ]);
    }

    public function test_records_can_restore_its_own_completed_route(): void
    {
        $records = User::factory()->create(['office' => 'SDO-Records Unit', 'permissions' => ['platform.index' => true]]);
        $document = Document::factory()->create([
            'current_office' => 'SDO-Records Unit',
            'status' => DocumentMovement::STATUS_COMPLETED,
        ]);

        $this->actingAs($records)->postJson(route('platform.documents.movements.store', $document), [
            'workflow' => [
                'action' => 'restore',
                'source_office' => 'SDO-Records Unit',
                'destination' => 'SDO-Records Unit',
            ],
        ])->assertOk();

        $this->assertSame('SDO-Records Unit', $document->fresh()->current_office);
        $this->assertSame(DocumentMovement::STATUS_RECEIVED, $document->fresh()->status);
        $this->assertDatabaseHas('document_office_assignments', [
            'document_id' => $document->id,
            'office' => 'SDO-Records Unit',
            'status' => DocumentMovement::STATUS_RECEIVED,
        ]);
    }

    public function test_restore_cannot_overwrite_an_office_that_is_still_working(): void
    {
        $records = User::factory()->create(['office' => 'SDO-Records Unit', 'permissions' => ['platform.index' => true]]);
        $document = Document::factory()->received()->create(['current_office' => 'Administrative Unit']);
        $document->officeAssignments()->create([
            'office' => 'SDO-Records Unit',
            'status' => DocumentMovement::STATUS_ARCHIVED,
        ]);

        $this->actingAs($records)->postJson(route('platform.documents.movements.store', $document), [
            'workflow' => [
                'action' => 'restore',
                'source_office' => 'SDO-Records Unit',
                'destination' => 'Administrative Unit',
            ],
        ])->assertUnprocessable()->assertJsonValidationErrors('workflow.destinations');

        $this->assertSame('Administrative Unit', $document->fresh()->current_office);
        $this->assertSame(DocumentMovement::STATUS_RECEIVED, $document->fresh()->status);
        $this->assertDatabaseHas('document_office_assignments', [
            'document_id' => $document->id,
            'office' => 'Administrative Unit',
            'status' => DocumentMovement::STATUS_RECEIVED,
        ]);
    }

    public function test_workflow_rejects_an_action_that_is_not_valid_for_the_current_status(): void
    {
        $user = User::factory()->create([
            'office' => 'Records Unit',
            'permissions' => ['platform.index' => true],
        ]);
        $document = Document::factory()->incoming()->create(['current_office' => 'Records Unit']);

        $this->actingAs($user)
            ->from(route('platform.documents.view', $document))
            ->post(route('platform.documents.movements.store', $document), [
                'workflow' => [
                    'action' => 'released',
                    'released_to' => 'Juan Dela Cruz',
                    'recipient_signature' => self::SIGNATURE,
                    'remarks' => 'Attempted invalid transition.',
                ],
            ])
            ->assertRedirect(route('platform.documents.view', $document))
            ->assertSessionHasErrors([
                'workflow.action' => 'This document cannot be released while its status is Pending.',
            ]);

        $this->assertDatabaseCount('document_movements', 0);
    }

    public function test_forwarding_requires_a_different_destination_office(): void
    {
        $user = User::factory()->create([
            'office' => 'Records Unit',
            'permissions' => ['platform.index' => true],
        ]);
        $document = Document::factory()->received()->create(['current_office' => 'Records Unit']);

        $this->actingAs($user)
            ->from(route('platform.documents.view', $document))
            ->post(route('platform.documents.movements.store', $document), [
                'workflow' => [
                    'action' => 'forwarded',
                    'destination' => 'Records Unit',
                    'remarks' => 'Forward for review.',
                ],
            ])
            ->assertRedirect(route('platform.documents.view', $document))
            ->assertSessionHasErrors([
                'workflow.destinations' => 'Select a different office for this routing update.',
            ]);

        $this->assertDatabaseCount('document_movements', 0);
    }

    public function test_forwarding_requires_unique_destination_offices(): void
    {
        $user = User::factory()->create([
            'office' => 'Records Unit',
            'permissions' => ['platform.index' => true],
        ]);
        $document = Document::factory()->received()->create(['current_office' => 'Records Unit']);

        $this->actingAs($user)
            ->from(route('platform.documents.view', $document))
            ->post(route('platform.documents.movements.store', $document), [
                'workflow' => [
                    'action' => 'forwarded',
                    'destinations' => [
                        'Administrative Unit',
                        'Administrative Unit',
                    ],
                    'remarks' => 'Forward for review.',
                ],
            ])
            ->assertRedirect(route('platform.documents.view', $document))
            ->assertSessionHasErrors([
                'workflow.destinations.0' => 'Select each destination office only once.',
            ]);

        $this->assertDatabaseCount('document_movements', 0);
    }

    public function test_document_can_be_forwarded_back_to_a_previous_office_with_independent_remarks(): void
    {
        $recordsOfficer = User::factory()->create([
            'office' => 'Records Unit',
            'permissions' => ['platform.index' => true],
        ]);
        $adminOfficer = User::factory()->create([
            'office' => 'Administrative Unit',
            'permissions' => ['platform.index' => true],
        ]);
        $document = Document::factory()->received()->create([
            'current_office' => 'Records Unit',
        ]);

        $this->actingAs($recordsOfficer)
            ->post(route('platform.documents.movements.store', $document), [
                'workflow' => [
                    'action' => 'forwarded',
                    'destination' => 'Administrative Unit',
                    'remarks' => 'Send to admin for supporting documents.',
                ],
            ])
            ->assertRedirectToRoute('platform.main');

        $this->actingAs($adminOfficer)
            ->post(route('platform.documents.movements.store', $document), [
                'workflow' => [
                    'action' => 'received',
                    'remarks' => 'Admin received and reviewed the request.',
                ],
            ])
            ->assertRedirectToRoute('platform.documents.view', $document);

        $this->post(route('platform.documents.movements.store', $document), [
            'workflow' => [
                'action' => 'forwarded',
                'destination' => 'Records Unit',
                'remarks' => 'Required supporting document attached.',
            ],
        ])->assertRedirectToRoute('platform.main');

        $this->actingAs($recordsOfficer)
            ->post(route('platform.documents.movements.store', $document), [
                'workflow' => [
                    'action' => 'received',
                    'remarks' => 'Records received the resubmitted packet.',
                ],
            ])
            ->assertRedirectToRoute('platform.documents.view', $document);

        $document->refresh();

        $this->assertSame('Records Unit', $document->current_office);
        $this->assertSame('Received', $document->status);
        $this->assertSame(
            ['forwarded', 'received', 'forwarded', 'received'],
            $document->movements()->reorder()->oldest()->pluck('action')->all(),
        );
        $this->assertDatabaseHas('document_movements', [
            'document_id' => $document->id,
            'to_office' => 'Records Unit',
            'action' => 'forwarded',
            'remarks' => 'Required supporting document attached.',
        ]);
        $this->assertDatabaseHas('document_movements', [
            'document_id' => $document->id,
            'to_office' => 'Records Unit',
            'action' => 'received',
            'remarks' => 'Records received the resubmitted packet.',
        ]);

        $this->get(route('platform.documents.workspace', $document))
            ->assertOk()
            ->assertSee($recordsOfficer->name)
            ->assertSee('Records Unit')
            ->assertDontSee('Who:')
            ->assertDontSee('Where:')
            ->assertDontSee('When:')
            ->assertSeeInOrder([
                'Document received',
                'Records received the resubmitted packet.',
                'Forwarded to Records Unit',
                'Required supporting document attached.',
                'Forwarded to Administrative Unit',
                'Send to admin for supporting documents.',
            ])
            ->assertDontSee('Admin received and reviewed the request.');
    }

    public function test_workflow_does_not_suggest_or_preselect_a_destination(): void
    {
        $user = User::factory()->create([
            'office' => 'Records Unit',
            'permissions' => ['platform.index' => true],
        ]);
        $document = Document::factory()->received()->create([
            'current_office' => 'Records Unit',
            'status' => 'Received',
        ]);

        $this->actingAs($user)
            ->get(route('platform.documents.workspace', $document))
            ->assertOk()
            ->assertDontSee('Suggested next office')
            ->assertDontSee('value="Administrative Unit" selected', false)
            ->assertSee('name="workflow[destinations][]"', false)
            ->assertSee('name="workflow[destination_remarks][0]"', false)
            ->assertSee('Add destination');

        $this->post(route('platform.documents.movements.store', $document), [
            'workflow' => [
                'action' => 'forwarded',
                'destination' => 'Curriculum Implementation Division',
                'remarks' => 'Manual route change approved by records.',
            ],
        ])->assertRedirectToRoute('platform.main');

        $document->refresh();

        $this->assertSame('Curriculum Implementation Division', $document->current_office);
        $this->assertSame('Forwarded', $document->status);
        $this->assertDatabaseHas('document_movements', [
            'document_id' => $document->id,
            'action' => 'forwarded',
            'from_office' => 'Records Unit',
            'to_office' => 'Curriculum Implementation Division',
            'remarks' => 'Manual route change approved by records.',
        ]);

        $curriculumUser = User::factory()->create([
            'office' => 'Curriculum Implementation Division',
            'permissions' => ['platform.index' => true],
        ]);

        $this->actingAs($curriculumUser)
            ->get(route('platform.documents.workspace', $document))
            ->assertOk()
            ->assertDontSee('Suggested next office')
            ->assertDontSee('Planned route');
    }

    public function test_document_can_be_sent_back_to_an_office_that_finished_its_part(): void
    {
        $legal = User::factory()->create(['office' => 'SDO-Legal Unit', 'permissions' => ['platform.index' => true]]);
        $budget = User::factory()->create(['office' => 'SDO-Budget Unit', 'permissions' => ['platform.index' => true]]);
        $document = Document::factory()->create(['current_office' => 'SDO-Legal Unit', 'status' => DocumentMovement::STATUS_RECEIVED]);
        $received = $document->movements()->create([
            'action' => DocumentMovement::ACTION_RECEIVED, 'from_office' => 'SDO-Legal Unit', 'to_office' => 'SDO-Legal Unit',
            'from_status' => DocumentMovement::STATUS_FORWARDED, 'to_status' => DocumentMovement::STATUS_RECEIVED,
        ]);
        $document->officeAssignments()->updateOrCreate(['office' => 'SDO-Legal Unit'], ['status' => DocumentMovement::STATUS_RECEIVED, 'movement_id' => $received->id]);
        $document->officeAssignments()->create(['office' => 'SDO-Budget Unit', 'status' => DocumentMovement::STATUS_COMPLETED]);

        $this->actingAs($legal)->postJson(route('platform.documents.movements.store', $document), [
            'workflow' => ['action' => 'forwarded', 'source_office' => 'SDO-Legal Unit', 'destinations' => ['SDO-Budget Unit'], 'remarks' => 'Please re-check the figures.'],
        ])->assertOk();

        // Budget's finished row is reopened, not duplicated, and Budget can take the document again.
        $this->assertSame(1, $document->officeAssignments()->where('office', 'SDO-Budget Unit')->count());
        $this->assertSame(DocumentMovement::STATUS_FORWARDED, $document->officeAssignments()->where('office', 'SDO-Budget Unit')->value('status'));
        $this->assertDatabaseMissing('document_office_assignments', ['document_id' => $document->id, 'office' => 'SDO-Legal Unit']);

        $this->actingAs($budget)->postJson(route('platform.documents.movements.store', $document), [
            'workflow' => ['action' => 'received', 'source_office' => 'SDO-Budget Unit'],
        ])->assertOk();
        $this->assertSame(DocumentMovement::STATUS_RECEIVED, $document->fresh()->status);
    }

    public function test_document_still_cannot_be_forwarded_to_an_office_that_is_working_on_it(): void
    {
        $legal = User::factory()->create(['office' => 'SDO-Legal Unit', 'permissions' => ['platform.index' => true]]);
        $document = Document::factory()->create(['current_office' => 'SDO-Legal Unit', 'status' => DocumentMovement::STATUS_RECEIVED]);
        $received = $document->movements()->create([
            'action' => DocumentMovement::ACTION_RECEIVED, 'from_office' => 'SDO-Legal Unit', 'to_office' => 'SDO-Legal Unit',
            'from_status' => DocumentMovement::STATUS_FORWARDED, 'to_status' => DocumentMovement::STATUS_RECEIVED,
        ]);
        $document->officeAssignments()->updateOrCreate(['office' => 'SDO-Legal Unit'], ['status' => DocumentMovement::STATUS_RECEIVED, 'movement_id' => $received->id]);
        $document->officeAssignments()->create(['office' => 'SDO-Budget Unit', 'status' => DocumentMovement::STATUS_RECEIVED]);

        $this->actingAs($legal)->postJson(route('platform.documents.movements.store', $document), [
            'workflow' => ['action' => 'forwarded', 'source_office' => 'SDO-Legal Unit', 'destinations' => ['SDO-Budget Unit']],
        ])->assertUnprocessable()->assertJsonValidationErrors('workflow.destinations');
    }
}
