<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\DocumentMovement;
use App\Models\Office;
use App\Models\User;
use App\Notifications\DocumentAssignedNotification;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Orchid\Platform\Models\Role;
use Tests\TestCase;

class DocumentRouteCorrectionTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_sending_office_corrects_an_unreceived_destination_with_a_reason_and_history(): void
    {
        $sender = User::factory()->create(['office' => 'Administrative Unit', 'permissions' => ['platform.index' => true]]);
        $recipient = User::factory()->create(['office' => 'SDO-Legal Unit']);
        $document = $this->forwardedDocument();
        Notification::fake();

        $this->actingAs($sender)->postJson(route('platform.documents.routes.correct', $document), [
            'source_office' => 'Administrative Unit',
            'destination_office' => 'SDO-Budget Unit',
            'replacement_office' => 'SDO-Legal Unit',
            'correction_reason' => 'Selected Budget instead of Legal.',
        ])->assertOk()->assertJsonPath('message', 'Route corrected to SDO-Legal Unit.');

        $this->assertDatabaseMissing('document_office_assignments', ['document_id' => $document->id, 'office' => 'SDO-Budget Unit']);
        $this->assertDatabaseHas('document_office_assignments', ['document_id' => $document->id, 'office' => 'SDO-Legal Unit', 'status' => DocumentMovement::STATUS_FORWARDED]);
        $this->assertDatabaseHas('document_movements', ['document_id' => $document->id, 'action' => DocumentMovement::ACTION_CANCELLED, 'to_office' => 'SDO-Budget Unit', 'remarks' => 'Correction reason: Selected Budget instead of Legal.']);
        $this->assertDatabaseHas('document_movements', ['document_id' => $document->id, 'action' => DocumentMovement::ACTION_FORWARDED, 'to_office' => 'SDO-Legal Unit', 'remarks' => 'Review this document.']);
        $this->assertDatabaseHas('activity_log', ['subject_id' => $document->id, 'description' => 'Document route corrected', 'properties->office' => 'Administrative Unit', 'properties->correction_reason' => 'Selected Budget instead of Legal.']);
        $this->assertSame('SDO-Legal Unit', $document->fresh()->current_office);
        Notification::assertSentTo($recipient, DocumentAssignedNotification::class);
    }

    public function test_failed_notification_rolls_back_a_route_correction_before_retry(): void
    {
        $sender = User::factory()->create(['office' => 'Administrative Unit', 'permissions' => ['platform.index' => true]]);
        User::factory()->create(['office' => 'SDO-Legal Unit']);
        $document = $this->forwardedDocument();
        $url = route('platform.documents.routes.correct', $document);
        $payload = [
            'source_office' => 'Administrative Unit',
            'destination_office' => 'SDO-Budget Unit',
            'replacement_office' => 'SDO-Legal Unit',
            'correction_reason' => 'Budget was selected by mistake.',
        ];
        $fail = true;
        Event::listen(NotificationSent::class, function () use (&$fail): void {
            if ($fail) {
                throw new \RuntimeException('Notification delivery failed.');
            }
        });

        $this->actingAs($sender)->postJson($url, $payload)->assertInternalServerError();

        $this->assertDatabaseHas('document_office_assignments', ['document_id' => $document->id, 'office' => 'SDO-Budget Unit']);
        $this->assertDatabaseMissing('document_office_assignments', ['document_id' => $document->id, 'office' => 'SDO-Legal Unit']);
        $this->assertSame(0, $document->movements()->where('action', DocumentMovement::ACTION_CANCELLED)->count());
        $this->assertDatabaseCount('notifications', 0);

        $fail = false;
        $this->postJson($url, $payload)->assertOk();

        $this->assertDatabaseHas('document_office_assignments', ['document_id' => $document->id, 'office' => 'SDO-Legal Unit']);
        $this->assertSame(1, $document->movements()->where('action', DocumentMovement::ACTION_CANCELLED)->count());
        $this->assertDatabaseCount('notifications', 1);
    }

    public function test_corrected_destination_cannot_reopen_document_from_an_old_notification(): void
    {
        $sender = User::factory()->create(['office' => 'Administrative Unit', 'permissions' => ['platform.index' => true]]);
        $oldRecipient = User::factory()->create(['office' => 'SDO-Budget Unit', 'permissions' => ['platform.index' => true]]);
        $newRecipient = User::factory()->create(['office' => 'SDO-Legal Unit', 'permissions' => ['platform.index' => true]]);
        $document = $this->forwardedDocument();
        $oldRecipient->notify(new DocumentAssignedNotification($document, DocumentMovement::ACTION_FORWARDED, $sender->name));
        $oldNotification = $oldRecipient->unreadNotifications()->sole();

        $this->actingAs($sender)->postJson(route('platform.documents.routes.correct', $document), [
            'source_office' => 'Administrative Unit',
            'destination_office' => 'SDO-Budget Unit',
            'replacement_office' => 'SDO-Legal Unit',
            'correction_reason' => 'Budget was selected by mistake.',
        ])->assertOk();

        $this->actingAs($oldRecipient)->get(route('platform.documents.view', $document))->assertNotFound();
        $this->get(route('platform.notifications.open', $oldNotification->id))->assertRedirectToRoute('platform.main');
        $this->assertNotNull($oldNotification->fresh()->read_at);

        $this->actingAs($newRecipient)->get(route('platform.documents.view', $document))->assertOk();
        $this->assertSame(1, $newRecipient->notifications()->count());
    }

    public function test_admin_corrects_only_the_selected_forwarded_branch_without_changing_its_source(): void
    {
        $adminRole = Role::create(['name' => 'Admin', 'slug' => User::ADMIN_ROLE_SLUG]);
        $admin = User::factory()->create(['office' => 'SDO-Records Unit']);
        $admin->replaceRoles([$adminRole->id]);
        $document = $this->forwardedDocument();
        $secondMovement = $document->movements()->create([
            'action' => DocumentMovement::ACTION_FORWARDED,
            'from_office' => 'SDO-Property and Supply Unit',
            'to_office' => 'SDO-Accounting Unit',
            'from_status' => DocumentMovement::STATUS_RECEIVED,
            'to_status' => DocumentMovement::STATUS_FORWARDED,
            'remarks' => 'Second branch instructions.',
        ]);
        $document->officeAssignments()->create([
            'office' => 'SDO-Accounting Unit',
            'status' => DocumentMovement::STATUS_FORWARDED,
            'movement_id' => $secondMovement->id,
        ]);

        $this->actingAs($admin)
            ->get(route('platform.documents.workspace', $document))
            ->assertOk()
            ->assertSee('data-route-correction=', false);

        $this->postJson(route('platform.documents.routes.correct', $document), [
            'source_office' => 'SDO-Records Unit',
            'destination_office' => 'SDO-Budget Unit',
            'replacement_office' => 'SDO-Legal Unit',
            'correction_reason' => 'Wrong sending branch.',
        ])->assertForbidden();

        $this->postJson(route('platform.documents.routes.correct', $document), [
            'source_office' => 'Administrative Unit',
            'destination_office' => 'SDO-Budget Unit',
            'replacement_office' => 'SDO-Legal Unit',
            'correction_reason' => 'Correcting the Budget branch.',
        ])->assertOk();

        $this->assertDatabaseHas('document_movements', [
            'document_id' => $document->id,
            'action' => DocumentMovement::ACTION_FORWARDED,
            'from_office' => 'Administrative Unit',
            'to_office' => 'SDO-Legal Unit',
            'remarks' => 'Review this document.',
            'acted_by' => $admin->id,
        ]);
        $this->assertDatabaseHas('document_office_assignments', [
            'document_id' => $document->id,
            'office' => 'SDO-Accounting Unit',
            'movement_id' => $secondMovement->id,
        ]);
    }

    public function test_another_user_in_the_sending_office_can_correct_a_forwarded_route(): void
    {
        $sender = User::factory()->create(['office' => 'Administrative Unit']);
        $colleague = User::factory()->create(['office' => 'Administrative Unit', 'permissions' => ['platform.index' => true]]);
        $document = $this->forwardedDocument();
        $document->movements()->where('action', DocumentMovement::ACTION_FORWARDED)->update(['acted_by' => $sender->id]);
        Notification::fake();

        $this->actingAs($colleague)->postJson(route('platform.documents.routes.correct', $document), [
            'source_office' => 'Administrative Unit',
            'destination_office' => 'SDO-Budget Unit',
            'replacement_office' => 'SDO-Legal Unit',
            'correction_reason' => 'Our office chose the wrong destination.',
        ])->assertOk();

        $this->assertDatabaseHas('document_movements', ['document_id' => $document->id, 'action' => DocumentMovement::ACTION_CANCELLED, 'to_office' => 'SDO-Budget Unit', 'acted_by' => $colleague->id]);
        $this->assertDatabaseHas('activity_log', ['subject_id' => $document->id, 'description' => 'Document route corrected', 'causer_id' => $colleague->id]);
    }

    public function test_another_user_in_the_registering_office_can_correct_an_unreceived_initial_route(): void
    {
        $registrar = User::factory()->create(['office' => 'Administrative Unit']);
        $colleague = User::factory()->create(['office' => 'Administrative Unit', 'permissions' => ['platform.index' => true]]);
        $document = $this->registeredDocument($registrar);
        Notification::fake();

        $this->assertTrue($document->isVisibleTo($colleague));
        $this->assertTrue(Document::query()->visibleTo($colleague)->whereKey($document)->exists());
        $this->actingAs($colleague)->postJson(route('platform.documents.routes.correct', $document), [
            'source_office' => 'Administrative Unit',
            'destination_office' => 'SDO-Accounting Unit',
            'replacement_office' => 'SDO-Budget Unit',
            'correction_reason' => 'Our office chose the wrong destination.',
        ])->assertOk();

        $this->assertDatabaseHas('document_movements', ['document_id' => $document->id, 'action' => DocumentMovement::ACTION_SENT, 'to_office' => 'SDO-Budget Unit', 'acted_by' => $colleague->id]);
        $this->assertDatabaseHas('activity_log', ['subject_id' => $document->id, 'description' => 'Document route corrected', 'causer_id' => $colleague->id, 'properties->correction_reason' => 'Our office chose the wrong destination.']);
    }

    public function test_reassigning_the_registrar_does_not_transfer_initial_route_control_to_the_new_office(): void
    {
        $registrar = User::factory()->create(['office' => 'Administrative Unit']);
        $originalColleague = User::factory()->create(['office' => 'Administrative Unit', 'permissions' => ['platform.index' => true]]);
        $newColleague = User::factory()->create(['office' => 'SDO-Legal Unit', 'permissions' => ['platform.index' => true]]);
        $document = $this->registeredDocument($registrar);
        $newOffice = Office::query()->where('name', 'SDO-Legal Unit')->firstOrFail();
        $registrar->offices()->sync([$newOffice->id]);

        $this->assertSame('Administrative Unit', $document->fresh()->registered_office);
        $this->assertTrue($document->isVisibleTo($originalColleague));
        $this->assertTrue(Document::query()->visibleTo($originalColleague)->whereKey($document)->exists());
        $this->assertFalse($document->isVisibleTo($newColleague));
        $this->assertFalse(Document::query()->visibleTo($newColleague)->whereKey($document)->exists());
        $this->assertFalse($document->isVisibleTo($registrar));
        $this->assertFalse(Document::query()->visibleTo($registrar)->whereKey($document)->exists());
        $registrar->offices()->detach();
        $this->assertFalse(Document::query()->visibleTo($registrar)->whereKey($document)->exists());

        $this->actingAs($newColleague)->postJson(route('platform.documents.routes.correct', $document), [
            'source_office' => 'SDO-Legal Unit',
            'destination_office' => 'SDO-Accounting Unit',
            'replacement_office' => 'SDO-Budget Unit',
            'correction_reason' => 'Wrong office.',
        ])->assertNotFound();

        $this->actingAs($originalColleague)->postJson(route('platform.documents.routes.correct', $document), [
            'source_office' => 'Administrative Unit',
            'destination_office' => 'SDO-Accounting Unit',
            'replacement_office' => 'SDO-Budget Unit',
            'correction_reason' => 'Wrong office.',
        ])->assertOk();
    }

    public function test_records_registrar_can_correct_an_initial_destination_before_receipt(): void
    {
        $registrar = User::factory()->create(['office' => 'SDO-Records Unit', 'permissions' => ['platform.index' => true]]);
        $document = $this->registeredDocument($registrar);
        Notification::fake();

        $this->actingAs($registrar)->postJson(route('platform.documents.routes.correct', $document), [
            'source_office' => 'SDO-Records Unit',
            'destination_office' => 'SDO-Accounting Unit',
            'replacement_office' => 'SDO-Budget Unit',
            'correction_reason' => 'Accounting was selected by mistake.',
        ])->assertOk()->assertJsonPath('message', 'Route corrected to SDO-Budget Unit.');

        $this->assertDatabaseMissing('document_office_assignments', ['document_id' => $document->id, 'office' => 'SDO-Accounting Unit']);
        $this->assertDatabaseHas('document_office_assignments', ['document_id' => $document->id, 'office' => 'SDO-Budget Unit', 'status' => DocumentMovement::STATUS_AWAITING_RECEIPT]);
        $this->assertDatabaseHas('document_movements', ['document_id' => $document->id, 'action' => DocumentMovement::ACTION_CANCELLED, 'to_office' => 'SDO-Accounting Unit', 'remarks' => 'Correction reason: Accounting was selected by mistake.']);
        $this->assertDatabaseHas('document_movements', ['document_id' => $document->id, 'action' => DocumentMovement::ACTION_SENT, 'from_office' => null, 'to_office' => 'SDO-Budget Unit']);
        $this->assertSame('SDO-Budget Unit', $document->fresh()->current_office);
    }

    public function test_other_records_staff_can_correct_the_registrars_initial_route_with_attribution(): void
    {
        $registrar = User::factory()->create(['office' => 'SDO-Records Unit']);
        $otherStaff = User::factory()->create(['office' => 'SDO-Records Unit', 'permissions' => ['platform.index' => true]]);
        $document = $this->registeredDocument($registrar);
        Notification::fake();

        $this->assertTrue($document->isVisibleTo($otherStaff));
        $this->assertTrue(Document::query()->visibleTo($otherStaff)->whereKey($document)->exists());

        $this->actingAs($otherStaff)->postJson(route('platform.documents.routes.correct', $document), [
            'source_office' => 'SDO-Records Unit',
            'destination_office' => 'SDO-Accounting Unit',
            'replacement_office' => 'SDO-Budget Unit',
            'correction_reason' => 'Accounting was selected by mistake.',
        ])->assertOk();

        $this->assertDatabaseHas('document_movements', ['document_id' => $document->id, 'action' => DocumentMovement::ACTION_CANCELLED, 'to_office' => 'SDO-Accounting Unit', 'acted_by' => $otherStaff->id]);
        $this->assertDatabaseHas('document_movements', ['document_id' => $document->id, 'action' => DocumentMovement::ACTION_SENT, 'to_office' => 'SDO-Budget Unit', 'acted_by' => $otherStaff->id]);
        $this->assertDatabaseHas('activity_log', ['subject_id' => $document->id, 'description' => 'Document route corrected', 'causer_id' => $otherStaff->id, 'properties->correction_reason' => 'Accounting was selected by mistake.']);
        $this->assertDatabaseMissing('document_office_assignments', ['document_id' => $document->id, 'office' => 'SDO-Accounting Unit']);
    }

    public function test_records_workspace_offers_route_correction_before_receipt_only(): void
    {
        $registrar = User::factory()->create(['office' => 'SDO-Records Unit']);
        $colleague = User::factory()->create(['office' => 'SDO-Records Unit', 'permissions' => ['platform.index' => true]]);
        $document = $this->registeredDocument($registrar);

        $page = $this->actingAs($colleague)->get(route('platform.documents.workspace', $document))
            ->assertOk()->assertSee('data-route-correction=', false);
        preg_match("/data-route-correction='([^']+)'/", $page->getContent(), $match);
        $details = json_decode(html_entity_decode($match[1] ?? ''), true);
        $this->assertSame([['source' => 'SDO-Records Unit', 'destination' => 'SDO-Accounting Unit']], $details['routes']);

        $document->officeAssignments()->where('office', 'SDO-Accounting Unit')->update(['status' => DocumentMovement::STATUS_RECEIVED]);
        $this->get(route('platform.documents.workspace', $document))->assertDontSee('data-route-correction=', false);
    }

    public function test_records_registrar_cannot_correct_an_initial_route_after_receipt(): void
    {
        $registrar = User::factory()->create(['office' => 'SDO-Records Unit', 'permissions' => ['platform.index' => true]]);
        $document = $this->registeredDocument($registrar);
        $document->officeAssignments()->where('office', 'SDO-Accounting Unit')->update(['status' => DocumentMovement::STATUS_RECEIVED]);

        $this->actingAs($registrar)->postJson(route('platform.documents.routes.correct', $document), [
            'source_office' => 'SDO-Records Unit',
            'destination_office' => 'SDO-Accounting Unit',
            'replacement_office' => 'SDO-Budget Unit',
            'correction_reason' => 'Accounting was selected by mistake.',
        ])->assertUnprocessable()->assertJsonValidationErrors('destination_office');

        $this->assertDatabaseHas('document_office_assignments', ['document_id' => $document->id, 'office' => 'SDO-Accounting Unit']);
    }

    public function test_records_staff_can_correct_the_same_unreceived_route_again(): void
    {
        $registrar = User::factory()->create(['office' => 'SDO-Records Unit', 'permissions' => ['platform.index' => true]]);
        $otherStaff = User::factory()->create(['office' => 'SDO-Records Unit', 'permissions' => ['platform.index' => true]]);
        $document = $this->registeredDocument($registrar);
        Notification::fake();

        $this->actingAs($registrar)->postJson(route('platform.documents.routes.correct', $document), [
            'source_office' => 'SDO-Records Unit',
            'destination_office' => 'SDO-Accounting Unit',
            'replacement_office' => 'SDO-Budget Unit',
            'correction_reason' => 'First correction.',
        ])->assertOk();

        $this->actingAs($otherStaff)->postJson(route('platform.documents.routes.correct', $document), [
            'source_office' => 'SDO-Records Unit',
            'destination_office' => 'SDO-Budget Unit',
            'replacement_office' => 'SDO-Legal Unit',
            'correction_reason' => 'Second correction.',
        ])->assertOk();

        $this->assertDatabaseHas('document_office_assignments', ['document_id' => $document->id, 'office' => 'SDO-Legal Unit', 'assigned_by' => $otherStaff->id]);
        $this->assertDatabaseHas('activity_log', ['subject_id' => $document->id, 'description' => 'Document route corrected', 'causer_id' => $otherStaff->id, 'properties->correction_reason' => 'Second correction.']);
    }

    public function test_records_staff_cannot_correct_another_offices_initial_send(): void
    {
        $registrar = User::factory()->create(['office' => 'Administrative Unit']);
        $recordsStaff = User::factory()->create(['office' => 'SDO-Records Unit', 'permissions' => ['platform.index' => true, 'platform.documents.view-all' => true]]);
        $document = $this->registeredDocument($registrar);

        $this->actingAs($recordsStaff)->postJson(route('platform.documents.routes.correct', $document), [
            'source_office' => 'SDO-Records Unit',
            'destination_office' => 'SDO-Accounting Unit',
            'replacement_office' => 'SDO-Budget Unit',
            'correction_reason' => 'Wrong office.',
        ])->assertForbidden();

        $this->assertDatabaseHas('document_office_assignments', ['document_id' => $document->id, 'office' => 'SDO-Accounting Unit']);
    }

    public function test_other_office_cannot_correct_the_senders_route(): void
    {
        $document = $this->forwardedDocument();
        $recipient = User::factory()->create(['office' => 'SDO-Budget Unit', 'permissions' => ['platform.index' => true]]);

        $this->actingAs($recipient)->postJson(route('platform.documents.routes.correct', $document), [
            'source_office' => 'Administrative Unit',
            'destination_office' => 'SDO-Budget Unit',
            'replacement_office' => 'SDO-Legal Unit',
            'correction_reason' => 'Wrong office.',
        ])->assertForbidden();

        $this->assertDatabaseHas('document_office_assignments', ['document_id' => $document->id, 'office' => 'SDO-Budget Unit']);
    }

    public function test_received_destination_cannot_be_corrected(): void
    {
        $document = $this->forwardedDocument();
        $document->officeAssignments()->where('office', 'SDO-Budget Unit')->update(['status' => DocumentMovement::STATUS_RECEIVED]);
        $sender = User::factory()->create(['office' => 'Administrative Unit', 'permissions' => ['platform.index' => true]]);

        $this->actingAs($sender)->postJson(route('platform.documents.routes.correct', $document), [
            'source_office' => 'Administrative Unit',
            'destination_office' => 'SDO-Budget Unit',
            'replacement_office' => 'SDO-Legal Unit',
            'correction_reason' => 'Wrong office.',
        ])->assertUnprocessable()->assertJsonValidationErrors('destination_office');

        $this->assertDatabaseHas('document_office_assignments', ['document_id' => $document->id, 'office' => 'SDO-Budget Unit']);
        $this->assertDatabaseMissing('document_office_assignments', ['document_id' => $document->id, 'office' => 'SDO-Legal Unit']);
    }

    public function test_receipt_wins_over_a_stale_route_correction(): void
    {
        $document = $this->forwardedDocument();
        $recipient = User::factory()->create(['office' => 'SDO-Budget Unit', 'permissions' => ['platform.index' => true]]);
        $sender = User::factory()->create(['office' => 'Administrative Unit', 'permissions' => ['platform.index' => true]]);
        $movementUrl = route('platform.documents.movements.store', $document);
        $correctionUrl = route('platform.documents.routes.correct', $document);

        $this->actingAs($recipient)->postJson($movementUrl, [
            'workflow' => ['action' => 'received', 'source_office' => 'SDO-Budget Unit'],
        ])->assertOk();

        $this->actingAs($sender)->postJson($correctionUrl, [
            'source_office' => 'Administrative Unit',
            'destination_office' => 'SDO-Budget Unit',
            'replacement_office' => 'SDO-Legal Unit',
            'correction_reason' => 'This correction was opened before receipt.',
        ])->assertUnprocessable()->assertJsonValidationErrors('destination_office');

        $this->assertDatabaseHas('document_office_assignments', [
            'document_id' => $document->id,
            'office' => 'SDO-Budget Unit',
            'status' => DocumentMovement::STATUS_RECEIVED,
        ]);
        $this->assertDatabaseMissing('document_office_assignments', ['document_id' => $document->id, 'office' => 'SDO-Legal Unit']);
        $this->assertSame(0, $document->movements()->where('action', DocumentMovement::ACTION_CANCELLED)->count());
    }

    public function test_correction_wins_over_a_stale_receipt_for_the_old_destination(): void
    {
        $document = $this->forwardedDocument();
        $oldRecipient = User::factory()->create(['office' => 'SDO-Budget Unit', 'permissions' => ['platform.index' => true]]);
        $newRecipient = User::factory()->create(['office' => 'SDO-Legal Unit', 'permissions' => ['platform.index' => true]]);
        $sender = User::factory()->create(['office' => 'Administrative Unit', 'permissions' => ['platform.index' => true]]);
        $movementUrl = route('platform.documents.movements.store', $document);

        $this->actingAs($sender)->postJson(route('platform.documents.routes.correct', $document), [
            'source_office' => 'Administrative Unit',
            'destination_office' => 'SDO-Budget Unit',
            'replacement_office' => 'SDO-Legal Unit',
            'correction_reason' => 'Budget was selected by mistake.',
        ])->assertOk();

        $this->actingAs($oldRecipient)->postJson($movementUrl, [
            'workflow' => ['action' => 'received', 'source_office' => 'SDO-Budget Unit'],
        ])->assertForbidden();

        $this->actingAs($newRecipient)->postJson($movementUrl, [
            'workflow' => ['action' => 'received', 'source_office' => 'SDO-Legal Unit'],
        ])->assertOk();

        $this->assertDatabaseMissing('document_office_assignments', ['document_id' => $document->id, 'office' => 'SDO-Budget Unit']);
        $this->assertDatabaseHas('document_office_assignments', [
            'document_id' => $document->id,
            'office' => 'SDO-Legal Unit',
            'status' => DocumentMovement::STATUS_RECEIVED,
        ]);
        $this->assertSame(1, $document->movements()->where('action', DocumentMovement::ACTION_RECEIVED)->count());
    }

    public function test_route_correction_requires_a_reason_and_an_unassigned_replacement(): void
    {
        $document = $this->forwardedDocument();
        $document->officeAssignments()->create(['office' => 'SDO-Legal Unit', 'status' => DocumentMovement::STATUS_FORWARDED]);
        $sender = User::factory()->create(['office' => 'Administrative Unit', 'permissions' => ['platform.index' => true]]);

        $this->actingAs($sender)->postJson(route('platform.documents.routes.correct', $document), [
            'source_office' => 'Administrative Unit',
            'destination_office' => 'SDO-Budget Unit',
            'replacement_office' => 'SDO-Legal Unit',
        ])->assertUnprocessable()->assertJsonValidationErrors('correction_reason');

        $this->postJson(route('platform.documents.routes.correct', $document), [
            'source_office' => 'Administrative Unit',
            'destination_office' => 'SDO-Budget Unit',
            'replacement_office' => 'SDO-Legal Unit',
            'correction_reason' => 'Wrong office.',
        ])->assertUnprocessable()->assertJsonValidationErrors('replacement_office');

        $this->assertDatabaseHas('document_office_assignments', ['document_id' => $document->id, 'office' => 'SDO-Budget Unit']);
    }

    public function test_route_correction_history_is_limited_to_the_involved_offices(): void
    {
        $document = $this->forwardedDocument();
        $document->officeAssignments()->create(['office' => 'Curriculum Implementation Division', 'status' => DocumentMovement::STATUS_RECEIVED]);
        $sender = User::factory()->create(['office' => 'Administrative Unit', 'permissions' => ['platform.index' => true]]);
        $unrelated = User::factory()->create(['office' => 'Curriculum Implementation Division', 'permissions' => ['platform.index' => true]]);

        $this->actingAs($sender)->postJson(route('platform.documents.routes.correct', $document), [
            'source_office' => 'Administrative Unit',
            'destination_office' => 'SDO-Budget Unit',
            'replacement_office' => 'SDO-Legal Unit',
            'correction_reason' => 'Selected Budget instead of Legal.',
        ])->assertOk();

        $this->get(route('platform.documents.workspace', $document))
            ->assertSee('Route corrected')
            ->assertSeeInOrder(['<dt>Wrong office</dt><dd>SDO-Budget Unit</dd>', '<dt>Corrected to</dt><dd>SDO-Legal Unit</dd>'], false)
            ->assertSee('Correction reason: Selected Budget instead of Legal.')
            // Newest first: the correction sits above the steps it caused, not below them.
            ->assertSeeInOrder(['Route corrected', 'Forwarded to SDO-Legal Unit', 'Route to SDO-Budget Unit cancelled', 'Forwarded to SDO-Budget Unit']);

        $this->actingAs($unrelated)->get(route('platform.documents.workspace', $document))
            ->assertDontSee('Route corrected');
    }

    public function test_outgoing_list_offers_correct_route_only_to_the_sending_office_before_receipt(): void
    {
        $sender = User::factory()->create(['office' => 'Administrative Unit', 'permissions' => ['platform.index' => true]]);
        $unrelated = User::factory()->create(['office' => 'SDO-Legal Unit', 'permissions' => ['platform.index' => true]]);
        $document = $this->forwardedDocument();

        $page = $this->actingAs($sender)->get(route('platform.outgoing'))->assertOk()->assertSee('Correct route');
        preg_match("/data-route-correction='([^']+)'/", $page->getContent(), $match);
        $details = json_decode(html_entity_decode($match[1] ?? ''), true);
        $this->assertSame(route('platform.documents.routes.correct', $document), $details['url']);
        $this->assertSame([['source' => 'Administrative Unit', 'destination' => 'SDO-Budget Unit']], $details['routes']);

        $this->actingAs($unrelated)->get(route('platform.outgoing'))->assertOk()->assertDontSee('data-route-correction', false);

        $document->officeAssignments()->where('office', 'SDO-Budget Unit')->update(['status' => DocumentMovement::STATUS_RECEIVED]);
        $this->actingAs($sender)->get(route('platform.outgoing'))->assertOk()->assertDontSee('data-route-correction', false);
    }

    public function test_outgoing_route_fix_actions_batch_load_movements_for_multiple_documents(): void
    {
        $sender = User::factory()->create(['office' => 'Administrative Unit', 'permissions' => ['platform.index' => true]]);
        $documents = [];
        for ($index = 0; $index < 3; $index++) {
            $documents[] = $this->forwardedDocument();
        }
        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $firstTable = explode(' from ', strtolower($query->sql), 2)[1] ?? '';
            if (str_starts_with($firstTable, '"document_movements"')) {
                $queries[] = strtolower($query->sql);
            }
        });

        $response = $this->actingAs($sender)
            ->get(route('platform.outgoing'))
            ->assertOk()
            ->assertSee('Correct route')
            ->assertSee('Send to another office');
        foreach ($documents as $document) {
            $response->assertSee($document->tracking_number);
        }

        $this->assertLessThanOrEqual(2, count($queries), implode("\n", $queries));
    }

    public function test_dashboard_route_fix_actions_batch_load_movements_for_multiple_documents(): void
    {
        $adminRole = Role::create(['name' => 'Admin', 'slug' => User::ADMIN_ROLE_SLUG]);
        $admin = User::factory()->create(['office' => null]);
        $admin->replaceRoles([$adminRole->id]);
        $documents = [];
        for ($index = 0; $index < 3; $index++) {
            $documents[] = $this->forwardedDocument();
        }
        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $firstTable = explode(' from ', strtolower($query->sql), 2)[1] ?? '';
            if (str_starts_with($firstTable, '"document_movements"')) {
                $queries[] = strtolower($query->sql);
            }
        });

        $response = $this->actingAs($admin)
            ->get(route('platform.main'))
            ->assertOk()
            ->assertSee('data-route-correction', false)
            ->assertSee('data-additional-route', false);
        foreach ($documents as $document) {
            $response->assertSee($document->tracking_number);
        }

        $this->assertLessThanOrEqual(3, count($queries), implode("\n", $queries));
    }

    public function test_document_window_offers_correct_route_for_an_unreceived_destination(): void
    {
        $sender = User::factory()->create(['office' => 'Administrative Unit', 'permissions' => ['platform.index' => true]]);
        $document = $this->forwardedDocument();

        $page = $this->actingAs($sender)->get(route('platform.documents.workspace', $document))->assertOk();
        preg_match("/data-route-correction='([^']+)'/", $page->getContent(), $match);
        $details = json_decode(html_entity_decode($match[1] ?? ''), true);
        $this->assertSame([['source' => 'Administrative Unit', 'destination' => 'SDO-Budget Unit']], $details['routes']);
        $this->assertSame('workspace-route-correction-offices', $details['list']);
        $page->assertSee('<datalist id="workspace-route-correction-offices">', false);

        $document->officeAssignments()->where('office', 'SDO-Budget Unit')->update(['status' => DocumentMovement::STATUS_RECEIVED]);
        $this->get(route('platform.documents.workspace', $document))->assertOk()->assertDontSee('data-route-correction', false);
    }

    private function forwardedDocument(): Document
    {
        $document = Document::factory()->outgoing()->create(['current_office' => 'SDO-Budget Unit']);
        $movement = $document->movements()->create([
            'action' => DocumentMovement::ACTION_FORWARDED,
            'from_office' => 'Administrative Unit',
            'to_office' => 'SDO-Budget Unit',
            'from_status' => DocumentMovement::STATUS_RECEIVED,
            'to_status' => DocumentMovement::STATUS_FORWARDED,
            'remarks' => 'Review this document.',
        ]);
        $document->officeAssignments()->where('office', 'SDO-Budget Unit')->update(['movement_id' => $movement->id]);

        return $document;
    }

    private function registeredDocument(User $registrar): Document
    {
        $document = Document::factory()->create([
            'current_office' => 'SDO-Accounting Unit',
            'status' => DocumentMovement::STATUS_AWAITING_RECEIPT,
            'registered_by' => $registrar->id,
        ]);
        $sent = $document->movements()->create([
            'action' => DocumentMovement::ACTION_SENT,
            'from_office' => null,
            'to_office' => 'SDO-Accounting Unit',
            'from_status' => null,
            'to_status' => DocumentMovement::STATUS_AWAITING_RECEIPT,
            'acted_by' => $registrar->id,
        ]);
        $document->officeAssignments()->where('office', 'SDO-Accounting Unit')->update(['movement_id' => $sent->id]);

        return $document;
    }
}
