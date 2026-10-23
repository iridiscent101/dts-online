<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\DocumentMovement;
use App\Models\User;
use App\Notifications\DocumentChangedNotification;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Orchid\Attachment\File;
use Orchid\Attachment\Models\Attachment;
use Orchid\Platform\Models\Role;
use RuntimeException;
use Tests\TestCase;

class DocumentAttachmentManagementTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.document_uploads_enabled' => true]);
    }

    public function test_authorized_staff_can_add_private_attachments_to_a_visible_document(): void
    {
        Storage::fake('local');
        $user = $this->attachmentManager();
        $recipient = User::factory()->create(['office' => $user->assignedOfficeNames()->first()]);
        $document = Document::factory()->create(['current_office' => $user->assignedOfficeNames()->first()]);
        Notification::fake();

        $response = $this->actingAs($user)->postJson(
            route('platform.documents.attachments.store', $document),
            ['attachments' => [UploadedFile::fake()->create('routing-note.pdf', 80, 'application/pdf')]],
        );

        $response->assertOk()
            ->assertJsonPath('message', 'Attachments uploaded.')
            ->assertJsonStructure(['html']);

        $attachment = $document->attachments()->sole();
        $this->assertSame('routing-note.pdf', $attachment->original_name);
        Storage::disk('local')->assertExists($attachment->physicalPath());
        $this->assertDatabaseHas('activity_log', [
            'description' => 'Attachments added',
            'subject_type' => Document::class,
            'subject_id' => $document->id,
            'causer_id' => $user->id,
        ]);
        Notification::assertSentTo(
            $recipient,
            DocumentChangedNotification::class,
            fn (DocumentChangedNotification $notification): bool => $notification->change === 'attachments_added'
                && $notification->document->is($document),
        );
    }

    public function test_notification_failure_rolls_back_attachment_addition_and_allows_retry(): void
    {
        Storage::fake('local');
        $user = $this->attachmentManager();
        $document = Document::factory()->create(['current_office' => $user->assignedOfficeNames()->first()]);
        Notification::shouldReceive('send')->once()->andThrow(new RuntimeException('Notification unavailable'));

        $this->actingAs($user)->postJson(route('platform.documents.attachments.store', $document), [
            'attachments' => [UploadedFile::fake()->create('note.pdf', 80, 'application/pdf')],
        ])->assertStatus(500)->assertJsonPath('message', 'The files could not be uploaded. Please try again.');

        $this->assertSame(0, $document->attachments()->count());
        $this->assertDatabaseCount('attachments', 0);
        $this->assertSame([], Storage::disk('local')->allFiles('documents'));

        Notification::fake();
        $this->postJson(route('platform.documents.attachments.store', $document), [
            'attachments' => [UploadedFile::fake()->create('note.pdf', 80, 'application/pdf')],
        ])->assertOk();

        $this->assertSame(1, $document->attachments()->count());
    }

    public function test_notification_failure_keeps_original_attachment_on_replace_and_allows_retry(): void
    {
        Storage::fake('local');
        $user = $this->attachmentManager();
        $document = Document::factory()->create(['current_office' => $user->assignedOfficeNames()->first()]);
        $original = $this->createAttachment('original.pdf');
        $originalPath = $original->physicalPath();
        $document->attachments()->attach($original);
        Notification::shouldReceive('send')->once()->andThrow(new RuntimeException('Notification unavailable'));

        $this->actingAs($user)->postJson(route('platform.documents.attachments.replace', [$document, $original]), [
            'replacement' => UploadedFile::fake()->create('replacement.pdf', 80, 'application/pdf'),
        ])->assertStatus(500)->assertJsonPath('message', 'The attachment could not be replaced. Please try again.');

        $this->assertSame([$original->id], $document->attachments()->pluck('attachments.id')->all());
        Storage::disk('local')->assertExists($originalPath);
        $this->assertDatabaseCount('attachments', 1);

        Notification::fake();
        $this->postJson(route('platform.documents.attachments.replace', [$document, $original]), [
            'replacement' => UploadedFile::fake()->create('replacement.pdf', 80, 'application/pdf'),
        ])->assertOk();

        $this->assertSame('replacement.pdf', $document->attachments()->sole()->original_name);
    }

    public function test_notification_failure_keeps_attachment_on_remove_and_allows_retry(): void
    {
        Storage::fake('local');
        $user = $this->attachmentManager();
        $document = Document::factory()->create(['current_office' => $user->assignedOfficeNames()->first()]);
        $first = $this->createAttachment('first.pdf');
        $second = $this->createAttachment('second.pdf');
        $document->attachments()->attach([$first->id, $second->id]);
        Notification::shouldReceive('send')->once()->andThrow(new RuntimeException('Notification unavailable'));

        $this->actingAs($user)->postJson(route('platform.documents.attachments.destroy', [$document, $first]))
            ->assertStatus(500);

        $this->assertEqualsCanonicalizing([$first->id, $second->id], $document->attachments()->pluck('attachments.id')->all());
        Storage::disk('local')->assertExists($first->physicalPath());

        Notification::fake();
        $this->postJson(route('platform.documents.attachments.destroy', [$document, $first]))->assertOk();

        $this->assertSame([$second->id], $document->attachments()->pluck('attachments.id')->all());
    }

    public function test_attachment_addition_enforces_file_rules_and_the_five_file_total(): void
    {
        Storage::fake('local');
        $user = $this->attachmentManager();
        $document = Document::factory()->create(['current_office' => $user->assignedOfficeNames()->first()]);

        $this->actingAs($user)->postJson(route('platform.documents.attachments.store', $document), [
            'attachments' => [UploadedFile::fake()->create('payload.exe', 20, 'application/octet-stream')],
        ])->assertUnprocessable()->assertJsonValidationErrors('attachments.0');

        foreach (range(1, 5) as $index) {
            $document->attachments()->attach($this->createAttachment("existing-{$index}.pdf"));
        }

        $this->postJson(route('platform.documents.attachments.store', $document), [
            'attachments' => [UploadedFile::fake()->create('sixth.pdf', 20, 'application/pdf')],
        ])->assertUnprocessable()->assertJsonValidationErrors('attachments');

        $this->assertCount(5, $document->attachments()->get());
    }

    public function test_authorized_staff_can_replace_an_attachment_and_the_change_is_audited(): void
    {
        Storage::fake('local');
        $user = $this->attachmentManager();
        $document = Document::factory()->create(['current_office' => $user->assignedOfficeNames()->first()]);
        $original = $this->createAttachment('old-report.pdf');
        $originalPath = $original->physicalPath();
        $document->attachments()->attach($original);

        $this->actingAs($user)->postJson(
            route('platform.documents.attachments.replace', [$document, $original]),
            ['replacement' => UploadedFile::fake()->create('corrected-report.pdf', 90, 'application/pdf')],
        )->assertOk()->assertJsonPath('message', 'Attachment replaced.');

        $replacement = $document->attachments()->sole();
        $this->assertSame('corrected-report.pdf', $replacement->original_name);
        $this->assertDatabaseMissing('attachments', ['id' => $original->id]);
        Storage::disk('local')->assertMissing($originalPath);
        Storage::disk('local')->assertExists($replacement->physicalPath());
        $this->assertDatabaseHas('activity_log', [
            'description' => 'Attachment replaced',
            'subject_id' => $document->id,
            'causer_id' => $user->id,
        ]);
    }

    public function test_attachment_can_be_deleted_but_the_final_file_must_be_replaced(): void
    {
        Storage::fake('local');
        $user = $this->attachmentManager();
        $document = Document::factory()->create(['current_office' => $user->assignedOfficeNames()->first()]);
        $first = $this->createAttachment('first.pdf');
        $second = $this->createAttachment('second.pdf');
        $document->attachments()->attach([$first->id, $second->id]);

        $this->actingAs($user)->postJson(
            route('platform.documents.attachments.destroy', [$document, $first]),
        )->assertOk()->assertJsonPath('message', 'Attachment removed.');

        $this->assertDatabaseMissing('attachments', ['id' => $first->id]);
        $this->assertSame([$second->id], $document->attachments()->pluck('attachments.id')->all());

        $this->postJson(route('platform.documents.attachments.destroy', [$document, $second]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('attachment');

        $this->assertDatabaseHas('attachments', ['id' => $second->id]);
        $this->assertDatabaseHas('activity_log', [
            'description' => 'Attachment removed',
            'subject_id' => $document->id,
            'causer_id' => $user->id,
        ]);
    }

    public function test_attachment_management_requires_permission_and_document_visibility(): void
    {
        Storage::fake('local');
        $document = Document::factory()->create(['current_office' => 'Records Unit']);
        $withoutPermission = User::factory()->create([
            'office' => 'Records Unit',
            'permissions' => ['platform.index' => true],
        ]);
        $otherOffice = $this->attachmentManager('Administrative Unit');

        $this->actingAs($withoutPermission)->postJson(route('platform.documents.attachments.store', $document), [
            'attachments' => [UploadedFile::fake()->create('note.pdf', 20, 'application/pdf')],
        ])->assertForbidden();

        $this->actingAs($otherOffice)->postJson(route('platform.documents.attachments.store', $document), [
            'attachments' => [UploadedFile::fake()->create('other.pdf', 20, 'application/pdf')],
        ])->assertNotFound();
    }

    public function test_attachment_manager_can_add_files_to_a_document_assigned_to_their_secondary_office(): void
    {
        Storage::fake('local');
        $user = $this->attachmentManager('Curriculum Implementation Division');
        $document = Document::factory()->create([
            'current_office' => 'Administrative Unit',
            'status' => 'Received',
        ]);
        $document->officeAssignments()->create([
            'office' => 'Curriculum Implementation Division',
            'status' => 'Forwarded',
            'assigned_by' => $user->id,
        ]);

        $this->actingAs($user)->postJson(route('platform.documents.attachments.store', $document), [
            'attachments' => [UploadedFile::fake()->create('secondary-office-note.pdf', 20, 'application/pdf')],
        ])->assertOk();

        $this->assertSame('secondary-office-note.pdf', $document->attachments()->sole()->original_name);
    }

    public function test_attachment_management_rejects_files_owned_by_another_document(): void
    {
        Storage::fake('local');
        $user = $this->attachmentManager();
        $document = Document::factory()->create(['current_office' => $user->assignedOfficeNames()->first()]);
        $otherDocument = Document::factory()->create(['current_office' => $user->assignedOfficeNames()->first()]);
        $attachment = $this->createAttachment('other-record.pdf');
        $otherDocument->attachments()->attach($attachment);
        $this->actingAs($user);

        $this->postJson(route('platform.documents.attachments.replace', [$document, $attachment]), [
            'replacement' => UploadedFile::fake()->create('replacement.pdf', 20, 'application/pdf'),
        ])->assertNotFound();

        $this->postJson(route('platform.documents.attachments.destroy', [$document, $attachment]))
            ->assertNotFound();

        $this->assertTrue($otherDocument->attachments()->whereKey($attachment->id)->exists());
    }

    public function test_administrator_can_manage_attachments_for_any_office(): void
    {
        Storage::fake('local');
        $adminRole = Role::create([
            'name' => 'Admin',
            'slug' => User::ADMIN_ROLE_SLUG,
            'permissions' => [],
        ]);
        $administrator = User::factory()->create(['office' => null]);
        $administrator->replaceRoles([$adminRole->id]);
        $document = Document::factory()->create(['current_office' => 'Records Unit']);

        $this->actingAs($administrator)->postJson(route('platform.documents.attachments.store', $document), [
            'attachments' => [UploadedFile::fake()->create('admin-note.pdf', 20, 'application/pdf')],
        ])->assertOk();

        $this->assertSame('admin-note.pdf', $document->attachments()->sole()->original_name);
    }

    public function test_attachments_of_a_closed_record_are_locked_for_office_staff(): void
    {
        Storage::fake('local');
        Notification::fake();
        $user = $this->attachmentManager('SDO-Budget Unit');
        $document = Document::factory()->create(['current_office' => 'SDO-Budget Unit', 'status' => DocumentMovement::STATUS_COMPLETED]);
        $document->officeAssignments()->updateOrCreate(['office' => 'SDO-Budget Unit'], ['status' => DocumentMovement::STATUS_COMPLETED]);
        $first = $this->createAttachment('signed.pdf');
        $second = $this->createAttachment('memo.pdf');
        $document->attachments()->attach([$first->id, $second->id]);

        $this->actingAs($user)->postJson(route('platform.documents.attachments.store', $document), [
            'attachments' => [UploadedFile::fake()->createWithContent('extra.pdf', '%PDF-1.4 extra')],
        ])->assertForbidden();
        $this->postJson(route('platform.documents.attachments.replace', [$document, $first->id]), [
            'replacement' => UploadedFile::fake()->createWithContent('swapped.pdf', '%PDF-1.4 swapped'),
        ])->assertForbidden();
        $this->postJson(route('platform.documents.attachments.destroy', [$document, $second->id]))->assertForbidden();

        $this->assertEqualsCanonicalizing([$first->id, $second->id], $document->attachments()->pluck('attachments.id')->all());
        $this->assertDatabaseMissing('activity_log', ['subject_id' => $document->id, 'description' => 'Attachment replaced']);
    }

    public function test_an_office_that_finished_its_part_cannot_change_attachments_while_others_work(): void
    {
        $budget = $this->attachmentManager('SDO-Budget Unit');
        $legal = $this->attachmentManager('SDO-Legal Unit');
        $document = Document::factory()->create(['current_office' => 'SDO-Legal Unit', 'status' => DocumentMovement::STATUS_RECEIVED]);
        $document->officeAssignments()->updateOrCreate(['office' => 'SDO-Legal Unit'], ['status' => DocumentMovement::STATUS_RECEIVED]);
        $document->officeAssignments()->create(['office' => 'SDO-Budget Unit', 'status' => DocumentMovement::STATUS_COMPLETED]);

        $this->assertFalse($document->canHaveAttachmentsManagedBy($budget));
        $this->assertTrue($document->canHaveAttachmentsManagedBy($legal));
    }

    public function test_administrator_can_still_correct_attachments_of_a_closed_record(): void
    {
        Storage::fake('local');
        $adminRole = Role::create(['name' => 'Admin', 'slug' => User::ADMIN_ROLE_SLUG, 'permissions' => []]);
        $administrator = User::factory()->create(['office' => null]);
        $administrator->replaceRoles([$adminRole->id]);
        $document = Document::factory()->archived()->create(['current_office' => 'SDO-Budget Unit']);

        $this->actingAs($administrator)->postJson(route('platform.documents.attachments.store', $document), [
            'attachments' => [UploadedFile::fake()->create('corrected-scan.pdf', 20, 'application/pdf')],
        ])->assertOk();

        $this->assertSame('corrected-scan.pdf', $document->attachments()->sole()->original_name);
    }

    private function attachmentManager(string $office = 'Records Unit'): User
    {
        return User::factory()->create([
            'office' => $office,
            'permissions' => [
                'platform.index' => true,
                'platform.documents.attachments.manage' => true,
            ],
        ]);
    }

    private function createAttachment(string $name): Attachment
    {
        /** @var Attachment $attachment */
        $attachment = (new File(
            UploadedFile::fake()->createWithContent($name, '%PDF-1.4 test document'),
            'local',
            'documents',
        ))->load();

        return $attachment;
    }
}
