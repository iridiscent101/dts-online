<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\Office;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Orchid\Attachment\File;
use Orchid\Platform\Models\Role;
use Tests\TestCase;

class DocumentAuditTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.document_uploads_enabled' => true]);
    }

    public function test_staff_only_see_documents_assigned_to_their_office(): void
    {
        $user = User::factory()->create([
            'office' => 'Records Unit',
            'permissions' => ['platform.index' => true],
        ]);
        $visibleDocument = Document::factory()->create([
            'tracking_number' => 'DTS-VISIBLE',
            'current_office' => 'Records Unit',
        ]);
        $hiddenDocument = Document::factory()->create([
            'tracking_number' => 'DTS-HIDDEN',
            'current_office' => 'Administrative Unit',
        ]);

        $this->actingAs($user)
            ->get(route('platform.documents'))
            ->assertSee($visibleDocument->tracking_number)
            ->assertDontSee($hiddenDocument->tracking_number);

        $this->get(route('platform.documents.workspace', $hiddenDocument))->assertNotFound();
    }

    public function test_staff_can_see_documents_for_any_assigned_office(): void
    {
        $recordsOffice = Office::create(['name' => 'Records Unit', 'sort_order' => 1, 'is_active' => true]);
        $administrativeOffice = Office::create(['name' => 'Administrative Unit', 'sort_order' => 2, 'is_active' => true]);
        $user = User::factory()->create([
            'office' => null,
            'permissions' => ['platform.index' => true],
        ]);
        $user->offices()->sync([$recordsOffice->id, $administrativeOffice->id]);

        $recordsDocument = Document::factory()->create([
            'tracking_number' => 'DTS-RECORDS',
            'current_office' => 'Records Unit',
        ]);
        $administrativeDocument = Document::factory()->create([
            'tracking_number' => 'DTS-ADMIN',
            'current_office' => 'Administrative Unit',
        ]);
        $hiddenDocument = Document::factory()->create([
            'tracking_number' => 'DTS-HIDDEN',
            'current_office' => 'Curriculum Implementation Division',
        ]);

        $this->actingAs($user)
            ->get(route('platform.documents'))
            ->assertSee($recordsDocument->tracking_number)
            ->assertSee($administrativeDocument->tracking_number)
            ->assertDontSee($hiddenDocument->tracking_number);

        $this->get(route('platform.documents.workspace', $administrativeDocument))->assertOk();
        $this->get(route('platform.documents.workspace', $hiddenDocument))->assertNotFound();
    }

    public function test_staff_creator_can_view_their_document_after_it_moves_to_another_office(): void
    {
        $creator = User::factory()->create([
            'office' => 'Records Unit',
            'permissions' => [
                'platform.index' => true,
                'platform.documents.edit' => true,
                'platform.documents.attachments.manage' => true,
            ],
        ]);
        $document = Document::factory()->create([
            'tracking_number' => 'DTS-CREATOR-VISIBLE',
            'current_office' => 'Administrative Unit',
            'registered_by' => $creator->id,
        ]);
        $otherDocument = Document::factory()->create([
            'tracking_number' => 'DTS-OTHER-HIDDEN',
            'current_office' => 'Administrative Unit',
        ]);

        $this->actingAs($creator)
            ->get(route('platform.documents'))
            ->assertSee($document->tracking_number)
            ->assertDontSee($otherDocument->tracking_number);
        $this->get(route('platform.documents.workspace', $document))->assertOk();
        $this->get(route('platform.documents.workspace', $otherDocument))->assertNotFound();
        // The registering Records office can still correct the details (after unlocking them with a reason), but
        // attachments stay with the office that holds the document now.
        $this->get(route('platform.documents.edit', $document))->assertOk()->assertSee('data-unlock-details', false);
        $this->assertFalse($document->canHaveAttachmentsManagedBy($creator));
    }

    public function test_administrator_can_see_documents_from_every_office(): void
    {
        $adminRole = Role::create([
            'name' => 'Admin',
            'slug' => User::ADMIN_ROLE_SLUG,
            'permissions' => [],
        ]);
        $admin = User::factory()->create(['office' => null, 'permissions' => []]);
        $admin->replaceRoles([$adminRole->id]);
        $document = Document::factory()->create([
            'tracking_number' => 'DTS-ADMIN-VISIBLE',
            'current_office' => 'Administrative Unit',
        ]);

        $this->actingAs($admin)
            ->get(route('platform.documents'))
            ->assertSee($document->tracking_number);

        $this->get(route('platform.documents.workspace', $document))
            ->assertSee($document->tracking_number);
    }

    public function test_viewing_a_document_does_not_create_an_audit_entry(): void
    {
        $user = User::factory()->create([
            'office' => 'Records Unit',
            'permissions' => ['platform.index' => true],
        ]);
        $document = Document::factory()->create(['current_office' => 'Records Unit']);

        $this->actingAs($user)
            ->get(route('platform.documents.workspace', $document))
            ->assertOk()
            ->assertSee('Activity history')
            ->assertSee('No activity recorded.');

        $this->assertDatabaseMissing('activity_log', [
            'subject_type' => Document::class,
            'subject_id' => $document->id,
        ]);
    }

    public function test_document_editor_cannot_delete_a_document_or_its_audit_history(): void
    {
        $editor = User::factory()->create([
            'office' => 'Records Unit',
            'permissions' => ['platform.index' => true, 'platform.documents.edit' => true],
        ]);
        $document = Document::factory()->create(['current_office' => 'Records Unit']);
        $document->recordActivity('Document details updated', $editor, ['action' => 'update']);

        $this->actingAs($editor)
            ->post(route('platform.documents.destroy', $document))
            ->assertForbidden();

        $this->assertModelExists($document);
        $this->assertSame(1, $document->activities()->count());
    }

    public function test_administrator_removal_retains_document_and_audit_history(): void
    {
        $adminRole = Role::create(['name' => 'Admin', 'slug' => User::ADMIN_ROLE_SLUG]);
        $admin = User::factory()->create(['permissions' => []]);
        $admin->replaceRoles([$adminRole->id]);
        $document = Document::factory()->create(['current_office' => 'Records Unit']);
        $document->recordActivity('Document details updated', $admin, ['action' => 'update']);

        $this->actingAs($admin)
            ->post(route('platform.documents.destroy', $document))
            ->assertRedirectToRoute('platform.documents');

        $this->assertDatabaseHas('documents', ['id' => $document->id]);
        $this->assertSoftDeleted($document);
        $this->assertDatabaseHas('activity_log', [
            'subject_type' => Document::class,
            'subject_id' => $document->id,
            'description' => 'Document details updated',
        ]);
        $this->assertDatabaseHas('activity_log', [
            'subject_type' => Document::class,
            'subject_id' => $document->id,
            'description' => 'Document removed',
        ]);
        $this->assertDatabaseHas('document_office_assignments', ['document_id' => $document->id]);
        $this->get(route('platform.documents.workspace', $document))->assertNotFound();
        $this->get(route('platform.main'))
            ->assertOk()
            ->assertDontSee($document->tracking_number);
        $this->get(route('tracking.index', ['tracking' => $document->tracking_number]))
            ->assertOk()
            ->assertSee('No matching document')
            ->assertDontSee($document->subject);
    }

    public function test_audit_trail_retains_all_edits_while_showing_the_latest_twenty(): void
    {
        $firstEditor = User::factory()->create([
            'office' => 'Records Unit',
            'permissions' => ['platform.index' => true],
        ]);
        $latestEditor = User::factory()->create(['office' => 'Records Unit']);
        $document = Document::factory()->create(['current_office' => 'Records Unit']);

        foreach (range(1, 20) as $index) {
            $document->recordActivity('Attachment replaced', $firstEditor, [
                'action' => 'attachment_replace',
                'file_name' => "revision-{$index}.pdf",
            ]);
        }
        $document->recordActivity('Document details updated', $latestEditor, [
            'action' => 'update',
        ]);

        $this->assertSame(21, $document->activities()->count());
        $this->assertDatabaseHas('activity_log', [
            'subject_type' => Document::class,
            'subject_id' => $document->id,
            'description' => 'Attachment replaced',
            'properties' => json_encode(['action' => 'attachment_replace', 'file_name' => 'revision-1.pdf']),
        ]);

        $this->actingAs($firstEditor)
            ->get(route('platform.documents.workspace', $document))
            ->assertOk()
            ->assertSee('Document details updated')
            ->assertSee($latestEditor->name)
            ->assertSee('Showing 20 of 21 entries');
    }

    public function test_preview_is_not_audited_and_every_download_is_retained(): void
    {
        Storage::fake('local');
        $user = User::factory()->create([
            'office' => 'Records Unit',
            'permissions' => ['platform.index' => true],
        ]);
        $document = Document::factory()->create(['current_office' => 'Records Unit']);
        $attachment = (new File(
            UploadedFile::fake()->createWithContent('routing-note.pdf', '%PDF-1.4 test'),
            'local',
            'documents',
        ))->load();
        $document->attachments()->attach($attachment);

        $this->actingAs($user)
            ->get(route('platform.documents.attachments.preview', [$document, $attachment]))
            ->assertOk();

        $this->get(route('platform.documents.attachments.download', [$document, $attachment]))
            ->assertDownload('routing-note.pdf');
        $this->get(route('platform.documents.attachments.download', [$document, $attachment]))
            ->assertDownload('routing-note.pdf');

        $this->assertDatabaseMissing('activity_log', [
            'description' => 'Attachment previewed',
            'subject_id' => $document->id,
        ]);
        $this->assertSame(
            2,
            $document->activities()->where('description', 'Attachment downloaded')->count(),
        );
    }

    public function test_staff_cannot_access_attachments_from_another_office(): void
    {
        Storage::fake('local');
        $user = User::factory()->create([
            'office' => 'Records Unit',
            'permissions' => ['platform.index' => true],
        ]);
        $document = Document::factory()->create(['current_office' => 'Administrative Unit']);
        $attachment = (new File(
            UploadedFile::fake()->create('private.pdf', 10, 'application/pdf'),
            'local',
            'documents',
        ))->load();
        $document->attachments()->attach($attachment);

        $this->actingAs($user)
            ->get(route('platform.documents.attachments.preview', [$document, $attachment]))
            ->assertNotFound();

        $this->assertDatabaseMissing('activity_log', [
            'subject_id' => $document->id,
        ]);
    }

    public function test_image_preview_keeps_the_viewer_source_image_hidden(): void
    {
        Storage::fake('local');
        $user = User::factory()->create([
            'office' => 'Records Unit',
            'permissions' => ['platform.index' => true],
        ]);
        $document = Document::factory()->create(['current_office' => 'Records Unit']);
        $attachment = (new File(
            UploadedFile::fake()->image('routing-map.png'),
            'local',
            'documents',
        ))->load();
        $document->attachments()->attach($attachment);

        $this->actingAs($user)
            ->get(route('platform.documents.attachments.preview', [$document, $attachment]))
            ->assertOk()
            ->assertSee('style="display:none!important;min-width:200px;"', false)
            ->assertDontSee("image.style.display = 'block'", false);
    }
}
