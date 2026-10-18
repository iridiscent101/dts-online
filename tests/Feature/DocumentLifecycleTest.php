<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\DocumentMovement;
use App\Models\User;
use App\Notifications\DocumentAssignedNotification;
use App\Notifications\DocumentChangedNotification;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Orchid\Platform\Models\Role;
use Tests\TestCase;

class DocumentLifecycleTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_paper_submission_can_pass_through_parallel_offices_and_return_to_records_for_release(): void
    {
        Notification::fake();
        $records = User::factory()->create(['office' => 'SDO-Records Unit', 'permissions' => ['platform.index' => true]]);
        $budget = User::factory()->create(['office' => 'SDO-Budget Unit', 'permissions' => ['platform.index' => true]]);
        $legal = User::factory()->create(['office' => 'SDO-Legal Unit', 'permissions' => ['platform.index' => true]]);
        $accounting = User::factory()->create(['office' => 'SDO-Accounting Unit', 'permissions' => ['platform.index' => true]]);

        $this->post(route('submission.store'), [
            'submission_key' => (string) Str::uuid(),
            'name' => 'Alex Rivera',
            'office' => 'School Office',
            'delivery_method' => 'Hand carry',
            'title' => 'Budget and legal request',
            'type' => 'Request',
            'privacy_consent' => true,
        ])->assertRedirect();
        $document = Document::query()->sole();
        $url = route('platform.documents.movements.store', $document);
        $this->assertSame(DocumentMovement::STATUS_AWAITING_PHYSICAL_DOCUMENT, $document->status);

        $this->actingAs($records)->postJson($url, [
            'workflow' => ['action' => 'received', 'source_office' => 'SDO-Records Unit'],
        ])->assertOk();
        $this->postJson($url, [
            'workflow' => ['action' => 'received', 'source_office' => 'SDO-Records Unit'],
        ])->assertUnprocessable()->assertJsonValidationErrors('workflow.action');
        $this->postJson($url, [
            'workflow' => [
                'action' => 'forwarded',
                'source_office' => 'SDO-Records Unit',
                'destinations' => ['SDO-Budget Unit', 'SDO-Legal Unit'],
            ],
        ])->assertOk();

        $this->actingAs($accounting)->get(route('platform.documents.workspace', $document))->assertNotFound();
        $this->actingAs($budget)->postJson($url, [
            'workflow' => ['action' => 'received', 'source_office' => 'SDO-Legal Unit'],
        ])->assertForbidden();
        $this->postJson($url, [
            'workflow' => ['action' => 'received', 'source_office' => 'SDO-Budget Unit'],
        ])->assertOk();
        $this->actingAs($legal)->postJson($url, [
            'workflow' => ['action' => 'received', 'source_office' => 'SDO-Legal Unit'],
        ])->assertOk();

        $this->actingAs($records)->postJson(route('platform.documents.routes.correct', $document), [
            'source_office' => 'SDO-Records Unit',
            'destination_office' => 'SDO-Legal Unit',
            'replacement_office' => 'SDO-Accounting Unit',
            'correction_reason' => 'Opened before Legal received its copy.',
        ])->assertUnprocessable()->assertJsonValidationErrors('destination_office');

        $this->actingAs($budget)->postJson($url, [
            'workflow' => ['action' => 'completed', 'source_office' => 'SDO-Budget Unit'],
        ])->assertOk();
        $this->assertSame(DocumentMovement::STATUS_RECEIVED, $document->fresh()->status);
        $this->actingAs($legal)->postJson($url, [
            'workflow' => ['action' => 'forwarded', 'source_office' => 'SDO-Legal Unit', 'destination' => 'SDO-Records Unit'],
        ])->assertOk();
        $this->actingAs($records)->postJson($url, [
            'workflow' => ['action' => 'received', 'source_office' => 'SDO-Records Unit'],
        ])->assertOk();
        $this->postJson($url, [
            'workflow' => [
                'action' => 'released',
                'source_office' => 'SDO-Records Unit',
                'released_to' => 'Jamie Cruz',
                'recipient_signature' => 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAEAAAAAYCAYAAABKtPtEAAAACXBIWXMAAA7EAAAOxAGVKw4bAAAAQUlEQVRYhe3QsRGAMAwDQCcbpGMD9h8nG9AxQuhyTIC583+nTlIEAACU1N5hHOfKKvKl+5p7d88s8gflDwAAoKoH38oEBtR+TtAAAAAASUVORK5CYII=',
            ],
        ])->assertOk();

        $this->assertSame(DocumentMovement::STATUS_RELEASED, $document->fresh()->status);
        $this->assertSame('Jamie Cruz', $document->movements()->latest('id')->value('released_to'));
        $this->assertSame(DocumentMovement::STATUS_COMPLETED, $document->officeAssignments()->where('office', 'SDO-Budget Unit')->value('status'));
        $this->assertSame(2, $document->movements()->where('action', DocumentMovement::ACTION_RECEIVED)->where('to_office', 'SDO-Records Unit')->count());
    }

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
