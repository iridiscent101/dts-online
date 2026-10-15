<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\User;
use App\Notifications\DocumentAssignedNotification;
use App\Notifications\DocumentChangedNotification;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Orchid\Platform\Models\Role;
use Tests\TestCase;

class DocumentLifecycleTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_document_completes_the_operational_lifecycle_with_rbac_notifications_and_auditing(): void
    {
        config(['app.document_uploads_enabled' => true]);
        Storage::fake('local');
        Notification::fake();

        $staffRole = Role::create([
            'name' => 'Document Staff',
            'slug' => 'document-staff',
            'permissions' => [
                'platform.index' => true,
                'platform.documents.edit' => true,
                'platform.documents.attachments.manage' => true,
            ],
        ]);
        $adminRole = Role::create([
            'name' => 'Admin',
            'slug' => User::ADMIN_ROLE_SLUG,
            'permissions' => [],
        ]);

        $recordsOfficer = User::factory()->create([
            'name' => 'Records Officer',
            'office' => 'Records Unit',
            'permissions' => [],
        ]);
        $recordsOfficer->replaceRoles([$staffRole->id]);

        $administrativeOfficer = User::factory()->create([
            'name' => 'Administrative Officer',
            'office' => 'Administrative Unit',
            'permissions' => [],
        ]);
        $administrativeOfficer->replaceRoles([$staffRole->id]);

        $administrator = User::factory()->create([
            'name' => 'System Administrator',
            'office' => null,
            'permissions' => [],
        ]);
        $administrator->replaceRoles([$adminRole->id]);

        $this->actingAs($recordsOfficer)
            ->post(route('platform.documents.create').'/save', [
                'document' => [
                    'title' => 'Lifecycle readiness memorandum',
                    'type' => 'Memorandum',
                    'reference' => 'UAT-2026-001',
                    'description' => 'End-to-end operational readiness test.',
                    'origin' => 'Regional Office',
                    'sender' => 'Regional Records Officer',
                    'received' => '2026-09-30',
                    'due' => '2026-10-07',
                    'destinations' => ['Records Unit'],
                    'priority' => 'Extended',
                    'remarks' => 'Process through the full workflow.',
                ],
                'attachments' => [UploadedFile::fake()->create('readiness.pdf', 50, 'application/pdf')],
            ])
            ->assertRedirectToRoute('platform.documents');

        $document = Document::query()->sole();
        $attachment = $document->attachments()->sole();

        $this->post(route('platform.documents.movements.store', $document), [
            'workflow' => [
                'action' => 'acknowledge',
                'remarks' => 'Received by the Records Unit.',
            ],
        ])->assertRedirectToRoute('platform.documents.view', $document);

        $this->post(route('platform.documents.edit', $document).'/save', [
            'document' => [
                'details_fingerprint' => $document->fresh()->detailsFingerprint(),
                'title' => 'Updated lifecycle readiness memorandum',
                'type' => 'Memorandum',
                'reference' => 'UAT-2026-001',
                'description' => 'End-to-end operational readiness test completed.',
                'origin' => 'Regional Office',
                'sender' => 'Regional Records Officer',
                'received' => '2026-09-30',
                'due' => '2026-10-07',
                'priority' => 'Urgent',
                // Received above, so its details are locked and changing them needs a reason.
                'correction_reason' => 'Lifecycle test correction',
            ],
        ])->assertRedirectToRoute('platform.documents.view', $document);

        $this->get(route('platform.documents.attachments.download', [$document, $attachment]))
            ->assertDownload('readiness.pdf');

        $this->post(route('platform.documents.movements.store', $document), [
            'workflow' => [
                'action' => 'forward',
                'destination' => 'Administrative Unit',
                'remarks' => 'Forwarded for administrative action.',
            ],
        ])->assertRedirectToRoute('platform.documents.view', $document);

        $this->actingAs($administrativeOfficer)
            ->post(route('platform.documents.movements.store', $document), [
                'workflow' => [
                    'action' => 'acknowledge',
                    'remarks' => 'Received by the Administrative Unit.',
                ],
            ])
            ->assertRedirectToRoute('platform.documents.view', $document);

        $this->post(route('platform.documents.movements.store', $document), [
            'workflow' => [
                'action' => 'completed',
                'remarks' => 'Administrative processing completed.',
            ],
        ])->assertRedirectToRoute('platform.documents.view', $document);

        $this->actingAs($administrator)
            ->post(route('platform.documents.movements.store', $document), [
                'workflow' => [
                    'action' => 'restore',
                    'destination' => 'Records Unit',
                    'remarks' => 'Restored for the final records review.',
                ],
            ])
            ->assertRedirectToRoute('platform.documents.view', $document);

        $document->refresh();

        $this->assertSame('Records Unit', $document->current_office);
        $this->assertSame('Received', $document->status);
        $this->assertSame('Updated lifecycle readiness memorandum', $document->subject);
        $this->assertSame(
            ['sent', 'received', 'forwarded', 'received', 'completed', 'restore'],
            $document->movements()->reorder()->oldest()->pluck('action')->all(),
        );
        $this->assertSame(
            ['Attachment downloaded', 'Document details updated', 'Document registered'],
            $document->activities()->pluck('description')->sort()->values()->all(),
        );

        Notification::assertSentTo(
            $administrativeOfficer,
            DocumentAssignedNotification::class,
            fn (DocumentAssignedNotification $notification): bool => $notification->action === 'forwarded',
        );
        Notification::assertSentTo(
            $recordsOfficer,
            DocumentAssignedNotification::class,
            fn (DocumentAssignedNotification $notification): bool => $notification->action === 'restore',
        );
        Notification::assertSentTo(
            $recordsOfficer,
            DocumentChangedNotification::class,
            fn (DocumentChangedNotification $notification): bool => $notification->change === 'details',
        );

        $this->actingAs($administrator)
            ->get(route('platform.documents.view', $document))
            ->assertOk()
            ->assertSee('Last changed by')
            ->assertSee($administrator->name)
            ->assertSee('Last downloaded by')
            ->assertSee($recordsOfficer->name);
    }
}
