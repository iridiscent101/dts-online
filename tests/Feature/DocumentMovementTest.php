<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Orchid\Platform\Models\Role;
use Tests\TestCase;

class DocumentMovementTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_assigned_staff_can_acknowledge_a_forwarded_document(): void
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
                    'action' => 'acknowledge',
                    'remarks' => 'Received by the administrative records desk.',
                ],
            ])
            ->assertRedirectToRoute('platform.documents.view', $document);

        $document->refresh();

        $this->assertSame('Administrative Unit', $document->current_office);
        $this->assertSame('Received', $document->status);
        $this->assertSame('Received by the administrative records desk.', $document->remarks);
        $this->assertDatabaseHas('document_movements', [
            'document_id' => $document->id,
            'action' => 'acknowledge',
            'from_office' => 'Administrative Unit',
            'to_office' => 'Administrative Unit',
            'from_status' => 'Forwarded',
            'to_status' => 'Received',
            'remarks' => 'Received by the administrative records desk.',
            'acted_by' => $user->id,
        ]);

        $this->get(route('platform.documents.view', $document))
            ->assertOk()
            ->assertSee('Receipt acknowledged')
            ->assertSee('Received by the administrative records desk.');

        $this->get(route('platform.main'))
            ->assertOk()
            ->assertSee('Receipt acknowledged')
            ->assertSee($document->tracking_number);
    }

    public function test_workflow_validates_destination_and_required_reason(): void
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
            ->assertSessionHasErrors([
                'workflow.destination' => 'Select a recognized destination office.',
                'workflow.remarks' => 'Provide a note for this action.',
            ]);

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
                    'remarks' => 'Attempted invalid transition.',
                    'remarks' => 'For review and appropriate action.',
                ],
            ])
            ->assertRedirectToRoute('platform.documents.view', $document);

        $document->refresh();

        $this->assertSame('Administrative Unit', $document->current_office);
        $this->assertSame('Forwarded', $document->status);
        $this->assertDatabaseHas('document_movements', [
            'document_id' => $document->id,
            'action' => 'forward',
            'from_office' => 'Records Unit',
            'to_office' => 'Administrative Unit',
            'from_status' => 'Received',
            'to_status' => 'Forwarded',
        ]);
        $this->assertDatabaseHas('activity_log', [
            'description' => 'Document forwarded',
            'subject_type' => Document::class,
            'subject_id' => $document->id,
            'causer_type' => User::class,
            'causer_id' => $user->id,
        ]);
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
                    'action' => 'forward',
                    'destination' => 'Administrative Unit',
                    'remarks' => 'Attempted invalid transition.',
                ],
            ])
            ->assertRedirect(route('platform.documents.view', $document))
            ->assertSessionHasErrors([
                'workflow.action' => 'This document cannot be forwarded while its status is Awaiting receipt.',
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
                'workflow.destination' => 'Select a different office to forward this document.',
            ]);

        $this->assertDatabaseCount('document_movements', 0);
    }
}
