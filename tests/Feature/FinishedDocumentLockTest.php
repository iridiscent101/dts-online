<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\DocumentMovement;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Orchid\Platform\Models\Role;
use Tests\TestCase;

/**
 * Once an office has processed a document, its details are locked: changing them needs a reason, which is kept in
 * the history. Completed, released and archived documents are closed records that only an administrator can unlock.
 */
class FinishedDocumentLockTest extends TestCase
{
    use LazilyRefreshDatabase;

    private const REASON_REQUIRED = 'The details of this document are locked. Unlock them and give a reason for the correction.';

    public function test_office_staff_cannot_edit_a_finished_document(): void
    {
        $staff = User::factory()->create([
            'office' => 'Records Unit',
            'permissions' => ['platform.index' => true, 'platform.documents.edit' => true],
        ]);
        $document = $this->document(DocumentMovement::STATUS_COMPLETED);

        $this->actingAs($staff)
            ->get(route('platform.documents.workspace', $document))
            ->assertOk()
            ->assertSee('so its details are locked.')
            ->assertDontSee('data-workspace-edit', false)
            ->assertDontSee('give a reason');

        $this->get(route('platform.documents.edit', $document))->assertForbidden();
        $this->post(route('platform.documents.edit', $document).'/save', ['document' => $this->payload($document)])
            ->assertForbidden();
    }

    public function test_an_administrator_must_give_a_reason_to_correct_a_finished_document(): void
    {
        $admin = User::factory()->create(['office' => 'Records Unit']);
        $admin->replaceRoles([Role::create(['name' => 'Admin', 'slug' => User::ADMIN_ROLE_SLUG])->id]);
        $document = $this->document(DocumentMovement::STATUS_ARCHIVED);

        $this->actingAs($admin)
            ->get(route('platform.documents.workspace', $document))
            ->assertOk()
            ->assertSee('Unlock to edit')
            ->assertSee('data-workspace-unlock', false)
            ->assertSee('name="document[correction_reason]"', false);
        $this->get(route('platform.documents.edit', $document))
            ->assertOk()
            ->assertSee('Unlock details')
            ->assertSee('readonly', false);

        $this->post(route('platform.documents.edit', $document).'/save', ['document' => $this->payload($document)])
            ->assertSessionHasErrors(['document.correction_reason' => self::REASON_REQUIRED]);
        $this->assertSame('Original sender', $document->fresh()->sender);

        $this->post(route('platform.documents.edit', $document).'/save', ['document' => [
            ...$this->payload($document),
            'correction_reason' => 'Sender name was misspelled at registration',
        ]])->assertSessionHasNoErrors();

        $this->assertSame('Corrected sender', $document->fresh()->sender);
        $this->assertSame(
            'Sender name was misspelled at registration',
            $document->activities()->where('description', 'Document details updated')->latest('id')->first()->properties['correction_reason'],
        );
    }

    public function test_released_document_is_closed_to_staff_and_requires_an_admin_reason_for_corrections(): void
    {
        $staff = User::factory()->create([
            'office' => 'Records Unit',
            'permissions' => ['platform.index' => true, 'platform.documents.edit' => true],
        ]);
        $document = $this->document(DocumentMovement::STATUS_RELEASED);

        $this->actingAs($staff)
            ->get(route('platform.documents.edit', $document))
            ->assertForbidden();
        $this->post(route('platform.documents.edit', $document).'/save', ['document' => $this->payload($document)])
            ->assertForbidden();

        $admin = User::factory()->create(['office' => 'Records Unit']);
        $admin->replaceRoles([Role::create(['name' => 'Admin', 'slug' => User::ADMIN_ROLE_SLUG])->id]);
        $this->actingAs($admin)
            ->post(route('platform.documents.edit', $document).'/save', ['document' => $this->payload($document)])
            ->assertSessionHasErrors(['document.correction_reason' => self::REASON_REQUIRED]);

        $this->post(route('platform.documents.edit', $document).'/save', ['document' => [
            ...$this->payload($document),
            'correction_reason' => 'Corrected after paper release',
        ]])->assertSessionHasNoErrors();

        $this->assertSame(DocumentMovement::STATUS_RELEASED, $document->fresh()->status);
        $this->assertSame('Corrected sender', $document->fresh()->sender);
    }

