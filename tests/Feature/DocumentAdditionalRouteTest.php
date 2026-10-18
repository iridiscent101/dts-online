<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\DocumentMovement;
use App\Models\User;
use App\Notifications\DocumentAssignedNotification;
use App\Support\RouteFixActions;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Orchid\Platform\Models\Role;
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
                'reason' => 'The ASDS asked for a copy.',
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

        // The reason is kept in the history, under the sending office.
        $added = $document->activities()->where('description', 'Document route added')->sole();
        $this->assertSame('The ASDS asked for a copy.', $added->properties['reason']);
        $this->assertSame('SDO-Legal Unit', $added->properties['to_office']);
        $this->get(route('platform.documents.workspace', $document))
            ->assertSee('<strong>Reason</strong><p>The ASDS asked for a copy.</p>', false);
    }

    public function test_failed_notification_rolls_back_an_additional_route_before_retry(): void
    {
        $sender = User::factory()->create(['office' => 'Administrative Unit', 'permissions' => ['platform.index' => true]]);
        User::factory()->create(['office' => 'SDO-Legal Unit']);
        $document = $this->forwardedDocument();
        $url = route('platform.documents.additional-routes.store', $document);
        $payload = [
            'source_office' => 'Administrative Unit',
            'destination_office' => 'SDO-Legal Unit',
            'reason' => 'The ASDS asked for a copy.',
        ];
        $fail = true;
        Event::listen(NotificationSent::class, function () use (&$fail): void {
            if ($fail) {
                throw new \RuntimeException('Notification delivery failed.');
            }
        });

        $this->actingAs($sender)->postJson($url, $payload)->assertInternalServerError();

        $this->assertDatabaseMissing('document_office_assignments', ['document_id' => $document->id, 'office' => 'SDO-Legal Unit']);
        $this->assertSame(1, $document->movements()->count());
        $this->assertDatabaseCount('notifications', 0);

        $fail = false;
        $this->postJson($url, $payload)->assertOk();

        $this->assertDatabaseHas('document_office_assignments', ['document_id' => $document->id, 'office' => 'SDO-Legal Unit']);
        $this->assertSame(2, $document->movements()->count());
        $this->assertDatabaseCount('notifications', 1);
    }

    public function test_additional_route_requires_the_sending_office(): void
    {
        $recipientStaff = User::factory()->create(['office' => 'SDO-Budget Unit', 'permissions' => ['platform.index' => true]]);
        $document = $this->forwardedDocument();

        $this->actingAs($recipientStaff)
            ->postJson(route('platform.documents.additional-routes.store', $document), [
                'source_office' => 'Administrative Unit',
                'destination_office' => 'SDO-Legal Unit',
                'reason' => 'The ASDS asked for a copy.',
            ])->assertForbidden();

        $this->assertDatabaseMissing('document_office_assignments', ['document_id' => $document->id, 'office' => 'SDO-Legal Unit']);
    }

    public function test_administrator_can_add_a_missed_route_for_the_sending_office(): void
    {
        $adminRole = Role::create(['name' => 'Admin', 'slug' => User::ADMIN_ROLE_SLUG]);
        $admin = User::factory()->create(['office' => null]);
        $admin->replaceRoles([$adminRole->id]);
        $document = $this->forwardedDocument();

        $this->actingAs($admin)->postJson(route('platform.documents.additional-routes.store', $document), [
            'source_office' => 'Administrative Unit',
            'destination_office' => 'SDO-Legal Unit',
            'reason' => 'The ASDS asked for a copy.',
        ])->assertOk();

        $this->assertDatabaseHas('document_movements', [
            'document_id' => $document->id,
            'action' => DocumentMovement::ACTION_FORWARDED,
            'from_office' => 'Administrative Unit',
            'to_office' => 'SDO-Legal Unit',
            'acted_by' => $admin->id,
        ]);
    }

    public function test_additional_route_rejects_a_missing_reason_a_duplicate_and_a_closed_document(): void
    {
        $sender = User::factory()->create(['office' => 'Administrative Unit', 'permissions' => ['platform.index' => true]]);
        $document = $this->forwardedDocument();
        $url = route('platform.documents.additional-routes.store', $document);

        $this->actingAs($sender)
            ->postJson($url, ['source_office' => 'Administrative Unit', 'destination_office' => 'SDO-Legal Unit'])
            ->assertUnprocessable()->assertJsonValidationErrors('reason');

        $this->postJson($url, [
            'source_office' => 'Administrative Unit', 'destination_office' => 'SDO-Budget Unit', 'reason' => 'The ASDS asked for a copy.',
        ])->assertUnprocessable()->assertJsonValidationErrors('destination_office');

        $document->update(['status' => DocumentMovement::STATUS_COMPLETED]);
        $this->postJson($url, [
            'source_office' => 'Administrative Unit', 'destination_office' => 'SDO-Legal Unit', 'reason' => 'The ASDS asked for a copy.',
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
                'reason' => 'The ASDS asked for a copy.',
            ])->assertForbidden();

        $document = $this->forwardedDocument();
        foreach (array_slice(array_values(array_diff(Document::officeOptions(), ['SDO-Budget Unit', 'SDO-Legal Unit'])), 0, 4) as $office) {
            $document->officeAssignments()->create(['office' => $office, 'status' => DocumentMovement::STATUS_RECEIVED]);
        }

        $this->postJson(route('platform.documents.additional-routes.store', $document), [
            'source_office' => 'Administrative Unit',
            'destination_office' => 'SDO-Legal Unit',
            'reason' => 'The ASDS asked for a copy.',
        ])->assertUnprocessable()->assertJsonValidationErrors('destination_office');

        $this->assertDatabaseMissing('document_office_assignments', ['document_id' => $document->id, 'office' => 'SDO-Legal Unit']);
    }

    public function test_completed_sending_office_cannot_add_another_route_while_another_branch_is_active(): void
    {
        $sender = User::factory()->create(['office' => 'Administrative Unit', 'permissions' => ['platform.index' => true]]);
        $document = $this->forwardedDocument();
        $document->officeAssignments()->create([
            'office' => 'Administrative Unit',
            'status' => DocumentMovement::STATUS_COMPLETED,
        ]);

        $this->actingAs($sender)
            ->postJson(route('platform.documents.additional-routes.store', $document), [
                'source_office' => 'Administrative Unit',
                'destination_office' => 'SDO-Legal Unit',
                'reason' => 'The ASDS asked for a copy.',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('source_office');

        $this->assertDatabaseMissing('document_office_assignments', [
            'document_id' => $document->id,
            'office' => 'SDO-Legal Unit',
        ]);
        $this->assertNull(RouteFixActions::for($document->fresh(), $sender)['additional']);
    }

    public function test_sending_office_cannot_add_a_route_before_receiving_a_new_arrival(): void
    {
        $sender = User::factory()->create(['office' => 'Administrative Unit', 'permissions' => ['platform.index' => true]]);
        $document = $this->forwardedDocument();
        $newArrival = $document->movements()->create([
            'action' => DocumentMovement::ACTION_FORWARDED,
            'from_office' => 'SDO-Budget Unit',
            'to_office' => 'Administrative Unit',
            'from_status' => DocumentMovement::STATUS_RECEIVED,
            'to_status' => DocumentMovement::STATUS_FORWARDED,
        ]);
        $document->officeAssignments()->create([
            'office' => 'Administrative Unit',
            'status' => DocumentMovement::STATUS_FORWARDED,
            'movement_id' => $newArrival->id,
        ]);
        $document->update(['current_office' => 'Administrative Unit']);

        $this->actingAs($sender)
            ->postJson(route('platform.documents.additional-routes.store', $document), [
                'source_office' => 'Administrative Unit',
                'destination_office' => 'SDO-Legal Unit',
                'reason' => 'An additional office was missed.',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('source_office');

        $this->assertDatabaseMissing('document_office_assignments', [
            'document_id' => $document->id,
            'office' => 'SDO-Legal Unit',
        ]);
        $this->assertNull(RouteFixActions::for($document->fresh(), $sender)['additional']);
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
