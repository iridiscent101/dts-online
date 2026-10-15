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

    public function test_guest_upload_goes_to_records_without_exposing_identity(): void
    {
        Storage::fake('local');
        $this->travelTo('2026-10-05 09:00:00');
        $staff = User::factory()->create([
            'office' => 'SDO-Records Unit',
            'permissions' => ['platform.index' => true],
        ]);

        $this->get(route('submission.create'))
            ->assertOk()
            ->assertSee(asset('images/icon.png'))
            ->assertSee('SDO – Nueva Vizcaya')
            ->assertSee('Your name')
            ->assertSee('Your school or office')
            ->assertSee('Type of document')
            ->assertSee('data-options="document-types"', false)
            ->assertDontSee('data-options="destination-offices"', false)
            ->assertDontSee('Add another office')
            ->assertDontSee('Employee number')
            ->assertDontSee('They are hidden from public tracking.')
            ->assertSee('Send document');

        $response = $this->post(route('submission.store'), $this->payload());
        $document = Document::query()->sole();
        $response->assertRedirectToRoute('tracking.index', ['tracking' => $document->tracking_number]);
        $this->assertMatchesRegularExpression('/^SDONV[1-9]{11}$/', $document->tracking_number);
        $this->assertSame('Awaiting receipt', $document->status);
        $this->assertSame('Request', $document->document_type);
        $this->assertSame('Standard', $document->priority);
        $this->assertNull($document->due_at);
        $this->assertSame('SDO-Records Unit', $document->current_office);
        $this->assertNull($document->client_employee_number);
        $this->assertSame('Alex Rivera', $document->sender);
        $this->assertSame('School Office', $document->origin);
        $this->assertSame('Hand carry', $document->delivery_method);
        $this->assertSame(1, $document->attachments()->count());
        Storage::disk('local')->assertExists($document->attachments()->sole()->physicalPath());
        $this->assertDatabaseHas('document_office_assignments', [
            'document_id' => $document->id,
            'office' => 'SDO-Records Unit',
            'status' => 'Awaiting receipt',
        ]);
        $this->assertDatabaseHas('document_movements', [
            'document_id' => $document->id,
            'to_office' => 'SDO-Records Unit',
            'remarks' => null,
        ]);
        $this->assertDatabaseCount('document_route_steps', 0);
        $this->assertDatabaseCount('notifications', 1);

        $receipt = $this->get(route('tracking.index', ['tracking' => $document->tracking_number]));
        $receipt
            ->assertOk()
            ->assertSee('Save your tracking QR code')
            ->assertSee('QR code for '.$document->tracking_number)
            ->assertSee('data:image/svg+xml;base64,', false)
            ->assertDontSee('Submit your document to the Records staff.');

        preg_match('/src="data:image\/svg\+xml;base64,([^"]+)"/', $receipt->getContent(), $matches);
        $this->assertStringContainsString('<svg', base64_decode($matches[1] ?? '', true) ?: '');

        $this->get(route('tracking.index', ['tracking' => $document->tracking_number]))
            ->assertOk()
            ->assertSee('Document submitted')
            ->assertSee('Client document')
            ->assertSee('SDO-Records Unit')
            ->assertDontSee('Travel request')
            ->assertDontSee('Alex Rivera')
            ->assertDontSee('School Office');

        $this->actingAs($staff)
            ->get(route('platform.documents.view', $document))
            ->assertOk()
            ->assertSee('Alex Rivera')
            ->assertSee('School Office')
            ->assertDontSee('Employee number');
    }

    public function test_public_submission_uses_the_new_tracking_format_across_a_year_boundary(): void
    {
        $this->travelTo(Carbon::parse('2026-12-31 23:30:00', 'UTC'));
        $payload = $this->payload();
        unset($payload['attachment']);

        $this->post(route('submission.store'), $payload)
            ->assertRedirect();

        $document = Document::query()->sole();
        $this->assertMatchesRegularExpression('/^SDONV[1-9]{11}$/', $document->tracking_number);
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
        // 01:30 on Oct 15 in Manila is still Oct 14 in UTC, so office dates must follow Manila time.
        $this->travelTo(Carbon::parse('2026-10-14 17:30:00', 'UTC'));
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
        $this->assertDatabaseCount('document_route_steps', 0);
        $this->assertDatabaseCount('document_movements', 1);
        $this->assertDatabaseCount('document_office_assignments', 1);
        $this->assertDatabaseCount('notifications', 1);

        $this->actingAs($destinationStaff)
            ->get(route('platform.documents.view', $document))
            ->assertNotFound();

        $this->get(route('tracking.index', ['tracking' => $document->tracking_number]))
            ->assertOk()
            ->assertSee('Submit your document to the Records staff.')
            ->assertSee('title="Pending: Waiting for paper copy">Pending</span>', false)
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
            ->assertDontSee('Requested destinations after paper intake')
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
        $this->assertSame('SDO-Records Unit', $document->current_office);
        $this->assertSame(DocumentMovement::STATUS_RECEIVED, $document->status);
        $this->assertSame($recordsStaff->id, $document->registered_by);
        $this->assertSame('2026-10-15', $document->received_at->toDateString());
        $this->assertNull($document->due_at);
        $this->assertDatabaseCount('document_route_steps', 0);
        $this->assertDatabaseCount('document_movements', 2);
        $this->assertDatabaseCount('document_office_assignments', 1);
        $this->assertDatabaseCount('notifications', 1);
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
            ->assertOk();

        $unrelatedStaff = User::factory()->create([
            'office' => 'Finance Unit',
            'permissions' => ['platform.index' => true],
        ]);
        $this->actingAs($unrelatedStaff)
            ->get(route('platform.documents.view', $document))
            ->assertNotFound();

        $this->actingAs($destinationStaff)
            ->get(route('platform.documents.view', $document))
            ->assertNotFound();

        $this->get(route('tracking.index', ['tracking' => $document->tracking_number]))
            ->assertOk()
            ->assertSee('Records Unit received the paper document')
            ->assertDontSee('Submit your document to the Records staff.');
    }

    public function test_records_is_the_only_route_until_paper_is_received(): void
    {
        Storage::fake('local');
        $recordsStaff = User::factory()->create([
            'office' => 'SDO-Records Unit',
            'permissions' => ['platform.index' => true],
        ]);
        $payload = $this->payload();
        unset($payload['attachment']);

        $response = $this->post(route('submission.store'), $payload);
        $response->assertRedirectToRoute('tracking.index', ['tracking' => Document::query()->sole()->tracking_number]);

        $this->assertDatabaseCount('documents', 1);
        $this->assertDatabaseCount('document_office_assignments', 1);
        $this->assertDatabaseCount('document_movements', 1);
        $this->assertDatabaseCount('notifications', 1);
        $this->assertDatabaseCount('document_route_steps', 0);

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

    public function test_records_can_forward_after_receiving_the_paper(): void
    {
        Storage::fake('local');
        $recordsStaff = User::factory()->create([
            'office' => 'SDO-Records Unit',
            'permissions' => ['platform.index' => true],
        ]);
        $payload = $this->payload();
        unset($payload['attachment']);
        $payload['client_remarks'] = 'Please ask Records to send this to the right office.';
        $this->post(route('submission.store'), $payload);
        $document = Document::query()->sole();

        $this->actingAs($recordsStaff)->post(route('platform.documents.movements.store', $document), [
            'workflow' => [
                'action' => 'received',
                'source_office' => 'SDO-Records Unit',
                'remarks' => 'Paper copy accepted.',
            ],
        ])->assertRedirectToRoute('platform.documents.view', $document);

        $this->actingAs($recordsStaff)->post(route('platform.documents.movements.store', $document), [
            'workflow' => [
                'action' => 'forwarded',
                'source_office' => 'SDO-Records Unit',
                'destinations' => ['Administrative Unit'],
                'destination_remarks' => ['Review this.'],
            ],
        ])->assertRedirect();

        $this->assertDatabaseCount('document_office_assignments', 1);
        $this->assertDatabaseHas('document_office_assignments', [
            'document_id' => $document->id,
            'office' => 'Administrative Unit',
            'status' => DocumentMovement::STATUS_FORWARDED,
        ]);
        $this->assertDatabaseHas('document_movements', [
            'document_id' => $document->id,
            'to_office' => 'Administrative Unit',
            'remarks' => 'Review this.',
        ]);

        $this->get(route('tracking.index', ['tracking' => $document->tracking_number]))
            ->assertOk()
            ->assertSee('Review this.')
            ->assertDontSee('Please ask Records to send this to the right office.');
    }

    public function test_records_receipt_does_not_dispatch_unselected_routes(): void
    {
        Storage::fake('local');
        $recordsStaff = User::factory()->create([
            'office' => 'SDO-Records Unit',
            'permissions' => ['platform.index' => true],
        ]);
        $otherStaff = User::factory()->create(['office' => 'Administrative Unit', 'permissions' => ['platform.index' => true]]);
        $payload = $this->payload();
        unset($payload['attachment']);
        $this->post(route('submission.store'), $payload);
        $document = Document::query()->sole();

        $receipt = [
            'workflow' => [
                'action' => 'received',
                'source_office' => 'SDO-Records Unit',
                'remarks' => 'Paper copy accepted.',
            ],
        ];

        $this->actingAs($recordsStaff)
            ->post(route('platform.documents.movements.store', $document), $receipt)
            ->assertRedirectToRoute('platform.documents.view', $document);

        $document->refresh();
        $this->assertSame(DocumentMovement::STATUS_RECEIVED, $document->status);
        $this->assertSame($recordsStaff->id, $document->registered_by);
        $this->assertDatabaseCount('document_route_steps', 0);
        $this->assertDatabaseCount('document_movements', 2);
        $this->assertDatabaseCount('document_office_assignments', 1);
        $this->assertDatabaseCount('notifications', 1);

        $this->actingAs($otherStaff)->get(route('platform.documents.view', $document))->assertNotFound();

        $this->actingAs($recordsStaff)->post(route('platform.documents.movements.store', $document), $receipt)
            ->assertSessionHasErrors('workflow.action');
        $this->assertDatabaseCount('document_movements', 2);
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

    public function test_type_and_client_routing_instructions_are_validated(): void
    {
        Storage::fake('local');
        $payload = $this->payload();
        $payload['type'] = 'Unknown type';
        $payload['route_remarks'] = ['Only one note'];

        $this->from(route('submission.create'))
            ->post(route('submission.store'), $payload)
            ->assertRedirect(route('submission.create'))
            ->assertSessionHasErrors([
                'type' => 'Select a recognized document type.',
                'route_remarks' => 'Give routing instructions to Records staff at the counter.',
            ]);

        $this->assertDatabaseCount('documents', 0);
    }

    public function test_client_can_leave_routing_instructions_for_records_without_choosing_offices(): void
    {
        $payload = $this->payload();
        unset($payload['attachment']);
        $payload['client_remarks'] = 'Please send copies to Budget and Legal for review.';

        $this->post(route('submission.store'), $payload)->assertRedirect();

        $document = Document::query()->sole();
        $this->assertDatabaseHas('document_movements', [
            'document_id' => $document->id,
            'to_office' => 'SDO-Records Unit',
            'remarks' => 'Please send copies to Budget and Legal for review.',
        ]);
        $this->assertDatabaseCount('document_route_steps', 0);

        $records = User::factory()->create(['office' => 'SDO-Records Unit', 'permissions' => ['platform.index' => true]]);
        $this->actingAs($records)
            ->getJson(route('platform.documents.intake', ['tracking' => $document->tracking_number]))
            ->assertOk()
            ->assertJsonPath('document.client_remarks', 'Please send copies to Budget and Legal for review.')
            ->assertJsonPath('document.delivery_method', 'Hand carry')
            ->assertJsonPath('document.routes', []);

        $this->get(route('tracking.index', ['tracking' => $document->tracking_number]))
            ->assertOk()
            ->assertDontSee('Please send copies to Budget and Legal for review.');
    }

    public function test_client_routing_instructions_must_be_short_text(): void
    {
        $payload = $this->payload();
        unset($payload['attachment']);
        $payload['client_remarks'] = ['Budget Unit'];

        $this->post(route('submission.store'), $payload)
            ->assertSessionHasErrors(['client_remarks' => 'Enter your routing instructions as text.']);

        $payload['client_remarks'] = str_repeat('x', 1001);
        $this->post(route('submission.store'), $payload)
            ->assertSessionHasErrors(['client_remarks' => 'Keep routing instructions within 1,000 characters.']);

        $this->assertDatabaseCount('documents', 0);
    }

    public function test_client_can_choose_official_channel_as_delivery_method(): void
    {
        $payload = $this->payload();
        unset($payload['attachment']);
        $payload['delivery_method'] = 'Official channel';

        $this->post(route('submission.store'), $payload)->assertRedirect();

        $this->assertSame('Official channel', Document::query()->sole()->delivery_method);
    }

    public function test_client_delivery_method_rejects_unrecognized_values(): void
    {
        $payload = $this->payload();
        $payload['delivery_method'] = 'Courier';

        $this->post(route('submission.store'), $payload)
            ->assertSessionHasErrors(['delivery_method' => 'Choose Hand carry or Official channel.']);

        $this->assertDatabaseCount('documents', 0);
    }

    public function test_client_must_choose_a_delivery_method(): void
    {
        $payload = $this->payload();
        unset($payload['delivery_method'], $payload['attachment']);

        $this->post(route('submission.store'), $payload)
            ->assertSessionHasErrors(['delivery_method' => 'Choose how the paper copy will reach the Records Unit.']);

        $this->assertDatabaseCount('documents', 0);
    }

    public function test_client_cannot_choose_destination_offices(): void
    {
        $payload = $this->payload();
        unset($payload['attachment']);
        $payload['destinations'] = ['Administrative Unit'];

        $this->from(route('submission.create'))
            ->post(route('submission.store'), $payload)
            ->assertRedirect(route('submission.create'))
            ->assertSessionHasErrors(['destinations' => 'Records staff choose the destination offices after intake.']);

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
                'destinations' => 'Records staff choose the destination offices after intake.',
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
        User::factory()->count(2)->create(['office' => 'SDO-Records Unit']);
        $key = (string) Str::uuid();
        $fail = true;
        $notificationsSent = 0;
        Event::listen(NotificationSent::class, function () use (&$fail, &$notificationsSent): void {
            if ($fail && ++$notificationsSent === 2) {
                throw new \RuntimeException('Notification delivery failed.');
            }
        });
        $payload = $this->payload($key);

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
        $this->assertDatabaseCount('document_movements', 1);
        $this->assertDatabaseCount('document_office_assignments', 1);
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
            'delivery_method' => 'Hand carry',
            'title' => 'Travel request',
            'type' => 'Request',
            'attachment' => UploadedFile::fake()->createWithContent('request.pdf', '%PDF-1.4 sample'),
            'privacy_consent' => '1',
        ];
    }
}
