<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\DocumentMovement;
use App\Models\User;
use App\Notifications\DocumentAssignedNotification;
use App\Orchid\PlatformProvider;
use Database\Seeders\DocumentTypeSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Orchid\Platform\ItemPermission;
use Orchid\Platform\Models\Role;
use Tests\TestCase;

class DocumentEditingTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_existing_document_type_remains_selectable_when_it_is_not_in_the_current_catalog(): void
    {
        $this->seed(DocumentTypeSeeder::class);

        $user = User::factory()->create([
            'office' => 'Records Unit',
            'permissions' => [
                'platform.index' => true,
                'platform.documents.edit' => true,
            ],
        ]);
        $document = Document::factory()->create([
            'current_office' => 'Records Unit',
            'document_type' => 'Memorandum',
        ]);

        $this->actingAs($user)
            ->get(route('platform.documents.edit', $document))
            ->assertOk()
            ->assertSee('value="Memorandum"', false)
            ->assertSee('name="document[details_fingerprint]"', false);

        $this->post(route('platform.documents.edit', $document).'/save', [
            'document' => $this->validDocumentData($document),
        ])->assertRedirectToRoute('platform.documents.view', $document);

        $this->assertSame('Memorandum', $document->fresh()->document_type);
    }

    public function test_authorized_office_staff_can_update_document_metadata_without_changing_workflow_fields(): void
    {
        $user = User::factory()->create([
            'office' => 'Records Unit',
            'permissions' => [
                'platform.index' => true,
                'platform.documents.edit' => true,
            ],
        ]);
        $document = Document::factory()->received()->create([
            'tracking_number' => 'DTS-2026-EDIT-001',
            'subject' => 'Original subject',
            'current_office' => 'Records Unit',
            'remarks' => 'Workflow-owned note',
        ]);

        $this->actingAs($user)
            ->get(route('platform.documents.view', $document))
            ->assertOk()
            ->assertSee('Edit document');

        $this->get(route('platform.documents.edit', $document))
            ->assertOk()
            ->assertSee('Edit document')
            ->assertSee('Optional information')
            ->assertDontSee('Protected record fields')
            ->assertDontSee('Changes are recorded in the audit trail')
            ->assertSee('Original subject');

        $this->post(route('platform.documents.edit', $document).'/save', [
            'document' => $this->validDocumentData($document),
        ])->assertRedirectToRoute('platform.documents.view', $document);

        $document->refresh();

        $this->assertSame('Corrected memorandum subject', $document->subject);
        $this->assertSame('Urgent', $document->priority);
        $this->assertSame('DTS-2026-EDIT-001', $document->tracking_number);
        $this->assertSame('Received', $document->status);
        $this->assertSame('Records Unit', $document->current_office);
        $this->assertSame('Workflow-owned note', $document->remarks);

        $activity = $document->activities()->where('description', 'Document details updated')->firstOrFail();

        $this->assertSame($user->id, $activity->causer_id);
        $this->assertContains('subject', $activity->properties->get('changed_fields'));
        $this->assertSame('Original subject', data_get($activity->properties, 'before.subject'));
        $this->assertSame('Corrected memorandum subject', data_get($activity->properties, 'after.subject'));
    }

    public function test_staff_need_edit_permission_and_current_office_access(): void
    {
        $document = Document::factory()->create(['current_office' => 'Administrative Unit']);
        $staffWithoutPermission = User::factory()->create([
            'office' => 'Administrative Unit',
            'permissions' => ['platform.index' => true],
        ]);

        $this->actingAs($staffWithoutPermission)
            ->get(route('platform.documents.view', $document))
            ->assertOk()
            ->assertDontSee('Edit document');

        $this->get(route('platform.documents.edit', $document))->assertForbidden();

        $staffFromAnotherOffice = User::factory()->create([
            'office' => 'Records Unit',
            'permissions' => [
                'platform.index' => true,
                'platform.documents.edit' => true,
            ],
        ]);

        $this->actingAs($staffFromAnotherOffice)
            ->get(route('platform.documents.edit', $document))
            ->assertNotFound();
    }

    public function test_staff_with_edit_permission_can_update_a_document_assigned_to_their_secondary_office(): void
    {
        $user = User::factory()->create([
            'office' => 'Curriculum Implementation Division',
            'permissions' => [
                'platform.index' => true,
                'platform.documents.edit' => true,
            ],
        ]);
        $document = Document::factory()->received()->create([
            'subject' => 'Secondary assignment subject',
            'current_office' => 'Administrative Unit',
            'status' => 'Received',
        ]);
        $document->officeAssignments()->create([
            'office' => 'Curriculum Implementation Division',
            'status' => 'Forwarded',
            'assigned_by' => $user->id,
        ]);

        $this->actingAs($user)
            ->get(route('platform.documents.edit', $document))
            ->assertOk()
            ->assertSee('Secondary assignment subject');

        $this->post(route('platform.documents.edit', $document).'/save', [
            'document' => $this->validDocumentData($document),
        ])->assertRedirectToRoute('platform.documents.view', $document);

        $this->assertSame('Corrected memorandum subject', $document->fresh()->subject);
    }

    public function test_administrators_can_edit_documents_from_any_office(): void
    {
        $adminRole = Role::create([
            'name' => 'Admin',
            'slug' => User::ADMIN_ROLE_SLUG,
            'permissions' => [],
        ]);
        $admin = User::factory()->create(['office' => null, 'permissions' => []]);
        $admin->replaceRoles([$adminRole->id]);
        $document = Document::factory()->received()->create(['current_office' => 'Curriculum Implementation Division']);

        $this->actingAs($admin)
            ->get(route('platform.documents.edit', $document))
            ->assertOk()
            ->assertSee('Save changes')
            ->assertSee('data-edit-route-add', false);
    }

    public function test_route_management_is_registered_as_its_own_grantable_permission(): void
    {
        $slugs = collect((new PlatformProvider($this->app))->permissions())
            ->flatMap(fn (ItemPermission $permission): mixed => collect($permission->items)->pluck('slug'));

        $this->assertTrue($slugs->contains('platform.documents.edit'));
        $this->assertTrue($slugs->contains('platform.documents.routes.manage'));
        $this->assertTrue($slugs->contains('platform.documents.attachments.manage'));
    }

    public function test_document_editing_validates_dates_before_updating(): void
    {
        $user = User::factory()->create([
            'office' => 'Records Unit',
            'permissions' => [
                'platform.index' => true,
                'platform.documents.edit' => true,
            ],
        ]);
        $document = Document::factory()->create([
            'subject' => 'Unchanged subject',
            'current_office' => 'Records Unit',
        ]);
        $data = $this->validDocumentData($document);
        $data['received'] = '2026-09-28';
        $data['due'] = '2026-09-27';

        $this->actingAs($user)
            ->from(route('platform.documents.edit', $document))
            ->post(route('platform.documents.edit', $document).'/save', ['document' => $data])
            ->assertRedirect(route('platform.documents.edit', $document))
            ->assertSessionHasErrors('document.due');

        $this->assertSame('Unchanged subject', $document->fresh()->subject);
    }

    public function test_administrator_can_change_routes_while_editing(): void
    {
        Notification::fake();

        $adminRole = Role::create(['name' => 'Admin', 'slug' => User::ADMIN_ROLE_SLUG]);
        $user = User::factory()->create(['office' => 'Records Unit']);
        $user->replaceRoles([$adminRole->id]);
        $recipient = User::factory()->create(['office' => 'Curriculum Implementation Division']);
        $document = Document::factory()->received()->create([
            'current_office' => 'Records Unit',
            'status' => 'Received',
            'registered_by' => $user->id,
        ]);
        $document->officeAssignments()->create([
            'office' => 'Administrative Unit',
            'status' => DocumentMovement::STATUS_AWAITING_RECEIPT,
        ]);

        $this->actingAs($user)
            ->get(route('platform.documents.edit', $document))
            ->assertOk()
            ->assertSee('Assigned offices')
            ->assertSee('data-edit-route-add', false);

        $data = $this->validDocumentData($document);
        $data['destinations'] = ['Records Unit', 'Curriculum Implementation Division'];
        $data['route_fingerprint'] = $document->routeFingerprint();

        $this->post(route('platform.documents.edit', $document).'/save', ['document' => $data])
            ->assertRedirectToRoute('platform.documents.view', $document)
            ->assertSessionHasNoErrors();

        $document->refresh();

        $this->assertEqualsCanonicalizing(
            ['Records Unit', 'Curriculum Implementation Division'],
            $document->officeAssignments()->pluck('office')->all(),
        );
        $this->assertSame('Records Unit', $document->current_office);
        $this->assertSame(DocumentMovement::STATUS_RECEIVED, $document->status);
        $this->assertDatabaseHas('document_movements', [
            'document_id' => $document->getKey(),
            'to_office' => 'Curriculum Implementation Division',
        ]);
        $this->assertDatabaseMissing('document_movements', [
            'document_id' => $document->getKey(),
            'to_office' => 'Administrative Unit',
        ]);

        $activity = $document->activities()->where('description', 'Document routes updated')->firstOrFail();

        $this->assertSame($user->id, $activity->causer_id);
        $this->assertSame('routes', $activity->properties->get('action'));
        $this->assertContains('Curriculum Implementation Division', $activity->properties->get('added'));
        $this->assertContains('Administrative Unit', $activity->properties->get('removed'));
        $this->assertEqualsCanonicalizing(
            ['Records Unit', 'Administrative Unit'],
            $activity->properties->get('before'),
        );

        Notification::assertSentTo($recipient, DocumentAssignedNotification::class);
    }

    public function test_stale_admin_route_edit_cannot_remove_a_new_handoff(): void
    {
        $adminRole = Role::create(['name' => 'Admin', 'slug' => User::ADMIN_ROLE_SLUG]);
        $admin = User::factory()->create(['office' => 'Records Unit']);
        $admin->replaceRoles([$adminRole->id]);
        $document = Document::factory()->received()->create(['current_office' => 'Records Unit', 'subject' => 'Original subject']);
        $document->officeAssignments()->create(['office' => 'Administrative Unit', 'status' => DocumentMovement::STATUS_FORWARDED]);
        $data = $this->validDocumentData($document);
        $data['destinations'] = ['Records Unit', 'Administrative Unit'];
        $data['route_fingerprint'] = str_repeat('a', 64);
        $document->officeAssignments()->create(['office' => 'Curriculum Implementation Division', 'status' => DocumentMovement::STATUS_FORWARDED]);

        $this->actingAs($admin)
            ->from(route('platform.documents.edit', $document))
            ->post(route('platform.documents.edit', $document).'/save', ['document' => $data])
            ->assertRedirect(route('platform.documents.edit', $document))
            ->assertSessionHasErrors(['document.route_fingerprint' => 'Routes changed while you were editing. Refresh the document and try again.']);

        $this->assertSame('Original subject', $document->fresh()->subject);
        $this->assertDatabaseHas('document_office_assignments', ['document_id' => $document->id, 'office' => 'Curriculum Implementation Division']);
    }

    public function test_stale_details_edit_cannot_overwrite_a_newer_save(): void
    {
        $user = User::factory()->create([
            'office' => 'Records Unit',
            'permissions' => ['platform.index' => true, 'platform.documents.edit' => true],
        ]);
        $document = Document::factory()->received()->create([
            'current_office' => 'Records Unit',
            'subject' => 'Original subject',
        ]);
        $staleFingerprint = $document->detailsFingerprint();
        $firstEdit = $this->validDocumentData($document);
        $firstEdit['title'] = 'First staff edit';
        $firstEdit['details_fingerprint'] = $staleFingerprint;

        $this->actingAs($user)
            ->post(route('platform.documents.edit', $document).'/save', ['document' => $firstEdit])
            ->assertRedirectToRoute('platform.documents.view', $document);

        $secondEdit = $firstEdit;
        $secondEdit['title'] = 'Stale second edit';
        $this->from(route('platform.documents.edit', $document))
            ->post(route('platform.documents.edit', $document).'/save', ['document' => $secondEdit])
            ->assertRedirect(route('platform.documents.edit', $document))
            ->assertSessionHasErrors(['document.details_fingerprint' => 'Document details changed while you were editing. Refresh the document and try again.']);

        $this->assertSame('First staff edit', $document->fresh()->subject);
        $this->assertSame(1, $document->activities()->where('description', 'Document details updated')->count());
    }

    public function test_edit_without_details_fingerprint_is_rejected(): void
    {
        $user = User::factory()->create([
            'office' => 'Records Unit',
            'permissions' => ['platform.index' => true, 'platform.documents.edit' => true],
        ]);
        $document = Document::factory()->received()->create([
            'current_office' => 'Records Unit',
            'subject' => 'Original subject',
        ]);
        $data = $this->validDocumentData($document);
        unset($data['details_fingerprint']);

        $this->actingAs($user)
            ->from(route('platform.documents.edit', $document))
            ->post(route('platform.documents.edit', $document).'/save', ['document' => $data])
            ->assertRedirect(route('platform.documents.edit', $document))
            ->assertSessionHasErrors(['document.details_fingerprint' => 'This edit form is out of date. Refresh the document and try again.']);

        $this->assertSame('Original subject', $document->fresh()->subject);
        $this->assertSame(0, $document->activities()->where('description', 'Document details updated')->count());
    }

    public function test_stale_route_edit_cannot_silently_save_after_document_is_completed(): void
    {
        $adminRole = Role::create(['name' => 'Admin', 'slug' => User::ADMIN_ROLE_SLUG]);
        $admin = User::factory()->create(['office' => 'Records Unit']);
        $admin->replaceRoles([$adminRole->id]);
        $document = Document::factory()->received()->create(['current_office' => 'Records Unit', 'subject' => 'Original subject']);
        $data = $this->validDocumentData($document);
        $data['destinations'] = ['Records Unit', 'Administrative Unit'];
        $data['route_fingerprint'] = $document->routeFingerprint();
        $document->update(['status' => DocumentMovement::STATUS_COMPLETED]);

        $this->actingAs($admin)
            ->from(route('platform.documents.edit', $document))
            ->post(route('platform.documents.edit', $document).'/save', ['document' => $data])
            ->assertRedirect(route('platform.documents.edit', $document))
            ->assertSessionHasErrors('document.destinations');

        $this->assertSame('Original subject', $document->fresh()->subject);
        $this->assertDatabaseMissing('document_office_assignments', ['document_id' => $document->id, 'office' => 'Administrative Unit']);
    }

    public function test_started_office_route_cannot_be_removed_by_an_admin(): void
    {
        $adminRole = Role::create(['name' => 'Admin', 'slug' => User::ADMIN_ROLE_SLUG]);
        $admin = User::factory()->create(['office' => 'Records Unit']);
        $admin->replaceRoles([$adminRole->id]);
        $document = Document::factory()->received()->create([
            'current_office' => 'Records Unit',
            'status' => DocumentMovement::STATUS_RECEIVED,
            'subject' => 'Original subject',
        ]);
        $document->officeAssignments()->create([
            'office' => 'Administrative Unit',
            'status' => DocumentMovement::STATUS_RECEIVED,
        ]);
        $data = $this->validDocumentData($document);
        $data['destinations'] = ['Records Unit', 'Curriculum Implementation Division'];
        $data['route_fingerprint'] = $document->routeFingerprint();

        $this->actingAs($admin)
            ->from(route('platform.documents.edit', $document))
            ->post(route('platform.documents.edit', $document).'/save', ['document' => $data])
            ->assertRedirect(route('platform.documents.edit', $document))
            ->assertSessionHasErrors(['document.destinations' => 'Administrative Unit has started work and cannot be removed from this document.']);

        $this->assertSame('Original subject', $document->fresh()->subject);
        $this->assertDatabaseHas('document_office_assignments', [
            'document_id' => $document->id,
            'office' => 'Administrative Unit',
            'status' => DocumentMovement::STATUS_RECEIVED,
        ]);
        $this->assertDatabaseMissing('document_office_assignments', [
            'document_id' => $document->id,
            'office' => 'Curriculum Implementation Division',
        ]);
    }

    public function test_client_routes_cannot_be_changed_before_records_receives_the_paper(): void
    {
        $adminRole = Role::create(['name' => 'Admin', 'slug' => User::ADMIN_ROLE_SLUG]);
        $admin = User::factory()->create(['office' => 'SDO-Records Unit']);
        $admin->replaceRoles([$adminRole->id]);
        $document = Document::factory()->create([
            'current_office' => 'SDO-Records Unit',
            'status' => DocumentMovement::STATUS_AWAITING_PHYSICAL_DOCUMENT,
        ]);

        $this->actingAs($admin)
            ->get(route('platform.documents.edit', $document))
            ->assertOk()
            ->assertDontSee('data-edit-route-add', false);

        $data = $this->validDocumentData($document);
        $data['destinations'] = ['Administrative Unit'];

        $this->from(route('platform.documents.edit', $document))
            ->post(route('platform.documents.edit', $document).'/save', ['document' => $data])
            ->assertRedirect(route('platform.documents.edit', $document))
            ->assertSessionHasErrors('document.destinations');

        $this->assertSame(['SDO-Records Unit'], $document->officeAssignments()->pluck('office')->all());
    }

    public function test_editing_paper_submission_does_not_set_a_received_date_before_intake(): void
    {
        $user = User::factory()->create([
            'office' => 'SDO-Records Unit',
            'permissions' => ['platform.index' => true, 'platform.documents.edit' => true],
        ]);
        $document = Document::factory()->create([
            'current_office' => 'SDO-Records Unit',
            'status' => DocumentMovement::STATUS_AWAITING_PHYSICAL_DOCUMENT,
            'received_at' => null,
            'due_at' => null,
        ]);
        $data = $this->validDocumentData($document);
        unset($data['received']);
        $data['due'] = null;

        $this->actingAs($user)
            ->post(route('platform.documents.edit', $document).'/save', ['document' => $data])
            ->assertRedirectToRoute('platform.documents.view', $document);
        $this->assertNull($document->fresh()->received_at);

        $data['received'] = '2026-10-14';
        $this->post(route('platform.documents.edit', $document).'/save', ['document' => $data])
            ->assertSessionHasErrors('document.received');
        $this->assertNull($document->fresh()->received_at);

        unset($data['received']);
        $data['due'] = '2026-10-21';
        $this->post(route('platform.documents.edit', $document).'/save', ['document' => $data])
            ->assertSessionHasErrors('document.due');
        $this->assertNull($document->fresh()->due_at);
    }

    public function test_pending_paper_submission_shows_dates_as_not_received_without_a_required_marker(): void
    {
        $user = User::factory()->create([
            'office' => 'SDO-Records Unit',
            'permissions' => ['platform.index' => true, 'platform.documents.edit' => true],
        ]);
        $document = Document::factory()->create([
            'current_office' => 'SDO-Records Unit',
            'status' => DocumentMovement::STATUS_AWAITING_PHYSICAL_DOCUMENT,
            'received_at' => null,
            'due_at' => null,
        ]);

        $this->actingAs($user)
            ->get(route('platform.documents.workspace', $document))
            ->assertOk()
            ->assertSee('Not received')
            ->assertSee('<span>Date received </span>', false)
            ->assertSee('aria-describedby="workspace-dates-pending"', false)
            ->assertSee('Both dates are set when the Records Unit confirms the paper copy.');

        $this->get(route('platform.documents.edit', $document))
            ->assertOk()
            ->assertSee('id="document-dates-pending"', false)
            ->assertDontSee('Date received <span aria-hidden="true">*</span>', false);
    }

    public function test_replacing_the_current_route_updates_the_overall_status_to_the_new_office_status(): void
    {
        $adminRole = Role::create(['name' => 'Admin', 'slug' => User::ADMIN_ROLE_SLUG]);
        $admin = User::factory()->create(['office' => 'Records Unit']);
        $admin->replaceRoles([$adminRole->id]);
        $document = Document::factory()->outgoing()->create([
            'current_office' => 'Records Unit',
            'status' => DocumentMovement::STATUS_FORWARDED,
        ]);
        $data = $this->validDocumentData($document);
        $data['destinations'] = ['Administrative Unit'];
        $data['route_fingerprint'] = $document->routeFingerprint();

        $this->actingAs($admin)
            ->post(route('platform.documents.edit', $document).'/save', ['document' => $data])
            ->assertRedirectToRoute('platform.documents.view', $document);

        $document->refresh();
        $this->assertSame('Administrative Unit', $document->current_office);
        $this->assertSame(DocumentMovement::STATUS_AWAITING_RECEIPT, $document->status);
        $this->assertSame(DocumentMovement::STATUS_AWAITING_RECEIPT, $document->officeAssignments()->firstOrFail()->status);
    }

    public function test_selecting_an_existing_route_uses_that_offices_status(): void
    {
        $adminRole = Role::create(['name' => 'Admin', 'slug' => User::ADMIN_ROLE_SLUG]);
        $admin = User::factory()->create(['office' => 'Records Unit']);
        $admin->replaceRoles([$adminRole->id]);
        $document = Document::factory()->outgoing()->create([
            'current_office' => 'Records Unit',
            'status' => DocumentMovement::STATUS_FORWARDED,
        ]);
        $document->officeAssignments()->create([
            'office' => 'Administrative Unit',
            'status' => DocumentMovement::STATUS_REVIEWED,
        ]);
        $data = $this->validDocumentData($document);
        $data['destinations'] = ['Administrative Unit'];
        $data['route_fingerprint'] = $document->routeFingerprint();

        $this->actingAs($admin)
            ->post(route('platform.documents.edit', $document).'/save', ['document' => $data])
            ->assertRedirectToRoute('platform.documents.view', $document);

        $document->refresh();
        $this->assertSame('Administrative Unit', $document->current_office);
        $this->assertSame(DocumentMovement::STATUS_REVIEWED, $document->status);
    }

    public function test_removing_current_route_keeps_an_unfinished_office_active(): void
    {
        $adminRole = Role::create(['name' => 'Admin', 'slug' => User::ADMIN_ROLE_SLUG]);
        $admin = User::factory()->create(['office' => 'Records Unit']);
        $admin->replaceRoles([$adminRole->id]);
        $document = Document::factory()->outgoing()->create([
            'current_office' => 'Records Unit',
            'status' => DocumentMovement::STATUS_FORWARDED,
        ]);
        $document->officeAssignments()->create([
            'office' => 'Administrative Unit',
            'status' => DocumentMovement::STATUS_COMPLETED,
        ]);
        $document->officeAssignments()->create([
            'office' => 'Curriculum Implementation Division',
            'status' => DocumentMovement::STATUS_AWAITING_RECEIPT,
        ]);
        $data = $this->validDocumentData($document);
        $data['destinations'] = ['Administrative Unit', 'Curriculum Implementation Division'];
        $data['route_fingerprint'] = $document->routeFingerprint();

        $this->actingAs($admin)
            ->post(route('platform.documents.edit', $document).'/save', ['document' => $data])
            ->assertRedirectToRoute('platform.documents.view', $document);

        $document->refresh();
        $this->assertSame('Curriculum Implementation Division', $document->current_office);
        $this->assertSame(DocumentMovement::STATUS_AWAITING_RECEIPT, $document->status);
    }

    public function test_new_office_route_starts_awaiting_receipt_even_when_document_was_approved(): void
    {
        $adminRole = Role::create(['name' => 'Admin', 'slug' => User::ADMIN_ROLE_SLUG]);
        $admin = User::factory()->create(['office' => 'Records Unit']);
        $admin->replaceRoles([$adminRole->id]);
        $document = Document::factory()->create([
            'current_office' => 'Records Unit',
            'status' => 'Approved',
            'registered_by' => $admin->id,
        ]);
        $data = $this->validDocumentData($document);
        $data['destinations'] = ['Records Unit', 'Administrative Unit'];
        $data['route_fingerprint'] = $document->routeFingerprint();

        $this->actingAs($admin)
            ->post(route('platform.documents.edit', $document).'/save', ['document' => $data])
            ->assertRedirectToRoute('platform.documents.view', $document);

        $this->assertDatabaseHas('document_office_assignments', [
            'document_id' => $document->id,
            'office' => 'Administrative Unit',
            'status' => 'Awaiting receipt',
        ]);
        $this->assertDatabaseHas('document_movements', [
            'document_id' => $document->id,
            'to_office' => 'Administrative Unit',
            'to_status' => 'Awaiting receipt',
        ]);
    }

    public function test_staff_without_routes_permission_cannot_change_routes(): void
    {
        $user = User::factory()->create([
            'office' => 'Records Unit',
            'permissions' => [
                'platform.index' => true,
                'platform.documents.edit' => true,
            ],
        ]);
        $document = Document::factory()->received()->create([
            'current_office' => 'Records Unit',
            'status' => 'Received',
        ]);

        $this->actingAs($user)
            ->get(route('platform.documents.edit', $document))
            ->assertOk()
            ->assertDontSee('data-edit-route-add', false)
            ->assertDontSee('document[destinations][]', false);

        $data = $this->validDocumentData($document);
        $data['destinations'] = ['Records Unit', 'Administrative Unit'];

        $this->from(route('platform.documents.edit', $document))
            ->post(route('platform.documents.edit', $document).'/save', ['document' => $data])
            ->assertRedirect(route('platform.documents.edit', $document))
            ->assertSessionHasErrors([
                'document.destinations' => 'You are not authorized to change document routes.',
            ]);

        $this->assertSame(
            ['Records Unit'],
            $document->officeAssignments()->pluck('office')->all(),
        );

        unset($data['destinations']);

        $this->post(route('platform.documents.edit', $document).'/save', ['document' => $data])
            ->assertRedirectToRoute('platform.documents.view', $document)
            ->assertSessionHasNoErrors();

        $this->assertSame('Corrected memorandum subject', $document->fresh()->subject);
        $this->assertSame(
            ['Records Unit'],
            $document->officeAssignments()->pluck('office')->all(),
        );
    }

    public function test_routes_are_locked_while_a_document_is_archived(): void
    {
        $adminRole = Role::create(['name' => 'Admin', 'slug' => User::ADMIN_ROLE_SLUG]);
        $user = User::factory()->create(['office' => 'Records Unit']);
        $user->replaceRoles([$adminRole->id]);
        $document = Document::factory()->archived()->create(['current_office' => 'Records Unit', 'registered_by' => $user->id]);

        $this->actingAs($user)
            ->get(route('platform.documents.edit', $document))
            ->assertOk()
            ->assertSee('Routing is locked')
            ->assertDontSee('data-edit-route-add', false);

        $data = $this->validDocumentData($document);
        $data['destinations'] = ['Records Unit', 'Administrative Unit'];

        $this->from(route('platform.documents.edit', $document))
            ->post(route('platform.documents.edit', $document).'/save', ['document' => $data])
            ->assertRedirect(route('platform.documents.edit', $document))
            ->assertSessionHasErrors([
                'document.destinations' => 'Routes cannot be edited after a document is archived or completed.',
            ]);

        $this->assertSame(
            ['Records Unit'],
            $document->officeAssignments()->pluck('office')->all(),
        );
    }

    public function test_route_editing_validates_destination_offices(): void
    {
        $adminRole = Role::create(['name' => 'Admin', 'slug' => User::ADMIN_ROLE_SLUG]);
        $user = User::factory()->create(['office' => 'Records Unit']);
        $user->replaceRoles([$adminRole->id]);
        $document = Document::factory()->received()->create([
            'current_office' => 'Records Unit',
            'status' => 'Received',
            'registered_by' => $user->id,
        ]);

        $cases = [
            [[], 'document.destinations', 'Select at least one destination office.'],
            [['Unknown Office'], 'document.destinations.0', 'Select recognized destination offices.'],
            [['Records Unit', 'Records Unit'], 'document.destinations.1', 'Select each destination office only once.'],
            [array_slice(Document::officeOptions(), 0, 6), 'document.destinations', 'Select no more than five destination offices.'],
        ];

        foreach ($cases as [$destinations, $errorKey, $errorMessage]) {
            $data = $this->validDocumentData($document);
            $data['destinations'] = $destinations;

            $this->actingAs($user)
                ->from(route('platform.documents.edit', $document))
                ->post(route('platform.documents.edit', $document).'/save', ['document' => $data])
                ->assertRedirect(route('platform.documents.edit', $document))
                ->assertSessionHasErrors([$errorKey => $errorMessage]);
        }

        $this->assertSame(
            ['Records Unit'],
            $document->officeAssignments()->pluck('office')->all(),
        );
    }

    /**
     * @return array<string, string>
     */
    private function validDocumentData(Document $document): array
    {
        return [
            'details_fingerprint' => $document->detailsFingerprint(),
            'title' => 'Corrected memorandum subject',
            'type' => 'Memorandum',
            'reference' => 'DEPED-EDIT-001',
            'description' => 'Corrected document description.',
            'origin' => 'Office of the Schools Division Superintendent',
            'sender' => 'Maria Santos',
            'received' => '2026-09-27',
            'due' => '2026-10-04',
            'priority' => 'Urgent',
        ];
    }
}
