<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\DocumentMovement;
use App\Models\Office;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Orchid\Platform\Models\Role;
use Tests\TestCase;

class DocumentMovementTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_first_processing_action_automatically_records_receipt(): void
    {
        $user = User::factory()->create([
            'office' => 'Administrative Unit',
            'permissions' => ['platform.index' => true],
        ]);
        $document = Document::factory()->outgoing()->create([
            'current_office' => 'Administrative Unit',
            'remarks' => null,
        ]);

        $this->actingAs($user)
            ->post(route('platform.documents.movements.store', $document), [
                'workflow' => [
                    'action' => 'reviewed',
                    'remarks' => 'Administrative review completed.',
                ],
            ])
            ->assertRedirectToRoute('platform.documents.view', $document);

        $document->refresh();

        $this->assertSame('Administrative Unit', $document->current_office);
        $this->assertSame('Reviewed', $document->status);
        $this->assertSame('Administrative review completed.', $document->remarks);
        $this->assertDatabaseHas('document_movements', [
            'document_id' => $document->id,
            'action' => 'received',
            'from_office' => 'Administrative Unit',
            'to_office' => 'Administrative Unit',
            'from_status' => 'Forwarded',
            'to_status' => 'Received',
            'remarks' => 'Automatically recorded when processing began.',
            'acted_by' => $user->id,
        ]);
        $this->assertDatabaseHas('document_movements', [
            'document_id' => $document->id,
            'action' => 'reviewed',
            'from_status' => 'Received',
            'to_status' => 'Reviewed',
            'remarks' => 'Administrative review completed.',
            'acted_by' => $user->id,
        ]);

        $this->get(route('platform.documents.view', $document))
            ->assertOk()
            ->assertSee('Receipt acknowledged')
            ->assertSee('Document reviewed')
            ->assertSee('Administrative review completed.');

        $this->get(route('platform.main'))
            ->assertOk()
            ->assertSee('Document reviewed')
            ->assertSee($document->tracking_number);
    }

    public function test_every_assigned_office_can_acknowledge_independently(): void
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
                        'action' => 'acknowledge',
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

        $this->get(route('platform.documents.view', $document))
            ->assertOk()
            ->assertSee('value="Curriculum Implementation Division"', false)
            ->assertSee('value="Administrative Unit"', false)
            ->assertSee('value="Records Unit"', false);
    }

    public function test_document_view_defaults_to_an_office_still_awaiting_acknowledgement(): void
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
                    'action' => 'acknowledge',
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

        $this->get(route('platform.documents.view', $document))
            ->assertOk()
            ->assertSee('id="workflow-source-office" name="workflow[source_office]" required data-workflow-source-office', false)
            ->assertSee('value="Administrative Unit" selected>Administrative Unit — Awaiting receipt</option>', false)
            ->assertSee('id="workflow-action" name="workflow[action]" required data-workflow-action', false)
            ->assertSee('value="reviewed"', false)
            ->assertDontSee('>Receive</option>', false);

        $this->get(route('platform.documents.view', [
            'document' => $document,
            'source_office' => 'Records Unit',
        ]))
            ->assertOk()
            ->assertSee('value="Records Unit" selected>Records Unit — Received</option>', false);
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
                    'action' => 'acknowledge',
                    'remarks' => 'Attempted acknowledgement.',
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
                    'action' => 'acknowledge',
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

        $this->actingAs($user)
            ->get(route('platform.documents.view', [
                'document' => $document,
                'source_office' => 'Records Unit',
            ]))
            ->assertOk()
            ->assertSee('Acting office')
            ->assertSee('name="workflow[source_office]"', false)
            ->assertSee('value="Records Unit" selected>Records Unit — Received</option>', false)
            ->assertSee('value="Administrative Unit"', false)
            ->assertSee('Administrative Unit — Forwarded</option>', false)
            ->assertSee('Choose an action')
            ->assertSee('value="reviewed"', false)
            ->assertSee('>Review</option>', false)
            ->assertSee('value="approved"', false)
            ->assertSee('>Approve</option>', false)
            ->assertSee('data-action-options', false);

        $this->get(route('platform.documents.view', [
            'document' => $document,
            'source_office' => 'Administrative Unit',
        ]))
            ->assertOk()
            ->assertSee('value="Administrative Unit" selected>Administrative Unit — Forwarded</option>', false)
            ->assertSee('value="reviewed"', false)
            ->assertDontSee('>Receive</option>', false);

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
                'workflow' => ['action' => 'acknowledge'],
            ])
            ->assertForbidden();

        $this->assertDatabaseCount('document_movements', 0);
    }

    public function test_workflow_requires_sign_in(): void
    {
        $document = Document::factory()->create();

        $this->post(route('platform.documents.movements.store', $document), [
            'workflow' => ['action' => 'acknowledge'],
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
                    'action' => 'forward',
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
                    'action' => 'forward',
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
                    'action' => 'forward',
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

    public function test_staff_can_review_a_document_without_remarks(): void
    {
        $user = User::factory()->create([
            'office' => 'Records Unit',
            'permissions' => ['platform.index' => true],
        ]);
        $document = Document::factory()->received()->create(['current_office' => 'Records Unit']);

        $this->actingAs($user)
            ->post(route('platform.documents.movements.store', $document), [
                'workflow' => ['action' => 'reviewed'],
            ])
            ->assertRedirectToRoute('platform.documents.view', $document);

        $this->assertDatabaseHas('document_movements', [
            'document_id' => $document->id,
            'action' => 'reviewed',
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
                    'action' => 'forward',
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
            ->get(route('platform.documents.view', $document))
            ->assertOk()
            ->assertSee('Action unavailable');

        $this->post(route('platform.documents.movements.store', $document), [
            'workflow' => [
                'action' => 'received',
                'remarks' => 'Records should no longer act on this route.',
            ],
        ])->assertForbidden();

        $this->actingAs($administrativeUser)
            ->get(route('platform.documents.view', $document))
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
            ->get(route('platform.documents.view', $document))
            ->assertOk()
            ->assertSee('Assigned offices')
            ->assertSee('Administrative Unit')
            ->assertSee('Curriculum Implementation Division')
            ->assertSee('Next action')
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
                    'action' => 'forward',
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
                    'action' => 'forward',
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
                'action' => 'forward',
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
        $this->get(route('platform.documents.view', $document))
            ->assertOk()
            ->assertSee('Action unavailable');
        $this->post(route('platform.documents.movements.store', $document), [
            'workflow' => [
                'action' => 'received',
                'remarks' => 'Administrative office should no longer act.',
            ],
        ])->assertForbidden();

        $this->actingAs($curriculumUser)
            ->get(route('platform.documents.view', $document))
            ->assertOk()
            ->assertSee('Curriculum review.')
            ->assertDontSee('Budget review after administrative receipt.');

        $this->actingAs($budgetUser)
            ->get(route('platform.documents.view', $document))
            ->assertOk();
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
                    'action' => 'forward',
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
                'workflow' => [
                    'action' => 'completed',
                    'remarks' => 'Administrative work is complete.',
                ],
            ])
            ->assertRedirectToRoute('platform.documents.view', $document);

        $this->assertSame('Records Unit', $document->fresh()->current_office);
        $this->assertSame('Forwarded', $document->fresh()->status);

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

    public function test_final_office_decisions_return_to_records_for_completion(): void
    {
        $firstOffice = User::factory()->create(['office' => 'Administrative Unit', 'permissions' => ['platform.index' => true]]);
        $secondOffice = User::factory()->create(['office' => 'Curriculum Implementation Division', 'permissions' => ['platform.index' => true]]);
        $records = User::factory()->create(['office' => 'SDO-Records Unit', 'permissions' => ['platform.index' => true]]);
        $document = Document::factory()->received()->create(['current_office' => 'Administrative Unit']);
        $document->officeAssignments()->create(['office' => 'Curriculum Implementation Division', 'status' => 'Approved']);

        $this->actingAs($firstOffice)->post(route('platform.documents.movements.store', $document), [
            'workflow' => ['action' => 'rejected', 'source_office' => 'Administrative Unit'],
        ])->assertRedirectToRoute('platform.documents.view', $document);

        $this->assertSame('Curriculum Implementation Division', $document->fresh()->current_office);
        $this->assertSame('Approved', $document->fresh()->status);

        $this->actingAs($secondOffice)->post(route('platform.documents.movements.store', $document), [
            'workflow' => ['action' => 'released', 'source_office' => 'Curriculum Implementation Division'],
        ])->assertRedirectToRoute('platform.documents.view', $document);

        $this->assertSame('SDO-Records Unit', $document->fresh()->current_office);
        $this->assertSame('Forwarded', $document->fresh()->status);
        $this->assertDatabaseHas('document_office_assignments', ['document_id' => $document->id, 'office' => 'SDO-Records Unit', 'status' => 'Forwarded']);
        $this->assertDatabaseHas('document_movements', ['document_id' => $document->id, 'from_office' => 'Curriculum Implementation Division', 'to_office' => 'SDO-Records Unit', 'action' => 'forwarded']);
        $this->assertSame(1, $records->notifications()->count());

        $this->actingAs($records)->post(route('platform.documents.movements.store', $document), [
            'workflow' => ['action' => 'completed', 'source_office' => 'SDO-Records Unit'],
        ])->assertRedirectToRoute('platform.documents.view', $document);

        $this->assertSame('Completed', $document->fresh()->status);
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

    public function test_assigned_staff_can_archive_a_received_document_with_a_reason(): void
    {
        $user = User::factory()->create([
            'office' => 'Records Unit',
            'permissions' => ['platform.index' => true],
        ]);
        $document = Document::factory()->received()->create(['current_office' => 'Records Unit']);

        $this->actingAs($user)
            ->post(route('platform.documents.movements.store', $document), [
                'workflow' => [
                    'action' => 'archive',
                    'remarks' => 'Processing is complete.',
                ],
            ])
            ->assertRedirectToRoute('platform.documents.view', $document);

        $this->assertSame('Archived', $document->fresh()->status);
        $this->assertDatabaseHas('document_movements', [
            'document_id' => $document->id,
            'action' => 'archive',
            'to_office' => 'Records Unit',
            'to_status' => 'Archived',
            'remarks' => 'Processing is complete.',
        ]);
        $this->assertDatabaseMissing('activity_log', [
            'description' => 'Document archived',
            'subject_type' => Document::class,
            'subject_id' => $document->id,
        ]);

        $this->get(route('platform.documents.view', $document))
            ->assertOk()
            ->assertSee('Last changed by')
            ->assertSee($user->name);
    }

    public function test_archiving_one_office_route_keeps_another_unfinished_route_active(): void
    {
        $user = User::factory()->create(['office' => 'Records Unit', 'permissions' => ['platform.index' => true]]);
        $document = Document::factory()->received()->create(['current_office' => 'Records Unit']);
        $document->officeAssignments()->create([
            'office' => 'Administrative Unit',
            'status' => 'Awaiting receipt',
        ]);

        $this->actingAs($user)
            ->post(route('platform.documents.movements.store', $document), [
                'workflow' => ['action' => 'archive', 'source_office' => 'Records Unit'],
            ])
            ->assertRedirectToRoute('platform.documents.view', $document);

        $this->assertSame('Administrative Unit', $document->fresh()->current_office);
        $this->assertSame('Awaiting receipt', $document->fresh()->status);
        $this->assertDatabaseHas('document_office_assignments', [
            'document_id' => $document->id,
            'office' => 'Records Unit',
            'status' => 'Archived',
        ]);
        $this->assertDatabaseHas('document_office_assignments', [
            'document_id' => $document->id,
            'office' => 'Administrative Unit',
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
                    'remarks' => 'Attempted invalid transition.',
                ],
            ])
            ->assertRedirect(route('platform.documents.view', $document))
            ->assertSessionHasErrors([
                'workflow.action' => 'This document cannot be released while its status is Awaiting receipt.',
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
                    'action' => 'forward',
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
                    'action' => 'forward',
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

    public function test_document_can_return_to_the_same_office_with_independent_remarks(): void
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
                'action' => 'resubmitted',
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
            ['forwarded', 'received', 'resubmitted', 'received'],
            $document->movements()->reorder()->oldest()->pluck('action')->all(),
        );
        $this->assertDatabaseHas('document_movements', [
            'document_id' => $document->id,
            'to_office' => 'Records Unit',
            'action' => 'resubmitted',
            'remarks' => 'Required supporting document attached.',
        ]);
        $this->assertDatabaseHas('document_movements', [
            'document_id' => $document->id,
            'to_office' => 'Records Unit',
            'action' => 'received',
            'remarks' => 'Records received the resubmitted packet.',
        ]);

        $this->get(route('platform.documents.view', $document))
            ->assertOk()
            ->assertSee($recordsOfficer->name)
            ->assertSee('Records Unit')
            ->assertDontSee('Who:')
            ->assertDontSee('Where:')
            ->assertDontSee('When:')
            ->assertSeeInOrder([
                'Receipt acknowledged',
                'Records received the resubmitted packet.',
                'Resubmitted to Records Unit',
                'Required supporting document attached.',
                'Forwarded to Administrative Unit',
                'Send to admin for supporting documents.',
                'Document registered',
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
            ->get(route('platform.documents.view', $document))
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
            ->get(route('platform.documents.view', $document))
            ->assertOk()
            ->assertDontSee('Suggested next office')
            ->assertDontSee('Planned route');
    }
}
