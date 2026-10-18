<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\DocumentMovement;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class PublicReleaseSignatureTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_the_public_tracking_page_does_not_offer_signing_for_an_older_unsigned_release(): void
    {
        $document = Document::factory()->create([
            'status' => DocumentMovement::STATUS_RELEASED,
            'current_office' => 'SDO-Records Unit',
        ]);
        DocumentMovement::factory()->for($document)->create([
            'action' => DocumentMovement::ACTION_RELEASED,
            'from_office' => 'SDO-Records Unit',
            'to_office' => 'SDO-Records Unit',
            'released_to' => 'Rowena D. Pascua',
            'recipient_signature' => null,
        ]);

        $this->get(route('tracking.index', ['tracking' => $document->tracking_number]))
            ->assertOk()
            ->assertDontSee('Sign to confirm you received the document/s')
            ->assertDontSee('name="recipient_signature"', false);

        $this->post('/tracking/'.$document->tracking_number.'/signature', [
            'recipient_name' => 'Someone Else',
            'recipient_signature' => 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/lXcAAAAASUVORK5CYII=',
        ])->assertNotFound();

        $this->assertNull($document->movements()->firstOrFail()->recipient_signature);
        $this->assertSame('Rowena D. Pascua', $document->movements()->firstOrFail()->released_to);
    }
}
