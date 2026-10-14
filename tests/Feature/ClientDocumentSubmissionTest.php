<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\DocumentMovement;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class ClientDocumentSubmissionTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.document_uploads_enabled' => true]);
    }

    public function test_guest_can_send_a_document_to_multiple_offices_with_remarks_without_exposing_identity(): void
    {
        Storage::fake('local');
        $this->travelTo('2026-10-05 09:00:00');
        $staff = User::factory()->create([
            'office' => 'Administrative Unit',
            'permissions' => ['platform.index' => true],
        ]);
        User::factory()->create(['office' => 'Curriculum Implementation Division']);

        $this->get(route('submission.create'))
            ->assertOk()
            ->assertSee(asset('images/icon.png'))
            ->assertSee('SDO – Nueva Vizcaya')
            ->assertSee('Your name')
            ->assertSee('Your school or office')
            ->assertSee('Type of document')
            ->assertSee('data-options="document-types"', false)
            ->assertSee('data-options="destination-offices"', false)
            ->assertSee('Add another office')
            ->assertDontSee('Employee number')
            ->assertDontSee('They are hidden from public tracking.')
            ->assertSee('Send document');

        $payload = $this->payload();
        $payload['destinations'] = ['Administrative Unit', 'Curriculum Implementation Division'];
        $payload['route_remarks'] = ['Review records.', 'Review curriculum.'];

        $response = $this->post(route('submission.store'), $payload);
        $document = Document::query()->sole();
        $response->assertRedirectToRoute('tracking.index', ['tracking' => $document->tracking_number]);
        $this->assertMatchesRegularExpression('/^DTS-\d{4}-[A-F0-9]{20}$/', $document->tracking_number);
        $this->assertSame('Awaiting receipt', $document->status);
        $this->assertSame('Request', $document->document_type);
        $this->assertSame('Standard', $document->priority);
        $this->assertSame('2026-10-14', $document->due_at->toDateString());
        $this->assertSame('Administrative Unit', $document->current_office);
        $this->assertNull($document->client_employee_number);
        $this->assertSame('Alex Rivera', $document->sender);
        $this->assertSame('School Office', $document->origin);
        $this->assertSame(1, $document->attachments()->count());
        Storage::disk('local')->assertExists($document->attachments()->sole()->physicalPath());
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
            'to_office' => 'Administrative Unit',
            'remarks' => 'Review records.',
        ]);
        $this->assertDatabaseHas('document_movements', [
            'document_id' => $document->id,
            'to_office' => 'Curriculum Implementation Division',
            'remarks' => 'Review curriculum.',
        ]);
        $this->assertDatabaseCount('notifications', 2);

        $receipt = $this->get(route('tracking.index', ['tracking' => $document->tracking_number]));
        $receipt
            ->assertOk()
            ->assertSee('Save your tracking QR code')
            ->assertSee('Target date')
            ->assertSee('Oct 14, 2026')
            ->assertSee('QR code for '.$document->tracking_number)
            ->assertSee('data:image/svg+xml;base64,', false)
            ->assertDontSee('Submit your document to the Records staff.');

        preg_match('/src="data:image\/svg\+xml;base64,([^"]+)"/', $receipt->getContent(), $matches);
        $this->assertStringContainsString('<svg', base64_decode($matches[1] ?? '', true) ?: '');

        $this->get(route('tracking.index', ['tracking' => $document->tracking_number]))
            ->assertOk()
            ->assertSee('Document submitted')
            ->assertSee('Client document')
            ->assertSee('Administrative Unit')
            ->assertSee('Curriculum Implementation Division')
            ->assertDontSee('Travel request')
            ->assertDontSee('Alex Rivera')
            ->assertDontSee('School Office')
            ->assertDontSee('Review records.')
            ->assertDontSee('Review curriculum.');

        $this->actingAs($staff)
            ->get(route('platform.documents.view', $document))
            ->assertOk()
            ->assertSee('Alex Rivera')
            ->assertSee('School Office')
            ->assertSee('Review records.')
            ->assertDontSee('Review curriculum.')
            ->assertDontSee('Employee number');
    }

    public function test_public_submission_uses_manila_date_and_year(): void
    {
        $this->travelTo(Carbon::parse('2026-12-31 23:30:00', 'UTC'));
        $payload = $this->payload();
        unset($payload['attachment']);

        $this->post(route('submission.store'), $payload)
            ->assertRedirect();

        $document = Document::query()->sole();
        $this->assertStringStartsWith('DTS-2027-', $document->tracking_number);
        $this->assertNull($document->received_at);
        $this->assertSame(DocumentMovement::STATUS_AWAITING_PHYSICAL_DOCUMENT, $document->status);
    }

    public function test_retry_with_same_submission_key_returns_the_first_receipt(): void
    {
        Storage::fake('local');
        $key = (string) Str::uuid();

        $firstResponse = $this->post(route('submission.store'), $this->payload($key));
        $this->post(route('submission.store'), $this->payload($key))
            ->assertRedirect($firstResponse->headers->get('Location'));

        $this->assertDatabaseCount('documents', 1);
        $this->assertDatabaseCount('attachments', 1);
        $this->assertDatabaseCount('document_office_assignments', 1);
        $this->assertDatabaseCount('document_movements', 1);
    }

    public function test_guest_can_submit_a_physical_document_without_uploading_a_file(): void
    {
        Storage::fake('local');
        $recordsStaff = User::factory()->create([
            'office' => 'SDO-Records Unit',
            'permissions' => [
                'platform.index' => true,
                'platform.documents.edit' => true,
            ],
        ]);
        $destinationStaff = User::factory()->create([
            'office' => 'Administrative Unit',
            'permissions' => ['platform.index' => true],
        ]);
        $key = (string) Str::uuid();
        $payload = $this->payload($key);
        unset($payload['attachment']);

        $this->get(route('submission.create'))
            ->assertOk()
            ->assertSee('Document file')
            ->assertSee('(optional)')
            ->assertDontSee('give Records staff your tracking number');

        $firstResponse = $this->post(route('submission.store'), $payload);
        $firstResponse->assertRedirectToRoute('tracking.index', ['tracking' => Document::query()->sole()->tracking_number]);
        $this->post(route('submission.store'), $payload)
            ->assertRedirect($firstResponse->headers->get('Location'));

        $document = Document::query()->sole();
        $this->assertSame('SDO-Records Unit', $document->current_office);
        $this->assertSame(DocumentMovement::STATUS_AWAITING_PHYSICAL_DOCUMENT, $document->status);
        $this->assertNull($document->received_at);
        $this->assertNull($document->registered_by);
        $this->assertNull($document->due_at);
        $this->assertSame(0, $document->attachments()->count());
        $this->assertDatabaseCount('attachments', 0);
        $this->assertDatabaseHas('document_office_assignments', [
            'document_id' => $document->id,
            'office' => 'SDO-Records Unit',
        ]);
        $this->assertDatabaseHas('document_route_steps', [
            'document_id' => $document->id,
            'office' => 'Administrative Unit',
            'remarks' => 'Please review.',
        ]);
        $this->assertDatabaseCount('document_movements', 1);
        $this->assertDatabaseCount('document_office_assignments', 1);
        $this->assertDatabaseCount('notifications', 1);

        $this->actingAs($destinationStaff)
            ->get(route('platform.documents.view', $document))
            ->assertNotFound();

        $this->get(route('tracking.index', ['tracking' => $document->tracking_number]))
            ->assertOk()
            ->assertSee('Submit your document to the Records staff.')
            ->assertSee('Awaiting physical document')
            ->assertDontSee('Assigned to Administrative Unit')
            ->assertSee('Date submitted');

        $this->actingAs($recordsStaff)
            ->get(route('platform.main'))
            ->assertOk()
            ->assertSee($document->tracking_number)
            ->assertSee('Paper received');

        $this->get(route('platform.incoming'))
            ->assertOk()
            ->assertSee($document->tracking_number);

        $this->actingAs($recordsStaff)
            ->get(route('platform.documents.view', $document))
            ->assertOk()
            ->assertSee('Alex Rivera')
            ->assertSee('Not received')
            ->assertSee('Requested destinations after paper intake')
            ->assertSee('Confirm paper received');

        $this->get(route('platform.documents'))
            ->assertOk()
            ->assertSee($document->tracking_number)
            ->assertSee('Not received');

        $this->actingAs($recordsStaff)->post(route('platform.documents.movements.store', $document), [
            'workflow' => [
                'action' => 'received',
                'source_office' => 'SDO-Records Unit',
                'remarks' => 'Paper copy accepted.',
            ],
        ])->assertRedirectToRoute('platform.documents.view', $document);

        $document->refresh();
        $this->assertSame('Administrative Unit', $document->current_office);
        $this->assertSame('Forwarded', $document->status);
        $this->assertSame($recordsStaff->id, $document->registered_by);
        $this->assertSame(today()->toDateString(), $document->received_at->toDateString());
        $this->assertSame(Document::targetDueDate(today(), 'Standard')->toDateString(), $document->due_at->toDateString());
        $this->assertDatabaseCount('document_route_steps', 0);
        $this->assertDatabaseCount('document_movements', 3);
        $this->assertDatabaseCount('document_office_assignments', 1);
        $this->assertDatabaseCount('notifications', 2);
        $this->assertDatabaseHas('document_movements', [
            'document_id' => $document->id,
            'action' => 'received',
            'acted_by' => $recordsStaff->id,
            'from_status' => DocumentMovement::STATUS_AWAITING_PHYSICAL_DOCUMENT,
        ]);

        $this->actingAs($recordsStaff)
            ->get(route('platform.documents'))
            ->assertOk()
            ->assertSee($document->tracking_number);
        $this->get(route('platform.incoming'))
            ->assertOk()
            ->assertDontSee($document->tracking_number);
        $this->get(route('platform.documents.view', $document))
            ->assertOk()
            ->assertSee('Alex Rivera');
        $this->get(route('platform.documents.edit', $document))
            ->assertForbidden();

        $unrelatedStaff = User::factory()->create([
            'office' => 'Finance Unit',
            'permissions' => ['platform.index' => true],
        ]);
        $this->actingAs($unrelatedStaff)
            ->get(route('platform.documents.view', $document))
            ->assertNotFound();

        $this->actingAs($destinationStaff)
            ->get(route('platform.documents.view', $document))
            ->assertOk();

        $this->get(route('tracking.index', ['tracking' => $document->tracking_number]))
            ->assertOk()
            ->assertSee('Records Unit received the paper document')
            ->assertDontSee('Submit your document to the Records staff.');
    }

    public function test_records_is_not_added_twice_when_selected_for_a_physical_document(): void
    {
        Storage::fake('local');
        $recordsStaff = User::factory()->create([
            'office' => 'SDO-Records Unit',
            'permissions' => ['platform.index' => true],
        ]);
        $payload = $this->payload();
        $payload['destinations'] = ['SDO-Records Unit'];
        unset($payload['attachment']);

        $response = $this->post(route('submission.store'), $payload);
        $response->assertRedirectToRoute('tracking.index', ['tracking' => Document::query()->sole()->tracking_number]);

        $this->assertDatabaseCount('documents', 1);
        $this->assertDatabaseCount('document_office_assignments', 1);
        $this->assertDatabaseCount('document_movements', 1);
        $this->assertDatabaseCount('notifications', 1);
        $this->assertDatabaseCount('document_route_steps', 1);

        $document = Document::query()->sole();
        $this->actingAs($recordsStaff)->post(route('platform.documents.movements.store', $document), [
            'workflow' => [
                'action' => 'received',
                'source_office' => 'SDO-Records Unit',
                'remarks' => 'Paper copy accepted.',
            ],
        ])->assertRedirectToRoute('platform.documents.view', $document);

        $this->assertSame(DocumentMovement::STATUS_RECEIVED, $document->fresh()->status);
        $this->assertDatabaseCount('document_office_assignments', 1);
        $this->assertDatabaseCount('document_movements', 2);
        $this->assertDatabaseCount('document_route_steps', 0);
    }

    public function test_records_remains_assigned_when_it_is_also_a_requested_destination(): void
    {
        Storage::fake('local');
        $recordsStaff = User::factory()->create([
            'office' => 'SDO-Records Unit',
            'permissions' => ['platform.index' => true],
        ]);
        $payload = $this->payload();
        $payload['destinations'] = ['SDO-Records Unit', 'Administrative Unit'];
        $payload['route_remarks'] = ['File at Records.', 'Review this.'];
        unset($payload['attachment']);
        $this->post(route('submission.store'), $payload);
        $document = Document::query()->sole();

        $this->actingAs($recordsStaff)->post(route('platform.documents.movements.store', $document), [
            'workflow' => [
                'action' => 'received',
                'source_office' => 'SDO-Records Unit',
                'remarks' => 'Paper copy accepted.',
            ],
        ])->assertRedirectToRoute('platform.documents.view', $document);

        $this->assertDatabaseCount('document_office_assignments', 2);
        $this->assertDatabaseHas('document_office_assignments', [
            'document_id' => $document->id,
            'office' => 'SDO-Records Unit',
            'status' => DocumentMovement::STATUS_RECEIVED,
        ]);
        $this->assertDatabaseHas('document_office_assignments', [
            'document_id' => $document->id,
            'office' => 'Administrative Unit',
            'status' => DocumentMovement::STATUS_FORWARDED,
        ]);
        $this->assertDatabaseHas('document_movements', [
            'document_id' => $document->id,
            'to_office' => 'SDO-Records Unit',
            'remarks' => 'File at Records.',
        ]);
    }

    public function test_records_receipt_rolls_back_when_dispatch_notification_fails_then_retries_once(): void
    {
        Storage::fake('local');
        $recordsStaff = User::factory()->create([
            'office' => 'SDO-Records Unit',
            'permissions' => ['platform.index' => true],
        ]);
        User::factory()->create(['office' => 'Administrative Unit']);
        $payload = $this->payload();
        unset($payload['attachment']);
        $this->post(route('submission.store'), $payload);
        $document = Document::query()->sole();

        $fail = true;
        Event::listen(NotificationSent::class, function () use (&$fail): void {
            if ($fail) {
                throw new \RuntimeException('Destination notification failed.');
            }
        });
        $receipt = [
            'workflow' => [
                'action' => 'received',
                'source_office' => 'SDO-Records Unit',
                'remarks' => 'Paper copy accepted.',
            ],
        ];

        $this->actingAs($recordsStaff)
            ->post(route('platform.documents.movements.store', $document), $receipt)
            ->assertInternalServerError();

        $document->refresh();
        $this->assertSame(DocumentMovement::STATUS_AWAITING_PHYSICAL_DOCUMENT, $document->status);
        $this->assertNull($document->registered_by);
        $this->assertDatabaseCount('document_route_steps', 1);
        $this->assertDatabaseCount('document_movements', 1);
        $this->assertDatabaseCount('document_office_assignments', 1);
        $this->assertDatabaseCount('notifications', 1);

        $fail = false;
        $this->post(route('platform.documents.movements.store', $document), $receipt)
            ->assertRedirectToRoute('platform.documents.view', $document);
        $this->assertDatabaseCount('document_route_steps', 0);
        $this->assertDatabaseCount('document_movements', 3);
        $this->assertDatabaseCount('document_office_assignments', 1);
        $this->assertDatabaseCount('notifications', 2);

        $this->post(route('platform.documents.movements.store', $document), $receipt)
            ->assertForbidden();
        $this->assertDatabaseCount('document_movements', 3);
    }

    public function test_name_and_office_are_required(): void
    {
        Storage::fake('local');
        $payload = $this->payload();
        unset($payload['name'], $payload['office']);

        $this->from(route('submission.create'))
            ->post(route('submission.store'), $payload)
            ->assertRedirect(route('submission.create'))
            ->assertSessionHasErrors(['name', 'office']);

        $this->assertDatabaseCount('documents', 0);
    }

    public function test_type_destinations_and_matching_remarks_are_validated(): void
    {
        Storage::fake('local');
        $payload = $this->payload();
        $payload['type'] = 'Unknown type';
        $payload['destinations'] = ['Administrative Unit', 'Administrative Unit'];
        $payload['route_remarks'] = ['Only one note'];

        $this->from(route('submission.create'))
            ->post(route('submission.store'), $payload)
            ->assertRedirect(route('submission.create'))
            ->assertSessionHasErrors([
                'type' => 'Select a recognized document type.',
                'destinations.0' => 'Select each destination office only once.',
                'route_remarks' => 'Provide one remarks field for each destination office.',
            ]);

        $this->assertDatabaseCount('documents', 0);
    }

    public function test_client_cannot_request_more_than_five_destination_offices(): void
    {
        $payload = $this->payload();
        unset($payload['attachment']);
        $payload['destinations'] = array_slice(Document::officeOptions(), 0, 6);
        $payload['route_remarks'] = array_fill(0, 6, null);

        $this->from(route('submission.create'))
            ->post(route('submission.store'), $payload)
            ->assertRedirect(route('submission.create'))
            ->assertSessionHasErrors(['destinations' => 'Select no more than five destination offices.']);

        $this->assertDatabaseCount('documents', 0);
    }

    public function test_unrecognized_destination_cannot_receive_a_client_document(): void
    {
        Storage::fake('local');
        $payload = $this->payload();
        $payload['destinations'] = ['Unrecognized office'];

        $this->from(route('submission.create'))
            ->post(route('submission.store'), $payload)
            ->assertRedirect(route('submission.create'))
            ->assertSessionHasErrors([
                'destinations.0' => 'Select a recognized destination office.',
            ]);

        $this->assertDatabaseCount('documents', 0);
    }

    public function test_invalid_file_does_not_create_a_submission(): void
    {
        Storage::fake('local');
        $payload = $this->payload();
        $payload['attachment'] = UploadedFile::fake()->create('script.exe', 1, 'application/octet-stream');

        $this->from(route('submission.create'))
            ->post(route('submission.store'), $payload)
            ->assertRedirect(route('submission.create'))
            ->assertSessionHasErrors('attachment');

        $this->assertDatabaseCount('documents', 0);
    }

    public function test_file_over_ten_megabytes_is_rejected(): void
    {
        Storage::fake('local');
        $payload = $this->payload();
        $payload['attachment'] = UploadedFile::fake()->create('large.pdf', 11 * 1024, 'application/pdf');

        $this->from(route('submission.create'))
            ->post(route('submission.store'), $payload)
            ->assertRedirect(route('submission.create'))
            ->assertSessionHasErrors('attachment');

        $this->assertDatabaseCount('documents', 0);
    }

    public function test_notification_failure_rolls_back_submission_and_allows_retry(): void
    {
        Storage::fake('local');
        User::factory()->create(['office' => 'Administrative Unit']);
        User::factory()->create(['office' => 'Curriculum Implementation Division']);
        $key = (string) Str::uuid();
        $fail = true;
        $notificationsSent = 0;
        Event::listen(NotificationSent::class, function () use (&$fail, &$notificationsSent): void {
            if ($fail && ++$notificationsSent === 2) {
                throw new \RuntimeException('Notification delivery failed.');
            }
        });
        $payload = $this->payload($key);
        $payload['destinations'] = ['Administrative Unit', 'Curriculum Implementation Division'];
        $payload['route_remarks'] = ['Review records.', 'Review curriculum.'];

        $this->from(route('submission.create'))
            ->post(route('submission.store'), $payload)
            ->assertRedirect(route('submission.create'))
            ->assertSessionHasErrors('submission');

        $this->assertDatabaseCount('documents', 0);
        $this->assertDatabaseCount('attachments', 0);
        $this->assertDatabaseCount('document_movements', 0);
        $this->assertDatabaseCount('document_office_assignments', 0);
        $this->assertDatabaseCount('notifications', 0);
        $this->assertSame([], Storage::disk('local')->allFiles('documents'));

        $fail = false;
        $response = $this->post(route('submission.store'), $payload);
        $response->assertRedirectToRoute('tracking.index', ['tracking' => Document::query()->sole()->tracking_number]);

        $this->assertDatabaseCount('documents', 1);
        $this->assertDatabaseCount('attachments', 1);
        $this->assertDatabaseCount('document_movements', 2);
        $this->assertDatabaseCount('document_office_assignments', 2);
        $this->assertDatabaseCount('notifications', 2);
    }

    public function test_a_filled_in_honeypot_turns_the_submission_away(): void
    {
        Storage::fake('local');
        User::factory()->create(['office' => 'Administrative Unit', 'permissions' => ['platform.index' => true]]);

        $this->get(route('submission.create'))
            ->assertOk()
            ->assertSee('name="contact_reference"', false)
            ->assertSee('tabindex="-1"', false);

        $this->post(route('submission.store'), [...$this->payload(), 'contact_reference' => 'https://spam.example'])
            ->assertSessionHasErrors(['contact_reference' => 'The document could not be sent. Please try again.']);

        $this->assertDatabaseCount('documents', 0);
    }

    /**
     * @return array<string, string|list<string>|UploadedFile>
     */
    public function test_submission_requires_agreeing_to_the_privacy_notice(): void
    {
        $payload = $this->payload();
        unset($payload['privacy_consent']);

        $this->post(route('submission.store'), $payload)
            ->assertSessionHasErrors(['privacy_consent' => 'Agree to the privacy notice to send your document.']);

        $this->assertDatabaseCount('documents', 0);
        $this->get(route('submission.create'))->assertSee('Privacy notice')->assertSee('name="privacy_consent"', false);
    }

    private function payload(?string $key = null): array
    {
        return [
            'submission_key' => $key ?? (string) Str::uuid(),
            'name' => 'Alex Rivera',
            'office' => 'School Office',
            'title' => 'Travel request',
            'type' => 'Request',
            'destinations' => ['Administrative Unit'],
            'route_remarks' => ['Please review.'],
            'attachment' => UploadedFile::fake()->createWithContent('request.pdf', '%PDF-1.4 sample'),
            'privacy_consent' => '1',
        ];
    }
}
