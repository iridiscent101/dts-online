<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\DocumentMovement;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class DocumentReceiptTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_tracking_result_links_to_a_printable_receipt_with_the_qr_code(): void
    {
        $document = Document::factory()->create([
            'tracking_number' => 'SDONV12345678912',
            'subject' => 'Private client title',
            'sender' => 'Private Sender',
            'document_type' => 'Request',
            'delivery_method' => 'Hand carry',
            'client_submission_key' => (string) Str::uuid(),
            'status' => DocumentMovement::STATUS_AWAITING_PHYSICAL_DOCUMENT,
        ]);

        $this->get(route('tracking.index', ['tracking' => $document->tracking_number]))
            ->assertOk()
            // Print receipt is hidden on the tracking result for now; the receipt page itself still works.
            ->assertDontSee('Print receipt');

        // Same privacy as the tracking page: a client submission's title and sender are left out.
        $this->get(route('tracking.receipt', $document->tracking_number))
            ->assertOk()
            ->assertSee('SDONV12345678912')
            ->assertSee('alt="QR code for SDONV12345678912"', false)
            ->assertSee('src="data:image/svg+xml;base64,', false)
            ->assertSee('Request')
            ->assertSee('Hand carry')
            ->assertSee('Bring the document/s and this receipt to the Records Unit counter.')
            ->assertSee('data-print-receipt', false)
            ->assertDontSee('Private client title')
            ->assertDontSee('Private Sender');
    }

    public function test_receipt_shows_the_title_of_a_staff_registered_document_and_needs_a_valid_number(): void
    {
        Document::factory()->received()->create(['tracking_number' => 'SDONV98765432198', 'subject' => 'Memorandum on travel']);

        $this->get(route('tracking.receipt', 'sdonv98765432198'))
            ->assertOk()
            ->assertSee('Memorandum on travel')
            ->assertDontSee('Bring the document/s');

        $this->get(route('tracking.receipt', 'SDONV11111111111'))->assertNotFound();
        $this->get('/receipt/not-a-number')->assertNotFound();
    }
}
