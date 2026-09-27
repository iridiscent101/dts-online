<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\User;
use App\Notifications\DocumentAssignedNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class DocumentNotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_forwarding_notifies_users_in_the_destination_office(): void
    {
        Notification::fake();
        $actor = User::factory()->create([
            'office' => 'Records Unit',
            'permissions' => ['platform.index' => true],
        ]);
        $recipient = User::factory()->create(['office' => 'Administrative Unit']);
        $secondRecipient = User::factory()->create(['office' => 'Administrative Unit']);
        $unrelatedUser = User::factory()->create(['office' => 'Curriculum Implementation Division']);
        $document = Document::factory()->received()->create(['current_office' => 'Records Unit']);

        $this->actingAs($actor)
            ->post(route('platform.documents.movements.store', $document), [
                'workflow' => [
                    'action' => 'forward',
                    'destination' => 'Administrative Unit',
                    'remarks' => 'For administrative review.',
                ],
            ])
            ->assertRedirectToRoute('platform.documents.view', $document);

        Notification::assertSentTo(
            [$recipient, $secondRecipient],
            DocumentAssignedNotification::class,
            fn (DocumentAssignedNotification $notification): bool => $notification->action === 'forward'
                && $notification->document->is($document)
                && $notification->actorName === $actor->name,
        );
        Notification::assertNotSentTo([$actor, $unrelatedUser], DocumentAssignedNotification::class);
    }

    public function test_acknowledging_a_document_does_not_send_an_assignment_notification(): void
    {
        Notification::fake();
        $user = User::factory()->create([
            'office' => 'Records Unit',
            'permissions' => ['platform.index' => true],
        ]);
        $document = Document::factory()->incoming()->create(['current_office' => 'Records Unit']);

        $this->actingAs($user)
            ->post(route('platform.documents.movements.store', $document), [
                'workflow' => [
                    'action' => 'acknowledge',
                    'remarks' => 'Received by the records desk.',
                ],
            ])
            ->assertRedirectToRoute('platform.documents.view', $document);

        Notification::assertNothingSent();
    }

    public function test_opening_an_owned_notification_marks_it_read_and_opens_the_document(): void
    {
        $recipient = User::factory()->create([
            'office' => 'Records Unit',
            'permissions' => ['platform.index' => true],
        ]);
        $otherUser = User::factory()->create([
            'office' => 'Administrative Unit',
            'permissions' => ['platform.index' => true],
        ]);
        $document = Document::factory()->create(['current_office' => 'Records Unit']);
        $recipient->notify(new DocumentAssignedNotification($document, 'forward', 'Records Officer'));
        $notification = $recipient->unreadNotifications()->firstOrFail();

        $this->actingAs($recipient)
            ->get(route('platform.notifications.open', $notification->id))
            ->assertRedirectToRoute('platform.documents.view', $document);

        $this->assertNotNull($notification->fresh()->read_at);

        $this->actingAs($otherUser)
            ->get(route('platform.notifications.open', $notification->id))
            ->assertNotFound();
    }

    public function test_dashboard_shows_the_assigned_office_queue_and_unread_notifications(): void
    {
        $user = User::factory()->create([
            'office' => 'Records Unit',
            'permissions' => ['platform.index' => true],
        ]);
        $overdueDocument = Document::factory()->received()->create([
            'tracking_number' => 'DTS-OFFICE-OVERDUE',
            'current_office' => 'Records Unit',
            'due_at' => today()->subDay(),
        ]);
        Document::factory()->outgoing()->create([
            'tracking_number' => 'DTS-OFFICE-FORWARDED',
            'current_office' => 'Records Unit',
            'due_at' => today()->addDay(),
        ]);
        Document::factory()->incoming()->create([
            'tracking_number' => 'DTS-OTHER-OFFICE',
            'current_office' => 'Administrative Unit',
        ]);
        Document::factory()->archived()->create([
            'tracking_number' => 'DTS-OFFICE-ARCHIVED',
            'current_office' => 'Records Unit',
        ]);
        $user->notify(new DocumentAssignedNotification($overdueDocument, 'forward', 'Administrative Officer'));

        $response = $this->actingAs($user)->get(route('platform.main'));

        $response->assertOk()
            ->assertSee('My office queue')
            ->assertSee('Records Unit')
            ->assertSee('2 actions')
            ->assertSee('1 overdue')
            ->assertSee('DTS-OFFICE-OVERDUE')
            ->assertSee('DTS-OFFICE-FORWARDED')
            ->assertSee('1 unread')
            ->assertSee('DTS-OFFICE-OVERDUE was forwarded to your office by Administrative Officer.');
    }

    public function test_opening_a_notification_requires_sign_in(): void
    {
        $user = User::factory()->create();
        $document = Document::factory()->create();
        $user->notify(new DocumentAssignedNotification($document, 'forward', 'Records Officer'));
        $notification = $user->unreadNotifications()->firstOrFail();

        $this->get(route('platform.notifications.open', $notification->id))
            ->assertRedirectToRoute('platform.login');
    }
}
