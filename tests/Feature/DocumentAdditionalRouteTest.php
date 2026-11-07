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

    public function test_sending_office_can_add_multiple_destinations_in_one_request(): void
    {
        Notification::fake();
        $sender = User::factory()->create(['office' => 'Administrative Unit', 'permissions' => ['platform.index' => true]]);
        $legal = User::factory()->create(['office' => 'SDO-Legal Unit']);
        $records = User::factory()->create(['office' => 'SDO-Records Unit']);
        $document = $this->forwardedDocument();

        $this->actingAs($sender)->postJson(route('platform.documents.additional-routes.store', $document), [
            'source_office' => 'Administrative Unit',
            'destinations' => ['SDO-Legal Unit', 'SDO-Records Unit'],
            'destination_remarks' => ['Review the draft.', 'File a copy.'],
        ])->assertOk()->assertJsonPath('message', "{$document->tracking_number} was also forwarded to 2 destinations.");

        foreach (['SDO-Legal Unit' => 'Review the draft.', 'SDO-Records Unit' => 'File a copy.'] as $office => $remarks) {
            $this->assertDatabaseHas('document_office_assignments', ['document_id' => $document->id, 'office' => $office, 'status' => DocumentMovement::STATUS_FORWARDED]);
            $this->assertDatabaseHas('document_movements', ['document_id' => $document->id, 'to_office' => $office, 'remarks' => $remarks]);
        }
        $this->assertDatabaseHas('document_office_assignments', ['document_id' => $document->id, 'office' => 'SDO-Budget Unit']);
        $this->assertSame(2, $document->activities()->where('description', 'Document route added')->count());
        Notification::assertSentTo($legal, DocumentAssignedNotification::class);
        Notification::assertSentTo($records, DocumentAssignedNotification::class);
    }

    public function test_invalid_later_destination_rolls_back_the_whole_batch(): void
    {
        Notification::fake();
        $sender = User::factory()->create(['office' => 'Administrative Unit', 'permissions' => ['platform.index' => true]]);
        $document = $this->forwardedDocument();

        $this->actingAs($sender)->postJson(route('platform.documents.additional-routes.store', $document), [
            'source_office' => 'Administrative Unit',
            'destinations' => ['SDO-Legal Unit', 'SDO-Budget Unit'],
        ])->assertUnprocessable();

        $this->assertDatabaseMissing('document_office_assignments', ['document_id' => $document->id, 'office' => 'SDO-Legal Unit']);
        $this->assertDatabaseMissing('document_movements', ['document_id' => $document->id, 'to_office' => 'SDO-Legal Unit']);
        Notification::assertNothingSent();
    }

    public function test_remarks_for_one_destination_are_validated_before_any_route_is_added(): void
    {
        $sender = User::factory()->create(['office' => 'Administrative Unit', 'permissions' => ['platform.index' => true]]);
        $document = $this->forwardedDocument();

        $this->actingAs($sender)->postJson(route('platform.documents.additional-routes.store', $document), [
            'source_office' => 'Administrative Unit',
            'destinations' => ['SDO-Legal Unit', 'SDO-Records Unit'],
            'destination_remarks' => ['Review the draft.', str_repeat('x', 1001)],
        ])->assertUnprocessable()->assertJsonValidationErrors('destination_remarks.1');

        $this->assertDatabaseMissing('document_office_assignments', ['document_id' => $document->id, 'office' => 'SDO-Legal Unit']);
        $this->assertDatabaseMissing('document_office_assignments', ['document_id' => $document->id, 'office' => 'SDO-Records Unit']);
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

    public function test_additional_route_rejects_a_duplicate_and_a_closed_document(): void
    {
        $sender = User::factory()->create(['office' => 'Administrative Unit', 'permissions' => ['platform.index' => true]]);
        $document = $this->forwardedDocument();
        $url = route('platform.documents.additional-routes.store', $document);

        $this->actingAs($sender)->postJson($url, [
            'source_office' => 'Administrative Unit', 'destination_office' => 'SDO-Budget Unit', 'reason' => 'The ASDS asked for a copy.',
        ])->assertUnprocessable()->assertJsonValidationErrors('destination_office');

        $document->update(['status' => DocumentMovement::STATUS_COMPLETED]);
        $this->postJson($url, [
            'source_office' => 'Administrative Unit', 'destination_office' => 'SDO-Legal Unit', 'reason' => 'The ASDS asked for a copy.',
        ])->assertUnprocessable()->assertJsonValidationErrors('destination_office');

        $this->assertDatabaseMissing('document_office_assignments', ['document_id' => $document->id, 'office' => 'SDO-Legal Unit']);
    }

    public function test_additional_route_can_be_added_without_a_reason(): void
    {
        Notification::fake();
        $sender = User::factory()->create(['office' => 'Administrative Unit', 'permissions' => ['platform.index' => true]]);
        $document = $this->forwardedDocument();

        $this->actingAs($sender)
            ->postJson(route('platform.documents.additional-routes.store', $document), [
                'source_office' => 'Administrative Unit',
                'destination_office' => 'SDO-Legal Unit',
            ])
            ->assertOk();

        $this->assertDatabaseHas('document_office_assignments', [
            'document_id' => $document->id, 'office' => 'SDO-Legal Unit', 'status' => DocumentMovement::STATUS_FORWARDED,
        ]);

        // The history entry is still written; it simply carries no reason, and the workspace shows no Reason block.
        $added = $document->activities()->where('description', 'Document route added')->sole();
        $this->assertNull($added->properties['reason']);
        $this->get(route('platform.documents.workspace', $document))
            ->assertOk()
            ->assertDontSee('<strong>Reason</strong>', false);
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

    public function test_sending_office_cannot_add_a_route_before_receiving_a_returned_or_resubmitted_arrival(): void
    {
        $sender = User::factory()->create(['office' => 'Administrative Unit', 'permissions' => ['platform.index' => true]]);

        foreach ([DocumentMovement::STATUS_RETURNED, DocumentMovement::STATUS_RESUBMITTED] as $status) {
            $document = $this->forwardedDocument();
            $arrival = $document->movements()->create([
                'action' => $status === DocumentMovement::STATUS_RETURNED
                    ? DocumentMovement::ACTION_RETURNED
                    : DocumentMovement::ACTION_RESUBMITTED,
                'from_office' => 'SDO-Budget Unit',
                'to_office' => 'Administrative Unit',
                'from_status' => DocumentMovement::STATUS_RECEIVED,
                'to_status' => $status,
            ]);
            $document->officeAssignments()->create([
                'office' => 'Administrative Unit',
                'status' => $status,
                'movement_id' => $arrival->id,
            ]);
            $document->update(['current_office' => 'Administrative Unit', 'status' => $status]);

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
    }

    public function test_sending_office_can_add_back_an_office_that_finished_its_part(): void
    {
        Notification::fake();
        $sender = User::factory()->create(['office' => 'Administrative Unit', 'permissions' => ['platform.index' => true]]);
        $document = $this->forwardedDocument();
        $document->officeAssignments()->create(['office' => 'SDO-Legal Unit', 'status' => DocumentMovement::STATUS_COMPLETED]);

        $this->assertNotContains('SDO-Legal Unit', RouteFixActions::for($document->fresh(), $sender)['additional']['assigned']->all());

        $this->actingAs($sender)
            ->postJson(route('platform.documents.additional-routes.store', $document), [
                'source_office' => 'Administrative Unit',
                'destination_office' => 'SDO-Legal Unit',
                'reason' => 'Legal must check the revised contract.',
            ])
            ->assertOk();

        $this->assertSame(1, $document->officeAssignments()->where('office', 'SDO-Legal Unit')->count());
        $this->assertDatabaseHas('document_office_assignments', [
            'document_id' => $document->id, 'office' => 'SDO-Legal Unit', 'status' => DocumentMovement::STATUS_FORWARDED,
        ]);
    }

    public function test_additional_route_still_refuses_an_office_that_is_working_on_it(): void
    {
        $sender = User::factory()->create(['office' => 'Administrative Unit', 'permissions' => ['platform.index' => true]]);
        $document = $this->forwardedDocument();
        $document->officeAssignments()->create(['office' => 'SDO-Legal Unit', 'status' => DocumentMovement::STATUS_RECEIVED]);

        $this->actingAs($sender)
            ->postJson(route('platform.documents.additional-routes.store', $document), [
                'source_office' => 'Administrative Unit',
                'destination_office' => 'SDO-Legal Unit',
                'reason' => 'Duplicate.',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('destination_office');
    }

    public function test_the_registering_office_can_add_a_destination_before_anyone_receives(): void
    {
        Notification::fake();
        $records = User::factory()->create(['office' => 'SDO-Records Unit', 'permissions' => ['platform.index' => true]]);
        $document = $this->registeredDocument();

        // The button is offered, sent from the registering office.
        $this->assertSame(['SDO-Records Unit'], RouteFixActions::for($document, $records)['additional']['sources']->all());

        $this->actingAs($records)
            ->postJson(route('platform.documents.additional-routes.store', $document), [
                'source_office' => 'SDO-Records Unit',
                'destination_office' => 'SDO-Budget Unit',
            ])
            ->assertOk();

        // Recorded like a registration send, so it matches its sibling and the document stays unprocessed:
        // the destination list remains editable.
        $this->assertDatabaseHas('document_office_assignments', [
            'document_id' => $document->id, 'office' => 'SDO-Budget Unit', 'status' => DocumentMovement::STATUS_AWAITING_RECEIPT,
        ]);
        $this->assertDatabaseHas('document_movements', [
            'document_id' => $document->id, 'action' => DocumentMovement::ACTION_SENT, 'from_office' => null, 'to_office' => 'SDO-Budget Unit',
        ]);
        $fresh = $document->fresh();
        $this->assertFalse($fresh->hasBeenProcessed());
        $this->assertFalse($fresh->routeListIsLocked());
        $added = $fresh->activities()->where('description', 'Document route added')->sole();
        $this->assertSame('SDO-Records Unit', $added->properties['from_office']);
    }

    public function test_only_the_registering_office_may_add_to_a_registered_document_and_only_before_receipt(): void
    {
        $other = User::factory()->create(['office' => 'SDO-Accounting Unit', 'permissions' => ['platform.index' => true]]);
        $records = User::factory()->create(['office' => 'SDO-Records Unit', 'permissions' => ['platform.index' => true]]);
        $document = $this->registeredDocument();
        $url = route('platform.documents.additional-routes.store', $document);

        // An unrelated office does not even see the document, so the controller answers 404 before the route rules.
        $this->assertNull(RouteFixActions::for($document, $other)['additional']);
        $this->actingAs($other)->postJson($url, [
            'source_office' => 'SDO-Records Unit', 'destination_office' => 'SDO-Budget Unit',
        ])->assertNotFound();

        // Once an office receives it, the registration path closes; Forward takes over.
        $document->movements()->create([
            'action' => DocumentMovement::ACTION_RECEIVED, 'from_office' => 'SDO-Legal Unit', 'to_office' => 'SDO-Legal Unit',
            'from_status' => DocumentMovement::STATUS_AWAITING_RECEIPT, 'to_status' => DocumentMovement::STATUS_RECEIVED,
        ]);
        $document->officeAssignments()->where('office', 'SDO-Legal Unit')->update(['status' => DocumentMovement::STATUS_RECEIVED]);
        $document = $document->fresh();

        $this->assertNull(RouteFixActions::for($document, $records)['additional']);
        $this->actingAs($records)->postJson($url, [
            'source_office' => 'SDO-Records Unit', 'destination_office' => 'SDO-Budget Unit',
        ])->assertForbidden();
        $this->assertDatabaseMissing('document_office_assignments', ['document_id' => $document->id, 'office' => 'SDO-Budget Unit']);
    }

    private function registeredDocument(): Document
    {
        $document = Document::factory()->create([
            'current_office' => 'SDO-Legal Unit',
            'status' => DocumentMovement::STATUS_AWAITING_RECEIPT,
            'registered_office' => 'SDO-Records Unit',
        ]);
        $movement = $document->movements()->create([
            'action' => DocumentMovement::ACTION_SENT,
            'from_office' => null,
            'to_office' => 'SDO-Legal Unit',
            'from_status' => null,
            'to_status' => DocumentMovement::STATUS_AWAITING_RECEIPT,
        ]);
        $document->officeAssignments()->where('office', 'SDO-Legal Unit')->update(['movement_id' => $movement->id]);

        return $document;
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
