<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\DocumentMovement;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class PublicReleaseSignatureTest extends TestCase
{
    use LazilyRefreshDatabase;

    private const SIGNATURE = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/lXcAAAAASUVORK5CYII=';

    private function releasedWithoutSignature(): array
    {
        $document = Document::factory()->create(['status' => DocumentMovement::STATUS_RELEASED, 'current_office' => 'SDO-Records Unit']);
        $release = DocumentMovement::factory()->for($document)->create([
            'action' => DocumentMovement::ACTION_RELEASED,
            'from_office' => 'SDO-Records Unit',
            'to_office' => 'SDO-Records Unit',
            'from_status' => DocumentMovement::STATUS_RECEIVED,
            'to_status' => DocumentMovement::STATUS_RELEASED,
            'released_to' => 'Rowena D. Pascua',
            'recipient_signature' => null,
        ]);

        return [$document, $release];
    }

    public function test_the_tracking_page_asks_for_a_signature_when_a_release_was_not_signed(): void
    {
        [$document] = $this->releasedWithoutSignature();

        $this->get(route('tracking.index', ['tracking' => $document->tracking_number]))
            ->assertOk()
            ->assertSee('Sign to confirm you received the document/s')
            ->assertSee('action="'.route('tracking.signature', $document->tracking_number).'"', false)
            ->assertSee('name="recipient_signature"', false);
    }

    public function test_the_client_can_sign_a_release_once_and_the_page_never_shows_it(): void
    {
        [$document, $release] = $this->releasedWithoutSignature();

        $this->post(route('tracking.signature', $document->tracking_number), ['recipient_name' => 'Rowena D. Pascua', 'recipient_signature' => self::SIGNATURE])
            ->assertRedirect(route('tracking.index', ['tracking' => $document->tracking_number]))
            ->assertSessionHas('release_signature_saved');

        $this->assertSame(self::SIGNATURE, $release->fresh()->recipient_signature);
        $this->get(route('tracking.index', ['tracking' => $document->tracking_number]))
            ->assertDontSee('Sign to confirm you received the document/s')
            ->assertDontSee(self::SIGNATURE, false);

        $other = 'data:image/png;base64,'.base64_encode(base64_decode(substr(self::SIGNATURE, 22)));
        $this->post(route('tracking.signature', $document->tracking_number), ['recipient_name' => 'Rowena D. Pascua', 'recipient_signature' => $other])
            ->assertSessionHasErrors('recipient_signature', null, 'releaseSignature');
        $this->assertSame(self::SIGNATURE, $release->fresh()->recipient_signature);
    }

    public function test_signing_adds_a_step_to_the_public_and_staff_timelines(): void
    {
        [$document] = $this->releasedWithoutSignature();

        $this->post(route('tracking.signature', $document->tracking_number), ['recipient_name' => 'Rowena D. Pascua', 'recipient_signature' => self::SIGNATURE])
            ->assertRedirect();

        $this->get(route('tracking.index', ['tracking' => $document->tracking_number]))
            ->assertSee('Rowena D. Pascua signed for the document/s')
            ->assertSee('Signature received')
            ->assertDontSee(self::SIGNATURE, false);

        $records = User::factory()->create(['office' => 'SDO-Records Unit', 'permissions' => ['platform.index' => true]]);
        $this->actingAs($records)
            ->get(route('platform.documents.workspace', $document))
            ->assertSee('Signed for by the recipient')
            ->assertSee('<dt>Signed by</dt><dd>Rowena D. Pascua</dd>', false)
            ->assertSee('<dt>Signed on</dt><dd>Fast Track page</dd>', false);
    }

    public function test_the_name_the_client_types_becomes_the_released_to_name(): void
    {
        [$document, $release] = $this->releasedWithoutSignature();

        $this->get(route('tracking.index', ['tracking' => $document->tracking_number]))
            ->assertSee('name="recipient_name" value="Rowena D. Pascua"', false);

        $this->post(route('tracking.signature', $document->tracking_number), ['recipient_name' => '  Rowena Dela Cruz Pascua ', 'recipient_signature' => self::SIGNATURE])
            ->assertSessionHasNoErrors();

        $this->assertSame('Rowena Dela Cruz Pascua', $release->fresh()->released_to);
        $this->get(route('tracking.index', ['tracking' => $document->tracking_number]))
            ->assertSee('Rowena Dela Cruz Pascua signed for the document/s');
        $records = User::factory()->create(['office' => 'SDO-Records Unit', 'permissions' => ['platform.index' => true]]);
        $this->actingAs($records)->get(route('platform.documents.workspace', $document))
            ->assertSee('<dt>Released to</dt><dd>Rowena Dela Cruz Pascua</dd>', false);
    }

    public function test_signing_needs_the_clients_name(): void
    {
        [$document, $release] = $this->releasedWithoutSignature();

        $this->post(route('tracking.signature', $document->tracking_number), ['recipient_name' => '   ', 'recipient_signature' => self::SIGNATURE])
            ->assertSessionHasErrors('recipient_name', null, 'releaseSignature');
        $this->assertNull($release->fresh()->recipient_signature);
        $this->assertSame('Rowena D. Pascua', $release->fresh()->released_to);
    }

    public function test_a_document_that_is_not_released_cannot_be_signed(): void
    {
        $document = Document::factory()->received()->create();
        DocumentMovement::factory()->for($document)->create(['action' => DocumentMovement::ACTION_RELEASED]);

        $this->post(route('tracking.signature', $document->tracking_number), ['recipient_name' => 'Rowena D. Pascua', 'recipient_signature' => self::SIGNATURE])
            ->assertSessionHasErrors('recipient_signature', null, 'releaseSignature');
        $this->assertNull($document->movements()->first()->recipient_signature);
        $this->get(route('tracking.index', ['tracking' => $document->tracking_number]))
            ->assertDontSee('Sign to confirm you received the document/s');
    }

    public function test_a_restored_release_can_no_longer_be_signed(): void
    {
        [$document, $release] = $this->releasedWithoutSignature();
        DocumentMovement::factory()->for($document)->create(['action' => DocumentMovement::ACTION_RESTORED]);

        $this->post(route('tracking.signature', $document->tracking_number), ['recipient_name' => 'Rowena D. Pascua', 'recipient_signature' => self::SIGNATURE])
            ->assertSessionHasErrors('recipient_signature', null, 'releaseSignature');
        $this->assertNull($release->fresh()->recipient_signature);
    }

    public function test_the_signature_must_be_a_drawn_png(): void
    {
        [$document, $release] = $this->releasedWithoutSignature();

        $this->post(route('tracking.signature', $document->tracking_number), ['recipient_name' => 'Rowena D. Pascua', 'recipient_signature' => 'data:image/gif;base64,R0lGODlhAQABAAAAACw='])
            ->assertSessionHasErrors('recipient_signature', null, 'releaseSignature');
        $this->post(route('tracking.signature', $document->tracking_number), [])
            ->assertSessionHasErrors('recipient_signature', null, 'releaseSignature');
        $this->assertNull($release->fresh()->recipient_signature);
    }

    public function test_staff_history_says_when_a_release_is_not_signed_yet(): void
    {
        [$document] = $this->releasedWithoutSignature();
        $records = User::factory()->create(['office' => 'SDO-Records Unit', 'permissions' => ['platform.index' => true]]);

        $this->actingAs($records)
            ->get(route('platform.documents.workspace', $document))
            ->assertSee('Not signed yet. The client can sign on the Fast Track page.');
    }

    public function test_records_releases_without_a_signature_and_the_client_signs_later(): void
    {
        $records = User::factory()->create(['office' => 'SDO-Records Unit', 'permissions' => ['platform.index' => true]]);
        $document = Document::factory()->incoming()->create(['current_office' => 'SDO-Records Unit']);
        $url = route('platform.documents.movements.store', $document);

        $this->actingAs($records)->postJson($url, ['workflow' => ['action' => 'received', 'source_office' => 'SDO-Records Unit']])->assertOk();
        $this->postJson($url, ['workflow' => ['action' => 'released', 'source_office' => 'SDO-Records Unit', 'released_to' => 'Juan Dela Cruz']])->assertOk();

        $this->assertSame(DocumentMovement::STATUS_RELEASED, $document->fresh()->status);
        $this->get(route('platform.documents.workspace', $document))
            ->assertDontSee('<dts-signature-pad', false)
            ->assertSee('Not signed yet. The client can sign on the Fast Track page.');
    }
}
