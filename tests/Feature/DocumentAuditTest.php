<?php

namespace Tests\Feature;

use App\Models\Document;
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

        $this->get(route('platform.documents.view', $hiddenDocument))->assertNotFound();
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

        $this->get(route('platform.documents.view', $document))
            ->assertSee($document->tracking_number);
    }

    public function test_viewing_a_document_creates_an_audit_entry(): void
    {
        $user = User::factory()->create([
            'office' => 'Records Unit',
            'permissions' => ['platform.index' => true],
        ]);
        $document = Document::factory()->create(['current_office' => 'Records Unit']);

        $this->actingAs($user)
            ->get(route('platform.documents.view', $document))
            ->assertSee('Audit trail')
            ->assertSee('Document viewed');

        $this->assertDatabaseHas('activity_log', [
            'log_name' => 'documents',
            'description' => 'Document viewed',
            'subject_type' => Document::class,
            'subject_id' => $document->id,
            'causer_type' => User::class,
            'causer_id' => $user->id,
        ]);
    }

    public function test_previewing_and_downloading_an_attachment_are_audited(): void
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

        $this->assertDatabaseHas('activity_log', [
            'description' => 'Attachment previewed',
            'subject_id' => $document->id,
            'causer_id' => $user->id,
        ]);
        $this->assertDatabaseHas('activity_log', [
            'description' => 'Attachment downloaded',
            'subject_id' => $document->id,
            'causer_id' => $user->id,
        ]);
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
