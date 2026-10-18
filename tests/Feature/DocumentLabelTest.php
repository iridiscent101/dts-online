<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\DocumentMovement;
use App\Models\User;
use App\Services\DocumentTrackingLabel;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class DocumentLabelTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_staff_can_open_and_print_a_label_for_their_document(): void
    {
        $user = User::factory()->create([
            'office' => 'Records Unit',
            'permissions' => ['platform.index' => true],
        ]);
        $document = Document::factory()->create([
            'tracking_number' => 'DTS-2026-000123',
            'current_office' => 'Records Unit',
            'external_reference' => 'RO-2026-104',
            'description' => 'Retention guidance for division records.',
        ]);

        $this->actingAs($user)
            ->get(route('platform.documents.view', $document))
            ->assertOk()
            ->assertSee('Next action')
            ->assertSee('Tracking label')
            ->assertSee('Assigned offices')
            ->assertSee('More details')
            ->assertSee('RO-2026-104')
            ->assertSee('Retention guidance for division records.')
            ->assertDontSee('Add routing update')
            ->assertDontSee('Primary office')
            ->assertDontSee('document-attachments-heading', false)
            ->assertSee(route('platform.documents.label', $document), false);

        $response = $this->get(route('platform.documents.label', $document));

        $response->assertOk()
            ->assertSee('Print label')
            ->assertSee('DTS-2026-000123')
            ->assertSee('60mm 30mm')
            ->assertSee('data:image/svg+xml;base64,', false);

        preg_match('/src="data:image\/svg\+xml;base64,([^"]+)"/', $response->getContent(), $matches);
        $svg = base64_decode($matches[1] ?? '', true);
        $this->assertIsString($svg);
        $this->assertStringContainsString('<svg', $svg);
    }

    public function test_label_shows_the_latest_action_in_manila_time(): void
    {
        $user = User::factory()->create([
            'office' => 'Records Unit',
            'permissions' => ['platform.index' => true],
        ]);
        $document = Document::factory()->create([
            'tracking_number' => 'DTS-2026-LATEST',
            'current_office' => 'Records Unit',
            'status' => 'Forwarded',
        ]);

        $this->travelTo(Carbon::parse('2026-10-14 01:00:00', 'UTC'));
        DocumentMovement::factory()->for($document)->for(User::factory()->create(['name' => 'Earlier Staff']), 'actor')->create();
        // 17:30 UTC on Oct 14 is 1:30 AM on Oct 15 in Manila.
        $this->travelTo(Carbon::parse('2026-10-14 17:30:00', 'UTC'));
        DocumentMovement::factory()->for($document)->for(User::factory()->create(['name' => 'Maria Santos']), 'actor')->create();

        $this->actingAs($user)
            ->get(route('platform.documents.label', $document))
            ->assertOk()
            ->assertSeeInOrder(['Status', 'Forwarded', 'Date', 'Oct 15, 2026', 'Time', '1:30 AM', 'By', 'Maria Santos', 'DTS-2026-LATEST'])
            ->assertDontSee('Earlier Staff');

        $this->get(route('platform.documents.labels', ['ids' => [$document->id]]))
            ->assertOk()
            ->assertSee('Maria Santos');
    }

    public function test_label_keeps_the_records_receiver_name_after_later_movements(): void
    {
        $staff = User::factory()->create([
            'office' => 'SDO-Records Unit',
            'permissions' => ['platform.index' => true],
        ]);
        $receiver = User::factory()->create(['name' => 'Rosa Reyes']);
        $forwarder = User::factory()->create(['name' => 'Other Staff']);
        $document = Document::factory()->create([
            'current_office' => 'SDO-Records Unit',
            'status' => DocumentMovement::STATUS_FORWARDED,
        ]);
        DocumentMovement::factory()->for($document)->for($receiver, 'actor')->create([
            'action' => DocumentMovement::ACTION_RECEIVED,
            'from_office' => null,
            'to_office' => 'SDO-Records Unit',
            'from_status' => DocumentMovement::STATUS_AWAITING_PHYSICAL_DOCUMENT,
            'to_status' => DocumentMovement::STATUS_RECEIVED,
        ]);
        DocumentMovement::factory()->for($document)->for($forwarder, 'actor')->create([
            'action' => DocumentMovement::ACTION_FORWARD,
            'from_office' => 'SDO-Records Unit',
        ]);

        $this->actingAs($staff)
            ->get(route('platform.documents.label', $document))
            ->assertSee('<dt>Received by</dt><dd>Rosa Reyes</dd>', false)
            ->assertDontSee('Other Staff');

        $this->get(route('platform.documents.labels', ['ids' => [$document->id]]))
            ->assertSee('<dt>Received by</dt><dd>Rosa Reyes</dd>', false);
    }

    public function test_label_falls_back_to_the_registration_before_any_action(): void
    {
        $this->travelTo(Carbon::parse('2026-10-05 01:15:00', 'UTC'));
        $registrar = User::factory()->create(['name' => 'Rosa Reyes']);
        $document = Document::factory()->create(['registered_by' => $registrar->id]);

        $details = app(DocumentTrackingLabel::class)->details($document);

        $this->assertSame('Oct 5, 2026', $details['date']);
        $this->assertSame('9:15 AM', $details['time']);
        $this->assertSame('Rosa Reyes', $details['processedBy']);
        $this->assertNull($details['releasedBy']);
    }

    public function test_after_a_release_the_label_names_who_received_and_who_released_it(): void
    {
        $clerk = User::factory()->create(['name' => 'Maricar Valido']);
        $document = Document::factory()->received()->create();
        DocumentMovement::factory()->for($document)->for($clerk, 'actor')->create([
            'action' => DocumentMovement::ACTION_RELEASED,
            'released_to' => 'Juan Dela Cruz',
        ]);

        $details = app(DocumentTrackingLabel::class)->details($document);
        $this->assertSame('Received by', $details['byLabel']);
        $this->assertSame('Juan Dela Cruz', $details['processedBy']);
        $this->assertSame('Maricar Valido', $details['releasedBy']);
        $this->assertArrayNotHasKey('recipientSignatureDataUri', $details);

        DocumentMovement::factory()->for($document)->create(['action' => DocumentMovement::ACTION_RESTORED]);
        $document->unsetRelation('latestMovement');

        $afterRestore = app(DocumentTrackingLabel::class)->details($document);
        $this->assertNotSame('Juan Dela Cruz', $afterRestore['processedBy']);
        $this->assertNull($afterRestore['releasedBy']);
    }

    public function test_a_release_without_the_recipients_name_shows_only_who_released_it(): void
    {
        $clerk = User::factory()->create(['name' => 'Maricar Valido']);
        $document = Document::factory()->received()->create();
        DocumentMovement::factory()->for($document)->for($clerk, 'actor')->create([
            'action' => DocumentMovement::ACTION_RELEASED,
            'released_to' => null,
        ]);

        $details = app(DocumentTrackingLabel::class)->details($document);
        $this->assertSame('Released by', $details['byLabel']);
        $this->assertSame('Maricar Valido', $details['processedBy']);
        $this->assertNull($details['releasedBy']);
    }

    public function test_label_shows_processing_staff_name_without_a_signature(): void
    {
        $staff = User::factory()->create([
            'name' => 'Rosa Reyes',
            'office' => 'Records Unit',
            'permissions' => ['platform.index' => true],
        ]);
        $document = Document::factory()->received()->create(['current_office' => 'Records Unit']);
        DocumentMovement::factory()->for($document)->for($staff, 'actor')->create();

        $this->actingAs($staff)
            ->get(route('platform.documents.label', $document))
            ->assertSee('<dt>By</dt><dd>Rosa Reyes</dd>', false)
            ->assertDontSee('<dt>Released by</dt>', false)
            ->assertDontSee('<dt>Sign</dt>', false)
            ->assertDontSee('data:image/png;base64,', false);

    }

    public function test_label_never_prints_the_recipient_signature(): void
    {
        $staff = User::factory()->create([
            'name' => 'Rosa Reyes',
            'office' => 'Records Unit',
            'permissions' => ['platform.index' => true],
        ]);
        $document = Document::factory()->received()->create(['current_office' => 'Records Unit']);
        $signature = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/lXcAAAAASUVORK5CYII=';
        DocumentMovement::factory()->for($document)->for($staff, 'actor')->create([
            'action' => DocumentMovement::ACTION_RELEASED,
            'released_to' => 'Juan Dela Cruz',
            'recipient_signature' => $signature,
        ]);

        // The signature is shown on the history only; the label names who received and who released the document.
        $this->actingAs($staff)
            ->get(route('platform.documents.label', $document))
            ->assertOk()
            ->assertSee('<dt>Received by</dt><dd>Juan Dela Cruz</dd>', false)
            ->assertSee('<dt>Released by</dt><dd>Rosa Reyes</dd>', false)
            ->assertDontSee($signature, false)
            ->assertDontSee("Recipient's signature", false);
    }

    public function test_fast_track_result_links_to_a_public_label_without_the_signature(): void
    {
        $staff = User::factory()->create(['name' => 'Rosa Reyes', 'office' => 'Records Unit']);
        $document = Document::factory()->received()->create([
            'tracking_number' => 'SDONV12345678912',
            'current_office' => 'Records Unit',
        ]);
        $signature = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/lXcAAAAASUVORK5CYII=';
        DocumentMovement::factory()->for($document)->for($staff, 'actor')->create([
            'action' => DocumentMovement::ACTION_RELEASED,
            'released_to' => 'Juan Dela Cruz',
            'recipient_signature' => $signature,
        ]);

        $this->get(route('tracking.index', ['tracking' => $document->tracking_number]))
            ->assertOk()
            ->assertSee('Print label')
            ->assertSee(route('tracking.label', 'SDONV12345678912'), false);

        // No sign-in needed; the same label staff print, without the signature.
        $this->get(route('tracking.label', 'sdonv12345678912'))
            ->assertOk()
            ->assertSee('class="dts-tracking-label"', false)
            ->assertSee('alt="QR code for SDONV12345678912"', false)
            ->assertSee('<dt>Received by</dt><dd>Juan Dela Cruz</dd>', false)
            ->assertSee('Back to tracking')
            ->assertDontSee($signature, false);

        $this->get(route('tracking.label', 'SDONV11111111111'))->assertNotFound();
        $this->get('/label/not-a-number')->assertNotFound();
    }

    public function test_tracking_qr_code_carries_the_sdo_seal(): void
    {
        $document = Document::factory()->create(['tracking_number' => 'DTS-2026-SEAL']);

        $uri = app(DocumentTrackingLabel::class)->dataUri($document);
        $svg = base64_decode(substr($uri, strlen('data:image/svg+xml;base64,')));

        $this->assertStringStartsWith('data:image/svg+xml;base64,', $uri);
        $this->assertStringContainsString('<circle', $svg);
        $this->assertStringContainsString('href="data:image/jpeg;base64,', $svg);
        // The seal file is prepared small (about 9 KB), so a sheet of labels stays light.
        $this->assertLessThan(35_000, strlen($uri));
    }

    public function test_staff_cannot_open_a_label_for_another_office(): void
    {
        $user = User::factory()->create([
            'office' => 'Records Unit',
            'permissions' => ['platform.index' => true],
        ]);
        $document = Document::factory()->create(['current_office' => 'Administrative Unit']);

        $this->actingAs($user)
            ->get(route('platform.documents.label', $document))
            ->assertNotFound();
    }

    public function test_staff_can_print_selected_labels_in_selection_order(): void
    {
        $user = User::factory()->create([
            'office' => 'Records Unit',
            'permissions' => ['platform.index' => true],
        ]);
        $firstDocument = Document::factory()->create([
            'tracking_number' => 'DTS-2026-FIRST',
            'current_office' => 'Records Unit',
        ]);
        $secondDocument = Document::factory()->create([
            'tracking_number' => 'DTS-2026-SECOND',
            'current_office' => 'Records Unit',
        ]);

        $this->actingAs($user)
            ->get(route('platform.documents'))
            ->assertSee(route('platform.documents.labels'), false);

        $response = $this->get(route('platform.documents.labels', [
            'ids' => [$secondDocument->id, $firstDocument->id],
        ]));

        $response->assertOk()
            ->assertSee('Print labels')
            ->assertSee('60mm 30mm')
            ->assertSeeInOrder(['DTS-2026-SECOND', 'DTS-2026-FIRST']);
        $this->assertSame(2, substr_count($response->getContent(), 'class="dts-tracking-label"'));
    }

    public function test_selected_labels_do_not_reveal_documents_from_another_office(): void
    {
        $user = User::factory()->create([
            'office' => 'Records Unit',
            'permissions' => ['platform.index' => true],
        ]);
        $visibleDocument = Document::factory()->create(['current_office' => 'Records Unit']);
        $hiddenDocument = Document::factory()->create(['current_office' => 'Administrative Unit']);

        $this->actingAs($user)
            ->get(route('platform.documents.labels', [
                'ids' => [$visibleDocument->id, $hiddenDocument->id],
            ]))
            ->assertNotFound();
    }

    public function test_selected_labels_require_a_valid_bounded_selection(): void
    {
        $user = User::factory()->create([
            'office' => 'Records Unit',
            'permissions' => ['platform.index' => true],
        ]);

        $this->actingAs($user)
            ->get(route('platform.documents.labels'))
            ->assertSessionHasErrors('ids');

        $this->get(route('platform.documents.labels', ['ids' => range(1, 101)]))
            ->assertSessionHasErrors('ids');
    }

    public function test_guests_are_sent_to_sign_in_before_printing_a_label(): void
    {
        $document = Document::factory()->create();

        $this->get(route('platform.documents.label', $document))
            ->assertRedirectToRoute('platform.login');

        $this->get(route('platform.documents.labels', ['ids' => [$document->id]]))
            ->assertRedirectToRoute('platform.login');
    }
}
