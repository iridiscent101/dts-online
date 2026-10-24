<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\DocumentMovement;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class ClientEmailEditingTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_staff_see_the_sender_email_and_can_fix_a_typo_without_unlocking(): void
    {
        $staff = $this->recordsStaff();
        $document = $this->processedDocument('typo@gmial.com');

        $this->actingAs($staff)->get(route('platform.documents.workspace', $document))
            ->assertOk()->assertSeeInOrder(['Sender email', 'typo@gmial.com']);
        $this->get(route('platform.documents.workspace', $document))
            ->assertOk()->assertSee('typo@gmial.com')->assertSee('name="document[client_email]"', false);

        // The details are locked (an office received it), yet the email needs no reason.
        $this->post(route('platform.documents.edit', $document).'/save', ['document' => [
            ...$this->unchangedDetails($document),
            'client_email' => ' Juan@Gmail.com ',
        ]])->assertSessionHasNoErrors();

        $this->assertSame('juan@gmail.com', $document->fresh()->client_email);
        $change = $document->activities()->where('description', 'Document details updated')->sole();
        $this->assertSame(['client_email'], $change->properties['changed_fields']);
        $this->assertSame(['client_email' => 'typo@gmial.com'], $change->properties['before']);
        $this->get(route('platform.documents.workspace', $document))
            ->assertSeeInOrder(['Sender email', 'typo@gmial.com', 'juan@gmail.com']);
    }

    public function test_other_locked_details_still_need_a_reason_when_saved_with_the_email(): void
    {
        $this->actingAs($this->recordsStaff());
        $document = $this->processedDocument('client@gmail.com');

        $this->post(route('platform.documents.edit', $document).'/save', ['document' => [
            ...$this->unchangedDetails($document),
            'sender' => 'Someone else',
            'client_email' => 'other@gmail.com',
        ]])->assertSessionHasErrors('document.correction_reason');

        $this->assertSame('client@gmail.com', $document->fresh()->client_email);
    }

    public function test_an_invalid_email_is_refused_and_a_form_without_the_field_keeps_it(): void
    {
        $this->actingAs($this->recordsStaff());
        $document = $this->processedDocument('client@gmail.com');

        $this->post(route('platform.documents.edit', $document).'/save', ['document' => [
            ...$this->unchangedDetails($document),
            'client_email' => 'not-an-email',
        ]])->assertSessionHasErrors('document.client_email');

        $this->post(route('platform.documents.edit', $document).'/save', ['document' => $this->unchangedDetails($document)])
            ->assertSessionHasNoErrors();
        $this->assertSame('client@gmail.com', $document->fresh()->client_email);

        // Clearing it stops the emails.
        $this->post(route('platform.documents.edit', $document->fresh()).'/save', ['document' => [
            ...$this->unchangedDetails($document->fresh()),
            'client_email' => '',
        ]])->assertSessionHasNoErrors();
        $this->assertNull($document->fresh()->client_email);
    }

    public function test_the_public_tracking_page_never_shows_the_email(): void
    {
        $document = $this->processedDocument('private@gmail.com');

        $this->get(route('tracking.index', ['tracking' => $document->tracking_number]))
            ->assertOk()->assertDontSee('private@gmail.com');
    }

    private function recordsStaff(): User
    {
        return User::factory()->create([
            'office' => 'Records Unit',
            'permissions' => ['platform.index' => true, 'platform.documents.edit' => true],
        ]);
    }

    private function processedDocument(string $email): Document
    {
        $document = Document::factory()->received()->create([
            'current_office' => 'Records Unit',
            'client_email' => $email,
            'received_at' => '2026-08-03',
        ]);
        $document->movements()->create([
            'action' => DocumentMovement::ACTION_RECEIVED,
            'from_office' => 'Records Unit',
            'to_office' => 'Records Unit',
            'from_status' => DocumentMovement::STATUS_AWAITING_RECEIPT,
            'to_status' => DocumentMovement::STATUS_RECEIVED,
        ]);

        return $document;
    }

    /**
     * @return array<string, mixed>
     */
    private function unchangedDetails(Document $document): array
    {
        return [
            'details_fingerprint' => $document->detailsFingerprint(),
            'title' => $document->subject,
            'type' => $document->document_type,
            'origin' => $document->origin,
            'sender' => $document->sender,
            'received' => $document->received_at->toDateString(),
        ];
    }
}
