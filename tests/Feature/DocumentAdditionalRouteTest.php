<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\DocumentMovement;
use App\Models\User;
use App\Notifications\DocumentAssignedNotification;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class DocumentAdditionalRouteTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_sending_office_can_add_a_missed_sibling_without_changing_existing_branches(): void
    {
        Notification::fake();
        $sender = User::factory()->create(['office' => 'Administrative Unit', 'permissions' => ['platform.index' => true]]);
        $recipient = User::factory()->create(['office' => 'SDO-Legal Unit']);
        $document = $this->forwardedDocument();
        $document->officeAssignments()->create(['office' => 'Curriculum Implementation Division', 'status' => DocumentMovement::STATUS_RECEIVED]);

        $this->actingAs($sender)
            ->postJson(route('platform.documents.additional-routes.store', $document), [
                'source_office' => 'Administrative Unit',
                'destination_office' => 'SDO-Legal Unit',
                'remarks' => 'Additional copy for Legal review.',
                'dispatch_confirmed' => true,
            ])
            ->assertOk()
            ->assertJsonPath('message', "{$document->tracking_number} was also forwarded to SDO-Legal Unit.");

        $this->assertDatabaseHas('document_office_assignments', [
            'document_id' => $document->id, 'office' => 'SDO-Legal Unit', 'status' => DocumentMovement::STATUS_FORWARDED,
        ]);
        $this->assertDatabaseHas('document_office_assignments', [
            'document_id' => $document->id, 'office' => 'SDO-Budget Unit', 'status' => DocumentMovement::STATUS_FORWARDED,
        ]);
        $this->assertDatabaseHas('document_office_assignments', [
            'document_id' => $document->id, 'office' => 'Curriculum Implementation Division', 'status' => DocumentMovement::STATUS_RECEIVED,
        ]);
        $this->assertDatabaseHas('document_movements', [
            'document_id' => $document->id,
            'action' => DocumentMovement::ACTION_FORWARDED,
            'from_office' => 'Administrative Unit',
            'to_office' => 'SDO-Legal Unit',
            'remarks' => 'Additional copy for Legal review.',
            'acted_by' => $sender->id,
        ]);
        $this->assertSame('SDO-Budget Unit', $document->fresh()->current_office);
        $this->assertSame(DocumentMovement::STATUS_FORWARDED, $document->status);
        Notification::assertSentTo($recipient, DocumentAssignedNotification::class);
    }

    public function test_additional_route_requires_the_sending_office(): void
    {
        $recipientStaff = User::factory()->create(['office' => 'SDO-Budget Unit', 'permissions' => ['platform.index' => true]]);
        $document = $this->forwardedDocument();

        $this->actingAs($recipientStaff)
            ->postJson(route('platform.documents.additional-routes.store', $document), [
                'source_office' => 'Administrative Unit',
                'destination_office' => 'SDO-Legal Unit',
                'dispatch_confirmed' => true,
            ])->assertForbidden();

        $this->assertDatabaseMissing('document_office_assignments', ['document_id' => $document->id, 'office' => 'SDO-Legal Unit']);
    }

    public function test_additional_route_rejects_unconfirmed_duplicate_and_closed_document(): void
    {
        $sender = User::factory()->create(['office' => 'Administrative Unit', 'permissions' => ['platform.index' => true]]);
        $document = $this->forwardedDocument();
        $url = route('platform.documents.additional-routes.store', $document);

        $this->actingAs($sender)
            ->postJson($url, ['source_office' => 'Administrative Unit', 'destination_office' => 'SDO-Legal Unit'])
            ->assertUnprocessable()->assertJsonValidationErrors('dispatch_confirmed');

        $this->postJson($url, [
            'source_office' => 'Administrative Unit', 'destination_office' => 'SDO-Budget Unit', 'dispatch_confirmed' => true,
        ])->assertUnprocessable()->assertJsonValidationErrors('destination_office');

        $document->update(['status' => DocumentMovement::STATUS_COMPLETED]);
        $this->postJson($url, [
            'source_office' => 'Administrative Unit', 'destination_office' => 'SDO-Legal Unit', 'dispatch_confirmed' => true,
        ])->assertUnprocessable()->assertJsonValidationErrors('destination_office');

        $this->assertDatabaseMissing('document_office_assignments', ['document_id' => $document->id, 'office' => 'SDO-Legal Unit']);
    }

    public function test_additional_route_requires_prior_forward_and_an_open_active_slot(): void
    {
        $sender = User::factory()->create(['office' => 'Administrative Unit', 'permissions' => ['platform.index' => true]]);
        $notForwarded = Document::factory()->received()->create(['current_office' => 'Administrative Unit']);

        $this->actingAs($sender)
            ->postJson(route('platform.documents.additional-routes.store', $notForwarded), [
                'source_office' => 'Administrative Unit',
                'destination_office' => 'SDO-Legal Unit',
                'dispatch_confirmed' => true,
            ])->assertForbidden();

        $document = $this->forwardedDocument();
        foreach (array_slice(array_values(array_diff(Document::officeOptions(), ['SDO-Budget Unit', 'SDO-Legal Unit'])), 0, 4) as $office) {
            $document->officeAssignments()->create(['office' => $office, 'status' => DocumentMovement::STATUS_RECEIVED]);
        }

        $this->postJson(route('platform.documents.additional-routes.store', $document), [
            'source_office' => 'Administrative Unit',
            'destination_office' => 'SDO-Legal Unit',
            'dispatch_confirmed' => true,
        ])->assertUnprocessable()->assertJsonValidationErrors('destination_office');

        $this->assertDatabaseMissing('document_office_assignments', ['document_id' => $document->id, 'office' => 'SDO-Legal Unit']);
    }

    private function forwardedDocument(): Document
    {
        $document = Document::factory()->create([
            'current_office' => 'SDO-Budget Unit',
            'status' => DocumentMovement::STATUS_FORWARDED,
        ]);
        $document->movements()->create([
            'action' => DocumentMovement::ACTION_FORWARDED,
            'from_office' => 'Administrative Unit',
            'to_office' => 'SDO-Budget Unit',
            'from_status' => DocumentMovement::STATUS_RECEIVED,
            'to_status' => DocumentMovement::STATUS_FORWARDED,
        ]);

        return $document;
    }
}
