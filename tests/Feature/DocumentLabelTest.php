<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\User;
use App\Services\DocumentTrackingLabel;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
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
            ->assertSee('70mm 30mm')
            ->assertSee('data:image/svg+xml;base64,', false);

        preg_match('/src="data:image\/svg\+xml;base64,([^"]+)"/', $response->getContent(), $matches);
        $svg = base64_decode($matches[1] ?? '', true);
        $this->assertIsString($svg);
        $this->assertStringContainsString('<svg', $svg);
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
            ->assertSee('70mm 30mm')
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
