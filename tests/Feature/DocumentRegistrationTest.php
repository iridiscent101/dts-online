<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Orchid\Attachment\File;
use Tests\TestCase;

class DocumentRegistrationTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_authorized_staff_can_register_a_document_with_private_attachments(): void
    {
        Storage::fake('local');
        $user = User::factory()->create([
            'office' => 'Records Unit',
            'permissions' => ['platform.index' => true],
        ]);

        $response = $this->actingAs($user)->post(route('platform.documents.create').'/save', [
            'document' => $this->validDocumentData(),
            'attachments' => [UploadedFile::fake()->create('memorandum.pdf', 100, 'application/pdf')],
        ]);

        $response->assertRedirectToRoute('platform.documents');

        $document = Document::query()->sole();

        $this->assertSame('DTS-2026-000001', $document->tracking_number);
        $this->assertSame('Regional Office', $document->origin);
        $this->assertSame($user->id, $document->registered_by);
        $this->assertSame('Awaiting receipt', $document->status);
        $this->assertCount(1, $document->attachments);

        $attachment = $document->attachments->first();
        $this->assertSame('local', $attachment->disk);
        $this->assertSame('memorandum.pdf', $attachment->original_name);
        Storage::disk('local')->assertExists($attachment->physicalPath());
        $this->assertDatabaseHas('activity_log', [
            'description' => 'Document registered',
            'subject_type' => Document::class,
            'subject_id' => $document->id,
            'causer_type' => User::class,
            'causer_id' => $user->id,
        ]);
    }

    public function test_registration_rejects_unsupported_attachments(): void
    {
        Storage::fake('local');
        $user = User::factory()->create(['permissions' => ['platform.index' => true]]);

        $response = $this->actingAs($user)
            ->from(route('platform.documents.create'))
            ->post(route('platform.documents.create').'/save', [
                'document' => $this->validDocumentData(),
                'attachments' => [UploadedFile::fake()->create('payload.exe', 100, 'application/octet-stream')],
            ]);

        $response->assertRedirect(route('platform.documents.create'))
            ->assertSessionHasErrors('attachments.0');

        $this->assertDatabaseCount('documents', 0);
        $this->assertDatabaseCount('attachments', 0);
    }

    public function test_registration_accepts_no_more_than_five_attachments(): void
    {
        $user = User::factory()->create(['permissions' => ['platform.index' => true]]);
        $attachments = [];

        for ($index = 1; $index <= 6; $index++) {
            $attachments[] = UploadedFile::fake()->create("attachment-{$index}.pdf", 10, 'application/pdf');
        }

        $this->actingAs($user)
            ->post(route('platform.documents.create').'/save', [
                'document' => $this->validDocumentData(),
                'attachments' => $attachments,
            ])
            ->assertSessionHasErrors('attachments');

        $this->assertDatabaseCount('documents', 0);
    }

    public function test_authorized_staff_can_view_and_download_an_attached_file(): void
    {
        Storage::fake('local');
        $user = User::factory()->create([
            'office' => 'Records Unit',
            'permissions' => ['platform.index' => true],
        ]);
        $this->actingAs($user);

        $document = Document::factory()->create([
            'tracking_number' => 'DTS-2026-000042',
            'current_office' => 'Records Unit',
            'registered_by' => $user->id,
        ]);
        $attachment = (new File(
            UploadedFile::fake()->createWithContent('retention-guide.pdf', '%PDF-1.4 test document'),
            'local',
            'documents',
        ))->load();
        $document->attachments()->attach($attachment);

        $this->get(route('platform.documents.view', $document))
            ->assertOk()
            ->assertSee($document->tracking_number)
            ->assertSee('retention-guide.pdf')
            ->assertSee(route('platform.documents.attachments.preview', [$document, $attachment]), false)
            ->assertSee(route('platform.documents.attachments.download', [$document, $attachment]), false);

        $this->get(route('platform.documents.attachments.preview', [$document, $attachment]))
            ->assertOk()
            ->assertSee('retention-guide.pdf')
            ->assertSee('pdf-container', false);

        $this->get(route('platform.documents.attachments.content', [$document, $attachment]))
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf')
            ->assertHeader('x-content-type-options', 'nosniff');

        $this->get(route('platform.documents.attachments.download', [$document, $attachment]))
            ->assertOk()
            ->assertDownload('retention-guide.pdf');
    }

    public function test_attachment_routes_reject_files_from_another_document(): void
    {
        Storage::fake('local');
        $user = User::factory()->create(['permissions' => ['platform.index' => true]]);
        $this->actingAs($user);

        $document = Document::factory()->create();
        $otherDocument = Document::factory()->create();
        $attachment = (new File(
            UploadedFile::fake()->create('private.pdf', 10, 'application/pdf'),
            'local',
            'documents',
        ))->load();
        $otherDocument->attachments()->attach($attachment);

        foreach (['preview', 'content', 'download'] as $action) {
            $this->get(route("platform.documents.attachments.{$action}", [$document, $attachment]))
                ->assertNotFound();
        }
    }

    public function test_attachment_preview_requires_sign_in(): void
    {
        Storage::fake('local');
        $document = Document::factory()->create();
        $attachment = (new File(
            UploadedFile::fake()->create('private.pdf', 10, 'application/pdf'),
            'local',
            'documents',
        ))->load();
        $document->attachments()->attach($attachment);

        $this->get(route('platform.documents.attachments.preview', [$document, $attachment]))
            ->assertRedirectToRoute('platform.login');
    }

    /**
     * @return array<string, string>
     */
    private function validDocumentData(): array
    {
        return [
            'title' => 'Regional memorandum on records retention',
            'type' => 'Memorandum',
            'reference' => 'RO-2026-104',
            'description' => 'Retention guidance for division records.',
            'origin' => 'Regional Office',
            'sender' => 'Records Officer',
            'received' => '2026-09-23',
            'due' => '2026-09-30',
            'destination' => 'Records Unit',
            'priority' => 'High',
            'remarks' => 'For immediate routing.',
        ];
    }
}
