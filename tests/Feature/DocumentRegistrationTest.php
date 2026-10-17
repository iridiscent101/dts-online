<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\DocumentType;
use App\Models\Office;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Orchid\Attachment\File;
use Tests\TestCase;

class DocumentRegistrationTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.document_uploads_enabled' => true]);
    }

    public function test_registration_form_offers_repeatable_destination_routes(): void
    {
        $user = User::factory()->create(['office' => 'SDO-Records Unit', 'permissions' => ['platform.index' => true]]);

        $response = $this->actingAs($user)
            ->get(route('platform.documents.create'))
            ->assertOk()
            ->assertSee('Add route')
            ->assertSee('Register document')
            ->assertSee('Register this document?')
            ->assertSee('name="document[destinations][]"', false)
            ->assertSee('data-registration-remarks', false)
            ->assertSee('data-registration-route-template', false)
            ->assertDontSee('name="document[reference]"', false)
            ->assertDontSee('Review document')
            ->assertDontSee('Choose one to five offices');

        $this->assertSame(2, substr_count($response->getContent(), 'data-registration-route>'));
        $response->assertSee('Route 1');
    }

    public function test_other_office_staff_cannot_register_a_document_directly(): void
    {
        $user = User::factory()->create(['office' => 'SDO-Legal Unit', 'permissions' => ['platform.index' => true]]);

        $this->actingAs($user)->get(route('platform.documents.create'))->assertForbidden();
        $this->post(route('platform.documents.create').'/save', [
            'document' => $this->validDocumentData(),
        ])->assertForbidden();

        $this->assertDatabaseCount('documents', 0);
    }

    public function test_registration_rejects_a_future_received_date(): void
    {
        $user = User::factory()->create(['office' => 'SDO-Records Unit', 'permissions' => ['platform.index' => true]]);
        $data = $this->validDocumentData();
        $data['received'] = today('Asia/Manila')->addDay()->toDateString();

        $this->actingAs($user)->post(route('platform.documents.create').'/save', [
            'document' => $data,
        ])->assertSessionHasErrors('document.received');

        $this->assertDatabaseCount('documents', 0);
    }

    public function test_deactivated_catalog_entries_are_not_restored_as_registration_fallbacks(): void
    {
        DocumentType::query()->create(['name' => 'Memorandum', 'is_active' => false]);
        Office::query()->create(['name' => 'Records Unit', 'is_active' => false]);
        $user = User::factory()->create(['office' => 'SDO-Records Unit', 'permissions' => ['platform.index' => true]]);

        $this->actingAs($user)
            ->get(route('platform.documents.create'))
            ->assertOk()
            ->assertDontSee('value="Memorandum"', false)
            ->assertDontSee('value="Records Unit"', false);

        $this->from(route('platform.documents.create'))
            ->post(route('platform.documents.create').'/save', [
                'document' => $this->validDocumentData(),
            ])
            ->assertRedirect(route('platform.documents.create'))
            ->assertSessionHasErrors(['document.type', 'document.destinations.0']);

        $this->assertDatabaseCount('documents', 0);
    }

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

        $this->assertMatchesRegularExpression('/^SDONV[1-9]{11}$/', $document->tracking_number);
        $this->assertSame('Regional Office', $document->origin);
        $this->assertSame('Records Unit', $document->current_office);
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

    public function test_registration_ignores_removed_due_and_priority_fields(): void
    {
        $user = User::factory()->create([
            'office' => 'Records Unit',
            'permissions' => ['platform.index' => true],
        ]);
        $documentData = $this->validDocumentData();
        $documentData['received'] = '2026-10-05';
        $documentData['priority'] = 'Extended';
        $documentData['due'] = '2026-10-01';

        $this->actingAs($user)->post(route('platform.documents.create').'/save', [
            'document' => $documentData,
        ])->assertRedirectToRoute('platform.documents');

        $document = Document::query()->sole();

        // Priority is no longer chosen; a sent value is ignored and new documents are saved as "Standard".
        $this->assertSame('Standard', $document->priority);
    }

    public function test_registration_rejects_unsupported_attachments(): void
    {
        Storage::fake('local');
        $user = User::factory()->create(['office' => 'SDO-Records Unit', 'permissions' => ['platform.index' => true]]);

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
        $user = User::factory()->create(['office' => 'SDO-Records Unit', 'permissions' => ['platform.index' => true]]);
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

    public function test_registration_uses_the_selected_initial_destination(): void
    {
        $user = User::factory()->create([
            'office' => 'Records Unit',
            'permissions' => ['platform.index' => true],
        ]);
        $documentData = $this->validDocumentData();
        $documentData['destinations'] = ['Administrative Unit', 'Records Unit', 'Curriculum Implementation Division'];
        $documentData['route_remarks'][2] = 'Start with admin review';

        $response = $this->actingAs($user)->post(route('platform.documents.create').'/save', [
            'document' => $documentData,
        ]);

        $response->assertRedirectToRoute('platform.documents');

        $document = Document::query()->sole();

        $this->assertSame('Administrative Unit', $document->current_office);
        $this->assertSame('Start with admin review', $document->remarks);
        $this->assertDatabaseCount('document_route_steps', 0);
    }

    public function test_registration_assigns_multiple_initial_offices_with_separate_remarks(): void
    {
        $registrar = User::factory()->create([
            'office' => 'Records Unit',
            'permissions' => ['platform.index' => true],
        ]);
        $administrativeUser = User::factory()->create([
            'office' => 'Administrative Unit',
            'permissions' => ['platform.index' => true],
        ]);
        $curriculumUser = User::factory()->create([
            'office' => 'Curriculum Implementation Division',
            'permissions' => ['platform.index' => true],
        ]);
        $documentData = $this->validDocumentData();
        unset($documentData['destination'], $documentData['remarks']);
        $documentData['destinations'] = ['Administrative Unit', 'Records Unit', 'Curriculum Implementation Division'];
        $documentData['route_remarks'] = ['Review the records.', '', 'Review the curriculum.'];

        $this->actingAs($registrar)->post(route('platform.documents.create').'/save', [
            'document' => $documentData,
        ])->assertRedirectToRoute('platform.documents');

        $document = Document::query()->sole();

        $this->assertSame('Administrative Unit', $document->current_office);
        $this->assertSame('Review the curriculum.', $document->remarks);
        $this->assertDatabaseHas('document_office_assignments', [
            'document_id' => $document->id,
            'office' => 'Administrative Unit',
            'status' => 'Awaiting receipt',
        ]);
        $this->assertDatabaseHas('document_office_assignments', [
            'document_id' => $document->id,
            'office' => 'Curriculum Implementation Division',
            'status' => 'Awaiting receipt',
        ]);
        $this->assertDatabaseHas('document_movements', [
            'document_id' => $document->id,
            'action' => 'sent',
            'from_office' => null,
            'to_office' => 'Administrative Unit',
            'remarks' => 'Review the records.',
        ]);
        $this->assertDatabaseHas('document_movements', [
            'document_id' => $document->id,
            'action' => 'sent',
            'from_office' => null,
            'to_office' => 'Curriculum Implementation Division',
            'remarks' => 'Review the curriculum.',
        ]);

        $this->actingAs($administrativeUser)
            ->get(route('platform.incoming'))
            ->assertOk()
            ->assertSee($document->tracking_number);

        $this->actingAs($curriculumUser)
            ->get(route('platform.incoming'))
            ->assertOk()
            ->assertSee($document->tracking_number);

        $this->actingAs($administrativeUser)
            ->get(route('platform.documents.view', $document))
            ->assertOk()
            ->assertSee('Sent to Administrative Unit')
            ->assertSee('title="Pending: Awaiting acknowledgment of receipt">Pending</span>', false)
            ->assertSee('Review the records.')
            ->assertDontSee('Sent to Curriculum Implementation Division')
            ->assertDontSee('Review the curriculum.');

        $this->get(route('platform.main'))
            ->assertOk()
            ->assertSee(route('platform.documents.view', [
                'document' => $document,
                'source_office' => 'Administrative Unit',
            ]))
            ->assertSee('data-workspace-office="Administrative Unit"', false);

        $this->actingAs($curriculumUser)
            ->get(route('platform.documents.view', $document))
            ->assertOk();

        $this->post(route('platform.documents.movements.store', $document), [
            'workflow' => ['action' => 'received', 'remarks' => 'Curriculum received its copy.'],
        ])->assertRedirectToRoute('platform.documents.view', $document);

        $this->get(route('platform.incoming'))
            ->assertOk()
            ->assertDontSee('<strong>'.$document->tracking_number.'</strong>', false);
        $this->actingAs($administrativeUser)
            ->get(route('platform.incoming'))
            ->assertOk()
            ->assertSee($document->tracking_number);

        $this->actingAs($administrativeUser)
            ->post(route('platform.documents.movements.store', $document), [
                'workflow' => ['action' => 'received', 'remarks' => 'Administration received its copy.'],
            ])->assertRedirectToRoute('platform.documents.view', $document);

        $this->assertDatabaseHas('document_office_assignments', [
            'document_id' => $document->id,
            'office' => 'Administrative Unit',
            'status' => 'Received',
        ]);
        $this->assertDatabaseHas('document_office_assignments', [
            'document_id' => $document->id,
            'office' => 'Curriculum Implementation Division',
            'status' => 'Received',
        ]);
    }

    public function test_registration_requires_an_initial_destination(): void
    {
        $user = User::factory()->create(['office' => 'SDO-Records Unit', 'permissions' => ['platform.index' => true]]);
        $documentData = $this->validDocumentData();
        unset($documentData['destination'], $documentData['destinations']);

        $this->actingAs($user)
            ->from(route('platform.documents.create'))
            ->post(route('platform.documents.create').'/save', [
                'document' => $documentData,
            ])
            ->assertRedirect(route('platform.documents.create'))
            ->assertSessionHasErrors('document.destinations');

        $this->assertDatabaseCount('documents', 0);
        $this->assertDatabaseCount('document_route_steps', 0);
    }

    public function test_registration_requires_one_to_five_initial_destinations(): void
    {
        $user = User::factory()->create(['office' => 'SDO-Records Unit', 'permissions' => ['platform.index' => true]]);
        $documentData = $this->validDocumentData();

        foreach ([
            [[], 'Select at least one initial destination office.'],
            [array_slice(Document::officeOptions(), 0, 6), 'Select no more than five initial destination offices.'],
        ] as [$destinations, $message]) {
            $documentData['destinations'] = $destinations;
            $documentData['route_remarks'] = array_fill(0, count($destinations), '');

            $this->actingAs($user)
                ->from(route('platform.documents.create'))
                ->post(route('platform.documents.create').'/save', ['document' => $documentData])
                ->assertRedirect(route('platform.documents.create'))
                ->assertSessionHasErrors(['document.destinations' => $message]);
        }

        $this->assertDatabaseCount('documents', 0);
    }

    public function test_registration_accepts_a_single_initial_destination(): void
    {
        $user = User::factory()->create([
            'office' => 'Records Unit',
            'permissions' => ['platform.index' => true],
        ]);
        $documentData = $this->validDocumentData();
        $documentData['destinations'] = ['Records Unit'];
        $documentData['route_remarks'] = ['For immediate routing.'];

        $this->actingAs($user)
            ->post(route('platform.documents.create').'/save', ['document' => $documentData])
            ->assertRedirectToRoute('platform.documents');

        $document = Document::query()->sole();

        $this->assertSame('Records Unit', $document->current_office);
        $this->assertCount(1, $document->officeAssignments);
        $this->assertDatabaseCount('document_office_assignments', 1);
    }

    public function test_registration_accepts_five_initial_destinations(): void
    {
        $user = User::factory()->create(['office' => 'SDO-Records Unit', 'permissions' => ['platform.index' => true]]);
        $documentData = $this->validDocumentData();
        $documentData['destinations'] = array_slice(Document::officeOptions(), 0, 5);
        $documentData['route_remarks'] = array_fill(0, 5, '');

        $this->actingAs($user)
            ->post(route('platform.documents.create').'/save', ['document' => $documentData])
            ->assertRedirectToRoute('platform.documents');

        $this->assertDatabaseCount('document_office_assignments', 5);
    }

    public function test_registration_rejects_unknown_initial_destination_offices(): void
    {
        $user = User::factory()->create(['office' => 'SDO-Records Unit', 'permissions' => ['platform.index' => true]]);
        $documentData = $this->validDocumentData();
        $documentData['destination'] = 'Unknown Office';

        $this->actingAs($user)
            ->from(route('platform.documents.create'))
            ->post(route('platform.documents.create').'/save', [
                'document' => $documentData,
            ])
            ->assertRedirect(route('platform.documents.create'))
            ->assertSessionHasErrors('document.destination');

        $this->assertDatabaseCount('documents', 0);
        $this->assertDatabaseCount('document_route_steps', 0);
    }

    public function test_registration_rejects_duplicate_initial_offices(): void
    {
        $user = User::factory()->create(['office' => 'SDO-Records Unit', 'permissions' => ['platform.index' => true]]);
        $documentData = $this->validDocumentData();
        unset($documentData['destination']);
        $documentData['destinations'] = ['Administrative Unit', 'Administrative Unit', 'Records Unit'];

        $this->actingAs($user)
            ->from(route('platform.documents.create'))
            ->post(route('platform.documents.create').'/save', ['document' => $documentData])
            ->assertRedirect(route('platform.documents.create'))
            ->assertSessionHasErrors([
                'document.destinations.0' => 'Select each destination office only once.',
            ]);

        $this->assertDatabaseCount('documents', 0);
    }

    public function test_registration_rejects_remarks_that_do_not_match_the_destinations(): void
    {
        $user = User::factory()->create(['office' => 'SDO-Records Unit', 'permissions' => ['platform.index' => true]]);
        $documentData = $this->validDocumentData();
        unset($documentData['destination'], $documentData['remarks']);
        $documentData['destinations'] = ['Administrative Unit', 'Records Unit', 'Curriculum Implementation Division'];
        $documentData['route_remarks'] = ['Only one note'];

        $this->actingAs($user)
            ->from(route('platform.documents.create'))
            ->post(route('platform.documents.create').'/save', ['document' => $documentData])
            ->assertRedirect(route('platform.documents.create'))
            ->assertSessionHasErrors([
                'document.route_remarks' => 'Provide one remarks field for each destination office.',
            ]);

        $this->assertDatabaseCount('documents', 0);
    }

    public function test_authorized_staff_can_open_attachment_endpoints_while_controls_are_hidden(): void
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
            ->assertDontSee('retention-guide.pdf')
            ->assertDontSee(route('platform.documents.attachments.preview', [$document, $attachment]), false)
            ->assertDontSee(route('platform.documents.attachments.download', [$document, $attachment]), false);

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
     * @return array<string, mixed>
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
            'destination' => 'Records Unit',
            'destinations' => ['Records Unit', 'Administrative Unit', 'Curriculum Implementation Division'],
            'priority' => 'Extended',
            'remarks' => 'For immediate routing.',
            'route_remarks' => ['For immediate routing.', 'For immediate routing.', 'For immediate routing.'],
        ];
    }
}
