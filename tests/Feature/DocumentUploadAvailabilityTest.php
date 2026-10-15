<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\DocumentMovement;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Orchid\Attachment\File;
use Tests\TestCase;

class DocumentUploadAvailabilityTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_registration_and_client_forms_hide_file_controls(): void
    {
        $staff = User::factory()->create(['permissions' => ['platform.index' => true]]);

        $this->get(route('submission.create'))
            ->assertDontSee('name="attachment"', false)
            ->assertDontSee('Document file');

        $this->actingAs($staff)->get(route('platform.documents.create'))
            ->assertDontSee('name="attachments[]"', false)
            ->assertDontSee('id="attachment-heading"', false);
    }

    public function test_registration_and_client_submission_reject_uploaded_files(): void
    {
        $staff = User::factory()->create(['permissions' => ['platform.index' => true]]);

        $this->actingAs($staff)->post(route('platform.documents.create').'/save', [
            'document' => [
                'title' => 'Paper request',
                'type' => 'Request',
                'origin' => 'Records Unit',
                'sender' => 'Alex Rivera',
                'received' => '2026-10-13',
                'destinations' => ['Records Unit'],
                'priority' => 'Standard',
            ],
            'attachments' => [UploadedFile::fake()->create('request.pdf', 10, 'application/pdf')],
        ])->assertSessionHasErrors('attachments');

        $this->post(route('submission.store'), [
            'submission_key' => (string) Str::uuid(),
            'name' => 'Alex Rivera',
            'privacy_consent' => '1',
            'office' => 'School Office',
            'delivery_method' => 'Hand carry',
            'title' => 'Paper request',
            'type' => 'Request',
            'destinations' => ['Records Unit'],
            'attachment' => UploadedFile::fake()->create('request.pdf', 10, 'application/pdf'),
        ])->assertSessionHasErrors('attachment');

        $this->assertDatabaseCount('documents', 0);
        $this->assertDatabaseCount('attachments', 0);
    }

    public function test_direct_attachment_actions_return_404_while_uploads_are_paused(): void
    {
        $staff = User::factory()->create([
            'office' => 'Records Unit',
            'permissions' => [
                'platform.index' => true,
                'platform.documents.attachments.manage' => true,
                'platform.systems.attachment' => true,
            ],
        ]);
        $document = Document::factory()->create(['current_office' => 'Records Unit']);
        $this->actingAs($staff);

        foreach (['preview', 'content', 'download'] as $action) {
            $this->get(route("platform.documents.attachments.{$action}", [$document, 1]))->assertNotFound();
        }

        $this->postJson(route('platform.documents.attachments.store', $document), [
            'attachments' => [UploadedFile::fake()->create('request.pdf', 10, 'application/pdf')],
        ])->assertNotFound();
        $this->postJson(route('platform.documents.attachments.replace', [$document, 1]), [
            'replacement' => UploadedFile::fake()->create('replacement.pdf', 10, 'application/pdf'),
        ])->assertNotFound();
        $this->postJson(route('platform.documents.attachments.destroy', [$document, 1]))->assertNotFound();
        $this->postJson(route('platform.systems.files.upload'), [
            'file' => UploadedFile::fake()->create('request.pdf', 10, 'application/pdf'),
        ])->assertNotFound();

        $this->assertDatabaseCount('attachments', 0);
    }

    public function test_existing_attachment_cannot_be_viewed_or_downloaded_while_files_are_paused(): void
    {
        Storage::fake('local');
        $staff = User::factory()->create([
            'office' => 'Records Unit',
            'permissions' => ['platform.index' => true],
        ]);
        $document = Document::factory()->create(['current_office' => 'Records Unit']);
        $attachment = (new File(
            UploadedFile::fake()->createWithContent('retained.pdf', '%PDF-1.4 retained file'),
            'local',
            'documents',
        ))->load();
        $document->attachments()->attach($attachment);

        foreach (['preview', 'content', 'download'] as $action) {
            $this->actingAs($staff)
                ->get(route("platform.documents.attachments.{$action}", [$document, $attachment]))
                ->assertNotFound();
        }

        Storage::disk('local')->assertExists($attachment->physicalPath());
        $this->assertSame(0, $document->activities()->where('description', 'Attachment downloaded')->count());
    }

    public function test_client_submission_without_upload_waits_for_paper_at_records_without_routes(): void
    {
        $this->post(route('submission.store'), [
            'submission_key' => (string) Str::uuid(),
            'name' => 'Alex Rivera',
            'privacy_consent' => '1',
            'office' => 'School Office',
            'delivery_method' => 'Hand carry',
            'title' => 'Paper request',
            'type' => 'Request',
        ])->assertRedirect();

        $document = Document::query()->sole();
        $this->assertSame('SDO-Records Unit', $document->current_office);
        $this->assertSame(DocumentMovement::STATUS_AWAITING_PHYSICAL_DOCUMENT, $document->status);
        $this->assertDatabaseCount('document_route_steps', 0);
        $this->assertDatabaseCount('attachments', 0);
    }

    public function test_two_records_routes_complete_independently_after_paper_intake(): void
    {
        $recordsStaff = User::factory()->create([
            'office' => 'SDO-Records Unit',
            'permissions' => ['platform.index' => true],
        ]);
        $administrativeStaff = User::factory()->create([
            'office' => 'Administrative Unit',
            'permissions' => ['platform.index' => true],
        ]);
        $curriculumStaff = User::factory()->create([
            'office' => 'Curriculum Implementation Division',
            'permissions' => ['platform.index' => true],
        ]);
        $this->post(route('submission.store'), [
            'submission_key' => (string) Str::uuid(),
            'name' => 'Alex Rivera',
            'privacy_consent' => '1',
            'office' => 'School Office',
            'delivery_method' => 'Hand carry',
            'title' => 'Paper request for two offices',
            'type' => 'Request',
        ])->assertRedirect();

        $document = Document::query()->sole();
        $this->assertSame('SDO-Records Unit', $document->current_office);
        $this->assertDatabaseCount('document_route_steps', 0);
        $this->actingAs($administrativeStaff)->get(route('platform.documents.view', $document))->assertNotFound();
        $this->actingAs($curriculumStaff)->get(route('platform.documents.view', $document))->assertNotFound();

        $this->actingAs($recordsStaff)->post(route('platform.documents.movements.store', $document), [
            'workflow' => ['action' => 'received', 'source_office' => 'SDO-Records Unit'],
        ])->assertRedirectToRoute('platform.documents.view', $document);

        $this->actingAs($recordsStaff)->post(route('platform.documents.movements.store', $document), [
            'workflow' => [
                'action' => 'forwarded',
                'source_office' => 'SDO-Records Unit',
                'destinations' => ['Administrative Unit', 'Curriculum Implementation Division'],
                'destination_remarks' => ['Administrative review.', 'Curriculum review.'],
            ],
        ])->assertRedirect();

        $this->assertDatabaseCount('document_route_steps', 0);
        $this->assertDatabaseHas('document_office_assignments', [
            'document_id' => $document->id,
            'office' => 'Administrative Unit',
            'status' => DocumentMovement::STATUS_FORWARDED,
        ]);
        $this->assertDatabaseHas('document_office_assignments', [
            'document_id' => $document->id,
            'office' => 'Curriculum Implementation Division',
            'status' => DocumentMovement::STATUS_FORWARDED,
        ]);

        $this->actingAs($recordsStaff)->post(route('platform.documents.movements.store', $document), [
            'workflow' => ['action' => 'completed', 'source_office' => 'Administrative Unit'],
        ])->assertForbidden();

        $this->actingAs($administrativeStaff)->post(route('platform.documents.movements.store', $document), [
            'workflow' => ['action' => 'received', 'source_office' => 'Administrative Unit'],
        ])->assertRedirectToRoute('platform.documents.view', $document);

        $this->actingAs($administrativeStaff)->post(route('platform.documents.movements.store', $document), [
            'workflow' => ['action' => 'completed', 'source_office' => 'Administrative Unit'],
        ])->assertRedirectToRoute('platform.documents.view', $document);

        $this->assertSame(DocumentMovement::STATUS_FORWARDED, $document->fresh()->status);
        $this->assertDatabaseHas('document_office_assignments', [
            'document_id' => $document->id,
            'office' => 'Administrative Unit',
            'status' => DocumentMovement::STATUS_COMPLETED,
        ]);
        $this->assertDatabaseHas('document_office_assignments', [
            'document_id' => $document->id,
            'office' => 'Curriculum Implementation Division',
            'status' => DocumentMovement::STATUS_FORWARDED,
        ]);

        $this->post(route('platform.documents.movements.store', $document), [
            'workflow' => ['action' => 'completed', 'source_office' => 'Curriculum Implementation Division'],
        ])->assertForbidden();

        $this->actingAs($curriculumStaff)->post(route('platform.documents.movements.store', $document), [
            'workflow' => ['action' => 'received', 'source_office' => 'Curriculum Implementation Division'],
        ])->assertRedirectToRoute('platform.documents.view', $document);

        $this->actingAs($curriculumStaff)->post(route('platform.documents.movements.store', $document), [
            'workflow' => ['action' => 'completed', 'source_office' => 'Curriculum Implementation Division'],
        ])->assertRedirectToRoute('platform.documents.view', $document);

        $this->assertSame('Curriculum Implementation Division', $document->fresh()->current_office);
        $this->assertSame(DocumentMovement::STATUS_COMPLETED, $document->fresh()->status);
        $this->assertDatabaseCount('document_office_assignments', 2);
        $this->assertSame(2, $document->movements()->where('action', 'completed')->count());

        $this->actingAs($recordsStaff)->post(route('platform.documents.movements.store', $document), [
            'workflow' => ['action' => 'completed', 'source_office' => 'SDO-Records Unit'],
        ])->assertForbidden();

        $this->assertSame(DocumentMovement::STATUS_COMPLETED, $document->fresh()->status);
    }
}
