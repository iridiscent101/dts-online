<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\DocumentMovement;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Orchid\Attachment\File;
use Tests\TestCase;

class AttachmentGracePeriodTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.document_uploads_enabled' => true, 'dts.attachment_grace_hours' => 24]);
        Storage::fake('local');
        Notification::fake();
    }

    public function test_the_releasing_office_can_add_the_signed_receipt_soon_after(): void
    {
        [$records, $document] = $this->releasedByRecords();

        $this->actingAs($records)->postJson(route('platform.documents.attachments.store', $document), [
            'attachments' => [UploadedFile::fake()->createWithContent('signed-receipt.pdf', '%PDF-1.4 receipt')],
        ])->assertOk()->assertJsonMissingPath('errors');

        $this->assertSame('signed-receipt.pdf', $document->attachments()->sole()->original_name);
    }

    public function test_it_cannot_replace_or_delete_files_and_the_window_closes(): void
    {
        [$records, $document] = $this->releasedByRecords();
        $first = (new File(UploadedFile::fake()->createWithContent('a.pdf', '%PDF-1.4 a'), 'local', 'documents'))->load();
        $second = (new File(UploadedFile::fake()->createWithContent('b.pdf', '%PDF-1.4 b'), 'local', 'documents'))->load();
        $document->attachments()->attach([$first->id, $second->id]);

        $this->actingAs($records)->postJson(route('platform.documents.attachments.replace', [$document, $first->id]), [
            'replacement' => UploadedFile::fake()->createWithContent('c.pdf', '%PDF-1.4 c'),
        ])->assertForbidden();
        $this->postJson(route('platform.documents.attachments.destroy', [$document, $second->id]))->assertForbidden();

        // After the grace period, adding is closed too.
        $this->travel(25)->hours();
        $this->flushSession(); // a fresh sign-in the next day (the old session timed out)
        $this->actingAs($records)->postJson(route('platform.documents.attachments.store', $document), [
            'attachments' => [UploadedFile::fake()->createWithContent('late.pdf', '%PDF-1.4 late')],
        ])->assertForbidden();
        $this->assertSame(2, $document->attachments()->count());
    }

    public function test_another_office_cannot_add_files_to_a_finished_document(): void
    {
        [, $document] = $this->releasedByRecords();
        $budget = User::factory()->create([
            'office' => 'SDO-Budget Unit',
            'permissions' => ['platform.index' => true, 'platform.documents.attachments.manage' => true, 'platform.documents.view-all' => true],
        ]);

        $this->actingAs($budget)->postJson(route('platform.documents.attachments.store', $document), [
            'attachments' => [UploadedFile::fake()->createWithContent('x.pdf', '%PDF-1.4 x')],
        ])->assertForbidden();
    }

    /**
     * @return array{0: User, 1: Document}
     */
    private function releasedByRecords(): array
    {
        $records = User::factory()->create([
            'office' => 'SDO-Records Unit',
            'permissions' => ['platform.index' => true, 'platform.documents.attachments.manage' => true],
        ]);
        $document = Document::factory()->create(['current_office' => 'SDO-Records Unit', 'status' => DocumentMovement::STATUS_RELEASED]);
        $release = $document->movements()->create([
            'action' => DocumentMovement::ACTION_RELEASED, 'from_office' => 'SDO-Records Unit', 'to_office' => 'SDO-Records Unit',
            'from_status' => DocumentMovement::STATUS_RECEIVED, 'to_status' => DocumentMovement::STATUS_RELEASED, 'acted_by' => $records->id,
        ]);
        $document->officeAssignments()->updateOrCreate(['office' => 'SDO-Records Unit'], ['status' => DocumentMovement::STATUS_RELEASED, 'movement_id' => $release->id]);

        return [$records, $document->fresh()];
    }
}