    public function test_staff_must_give_a_reason_to_change_a_processed_documents_details(): void
    {
        $staff = User::factory()->create([
            'office' => 'Records Unit',
            'permissions' => ['platform.index' => true, 'platform.documents.edit' => true],
        ]);
        $document = $this->document(DocumentMovement::STATUS_RECEIVED);
        $this->receive($document, $staff);

        $this->actingAs($staff)
            ->get(route('platform.documents.workspace', $document))
            ->assertOk()
            ->assertSee('Edit document')
            // The Unlock prompt explains the lock, so there is no separate note.
            ->assertDontSee('so its details are locked.')
            ->assertSee('data-unlock-details', false)
            ->assertSee('data-locked-fields', false)
            ->assertDontSee('Unlock to edit');

        $this->post(route('platform.documents.edit', $document).'/save', ['document' => $this->payload($document)])
            ->assertSessionHasErrors(['document.correction_reason' => self::REASON_REQUIRED]);
        $this->assertSame('Original sender', $document->fresh()->sender);

        $this->post(route('platform.documents.edit', $document).'/save', ['document' => [
            ...$this->payload($document),
            'correction_reason' => 'Wrong sender typed at registration',
        ]])->assertSessionHasNoErrors();

        $this->assertSame('Corrected sender', $document->fresh()->sender);
        $activity = $document->activities()->where('description', 'Document details updated')->latest('id')->first();
        $this->assertSame('Wrong sender typed at registration', $activity->properties['correction_reason']);
        $this->assertSame(['sender' => 'Original sender'], $activity->properties['before']);

        // The reason and the change are shown in the history, behind a "View reason and changes" dropdown.
        $this->get(route('platform.documents.workspace', $document))
            ->assertOk()
            ->assertSee('View reason and changes')
            ->assertSee('Wrong sender typed at registration')
            ->assertSeeInOrder(['Sender', 'Original sender', 'Corrected sender']);
        $this->get(route('platform.documents.view', $document))
            ->assertOk()
            ->assertSee('Wrong sender typed at registration');
    }

    public function test_saving_a_processed_document_without_changing_its_details_needs_no_reason(): void
    {
        $staff = User::factory()->create([
            'office' => 'Records Unit',
            'permissions' => ['platform.index' => true, 'platform.documents.edit' => true],
        ]);
        $document = $this->document(DocumentMovement::STATUS_FORWARDED);
        $this->receive($document, $staff);

        $this->actingAs($staff)
            ->post(route('platform.documents.edit', $document).'/save', ['document' => [
                ...$this->payload($document),
                'sender' => 'Original sender',
            ]])
            ->assertSessionHasNoErrors();
    }

    public function test_documents_not_yet_processed_are_not_locked(): void
    {
        $staff = User::factory()->create([
            'office' => 'Records Unit',
            'permissions' => ['platform.index' => true, 'platform.documents.edit' => true],
        ]);
        $document = $this->document(DocumentMovement::STATUS_RECEIVED);

        $this->actingAs($staff)
            ->get(route('platform.documents.workspace', $document))
            ->assertOk()
            ->assertSee('Edit document')
            ->assertDontSee('so its details are locked.')
            ->assertDontSee('Unlock to edit');
    }

    private function receive(Document $document, User $user): void
    {
        $document->movements()->create([
            'action' => DocumentMovement::ACTION_RECEIVED,
            'from_office' => 'Records Unit',
            'to_office' => 'Records Unit',
            'from_status' => DocumentMovement::STATUS_AWAITING_RECEIPT,
            'to_status' => DocumentMovement::STATUS_RECEIVED,
            'acted_by' => $user->id,
        ]);
    }

    private function document(string $status): Document
    {
        return Document::factory()->received()->create([
            'current_office' => 'Records Unit',
            'status' => $status,
            'sender' => 'Original sender',
            // Plain dates, as the date columns store them, so an unchanged form doesn't count as a change.
            'received_at' => '2026-08-03',
        ]);
    }

    private function payload(Document $document): array
    {
        return [
            'details_fingerprint' => $document->detailsFingerprint(),
            'title' => $document->subject,
            'type' => $document->document_type,
            'origin' => $document->origin,
            'sender' => 'Corrected sender',
            'received' => $document->received_at->toDateString(),
            'priority' => $document->priority,
        ];
    }
}
