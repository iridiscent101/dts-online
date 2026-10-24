<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\Office;
use App\Models\User;
use App\Notifications\DocumentAssignedNotification;
use App\Notifications\DocumentChangedNotification;
use App\Notifications\DocumentNotificationDispatcher;
use App\Notifications\DocumentRoutedClientNotification;
use App\Support\EmailLink;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Orchid\Platform\Models\Role;
use Orchid\Platform\Notifications\DashboardMessage;
use Tests\TestCase;

class DocumentNotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_failed_routing_notification_rolls_back_and_retry_creates_one_action(): void
    {
        $actor = User::factory()->create([
            'office' => 'Records Unit',
            'permissions' => ['platform.index' => true],
        ]);
        User::factory()->create(['office' => 'Administrative Unit']);
        $document = Document::factory()->received()->create(['current_office' => 'Records Unit']);
        $workflow = ['workflow' => [
            'action' => 'forward',
            'destination' => 'Administrative Unit',
            'remarks' => 'For administrative review.',
        ]];
        $fail = true;
        Event::listen(NotificationSent::class, function () use (&$fail): void {
            if ($fail) {
                throw new \RuntimeException('Notification delivery failed.');
            }
        });

        $this->actingAs($actor)
            ->post(route('platform.documents.movements.store', $document), $workflow)
            ->assertStatus(500);

        $this->assertSame('Received', $document->fresh()->status);
        $this->assertDatabaseCount('document_movements', 0);
        $this->assertDatabaseMissing('document_office_assignments', [
            'document_id' => $document->id,
            'office' => 'Administrative Unit',
        ]);
        $this->assertDatabaseCount('notifications', 0);

        $fail = false;
        $this->post(route('platform.documents.movements.store', $document), $workflow)
            ->assertRedirectToRoute('platform.main');

        $this->assertSame('Forwarded', $document->fresh()->status);
        $this->assertDatabaseCount('document_movements', 1);
        $this->assertDatabaseCount('notifications', 1);
    }

    public function test_failed_registration_notification_rolls_back_and_retry_creates_one_document(): void
    {
        $actor = User::factory()->create([
            'office' => 'Records Unit',
            'permissions' => ['platform.index' => true],
        ]);
        User::factory()->create(['office' => 'Administrative Unit']);
        $payload = ['document' => [
            'title' => 'Division records request',
            'type' => 'Request',
            'origin' => 'Regional Office',
            'sender' => 'Records Officer',
            'received' => '2026-09-28',
            'destinations' => ['Administrative Unit', 'SDO-Budget Unit', 'SDO-Accounting Unit'],
            'priority' => 'Standard',
        ]];
        $fail = true;
        Event::listen(NotificationSent::class, function () use (&$fail): void {
            if ($fail) {
                throw new \RuntimeException('Notification delivery failed.');
            }
        });

        $this->actingAs($actor)
            ->from(route('platform.documents.create'))
            ->post(route('platform.documents.create').'/save', $payload)
            ->assertRedirect(route('platform.documents.create'));

        $this->assertDatabaseCount('documents', 0);
        $this->assertDatabaseCount('document_movements', 0);
        $this->assertDatabaseCount('document_office_assignments', 0);
        $this->assertDatabaseCount('notifications', 0);

        $fail = false;
        $this->post(route('platform.documents.create').'/save', $payload)
            ->assertRedirectToRoute('platform.documents');

        $this->assertDatabaseCount('documents', 1);
        $this->assertDatabaseCount('document_movements', 3);
        $this->assertDatabaseCount('notifications', 1);
    }

    public function test_failed_edit_notification_rolls_back_document_and_audit_entry(): void
    {
        $actor = User::factory()->create([
            'office' => 'Records Unit',
            'permissions' => ['platform.index' => true, 'platform.documents.edit' => true],
        ]);
        $document = Document::factory()->received()->create([
            'subject' => 'Original subject',
            'current_office' => 'Records Unit',
        ]);
        Event::listen(NotificationSent::class, function (): void {
            throw new \RuntimeException('Notification delivery failed.');
        });

        $this->actingAs($actor)
            ->post(route('platform.documents.edit', $document).'/save', [
                'details_only' => true,
                'document' => [
                    'details_fingerprint' => $document->detailsFingerprint(),
                    'title' => 'Updated subject',
                    'type' => $document->document_type,
                    'origin' => $document->origin,
                    'sender' => $document->sender,
                    'received' => $document->received_at->toDateString(),
                    'priority' => $document->priority,
                ],
            ])
            ->assertStatus(500);

        $this->assertSame('Original subject', $document->fresh()->subject);
        $this->assertDatabaseCount('activity_log', 0);
        $this->assertDatabaseCount('notifications', 0);
    }

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
            ->assertRedirectToRoute('platform.main');

        Notification::assertSentTo(
            [$recipient, $secondRecipient],
            DocumentAssignedNotification::class,
            fn (DocumentAssignedNotification $notification): bool => $notification->action === 'forwarded'
                && $notification->document->is($document)
                && $notification->actorName === $actor->name,
        );
        Notification::assertNotSentTo([$actor, $unrelatedUser], DocumentAssignedNotification::class);
    }

    public function test_forwarding_emails_the_client_and_never_staff(): void
    {
        config()->set('mail.document_assignment_notifications_enabled', true);
        Notification::fake();
        $actor = User::factory()->create(['office' => 'Records Unit', 'permissions' => ['platform.index' => true]]);
        $staff = User::factory()->create([
            'office' => 'Administrative Unit',
            'notification_email' => 'staff@gmail.com',
            'notification_email_verified_at' => now(),
        ]);
        $document = Document::factory()->received()->create(['current_office' => 'Records Unit', 'client_email' => 'client@gmail.com']);

        $this->actingAs($actor)
            ->post(route('platform.documents.movements.store', $document), [
                'workflow' => ['action' => 'forward', 'destination' => 'Administrative Unit'],
            ])
            ->assertRedirectToRoute('platform.main');

        Notification::assertSentTo($staff, DocumentAssignedNotification::class);
        Notification::assertSentOnDemand(
            DocumentRoutedClientNotification::class,
            fn (DocumentRoutedClientNotification $notification, array $channels, object $notifiable): bool => $notifiable->routes['mail'] === 'client@gmail.com'
                && $notification->offices === ['Administrative Unit'],
        );
        Notification::assertSentOnDemandTimes(DocumentRoutedClientNotification::class, 1);
    }

    public function test_sent_emails_carry_the_seal_the_green_button_and_the_records_unit_sign_off(): void
    {
        config()->set('mail.default', 'array');

        Notification::route('mail', 'client@gmail.com')
            ->notifyNow(new DocumentRoutedClientNotification('SDONV12345678912', ['SDO-Budget Unit']));

        $email = app('mailer')->getSymfonyTransport()->messages()->sole()->getOriginalMessage();
        $html = $email->getHtmlBody();
        $this->assertCount(1, $email->getAttachments());
        $this->assertStringContainsString('src="cid:', $html);
        $this->assertStringContainsString('background-color: #216e39', $html);
        $this->assertStringContainsString('Regards,<br>Deped SDO Records Unit', $html);
        $this->assertSame('Deped Nueva Vizcaya Records Unit', $email->getFrom()[0]->getName());
    }

    public function test_client_email_is_sent_without_a_queue_worker(): void
    {
        config()->set('mail.document_assignment_notifications_enabled', true);
        config()->set('queue.default', 'database');
        config()->set('mail.default', 'array');
        $actor = User::factory()->create(['office' => 'Records Unit', 'permissions' => ['platform.index' => true]]);
        $document = Document::factory()->received()->create(['current_office' => 'Records Unit', 'client_email' => 'client@gmail.com']);

        $this->actingAs($actor)->post(route('platform.documents.movements.store', $document), [
            'workflow' => ['action' => 'forward', 'destination' => 'Administrative Unit'],
        ])->assertRedirect();

        // Nothing waits in the jobs table; the email went out once the page answered.
        $this->assertDatabaseCount('jobs', 0);
        $sent = app('mailer')->getSymfonyTransport()->messages();
        $this->assertCount(1, $sent);
        $this->assertSame('client@gmail.com', $sent->first()->getEnvelope()->getRecipients()[0]->getAddress());
    }

    public function test_no_client_email_is_sent_without_an_address_or_while_mail_is_off(): void
    {
        Notification::fake();
        $actor = User::factory()->create(['office' => 'Records Unit']);
        $withoutAddress = Document::factory()->received()->create();
        $withAddress = Document::factory()->received()->create(['client_email' => 'client@gmail.com']);

        config()->set('mail.document_assignment_notifications_enabled', true);
        app(DocumentNotificationDispatcher::class)->assigned($withoutAddress, 'forward', $actor, ['Administrative Unit']);

        config()->set('mail.document_assignment_notifications_enabled', false);
        app(DocumentNotificationDispatcher::class)->assigned($withAddress, 'forward', $actor, ['Administrative Unit']);

        Notification::assertNothingSentTo(new AnonymousNotifiable);
        Notification::assertSentOnDemandTimes(DocumentRoutedClientNotification::class, 0);
    }

    public function test_client_submission_with_an_email_is_told_it_reached_the_records_unit(): void
    {
        config()->set('mail.document_assignment_notifications_enabled', true);
        Notification::fake();

        $this->post(route('submission.store'), [
            'submission_key' => (string) Str::uuid(),
            'name' => 'Juan Dela Cruz',
            'office' => 'Bayombong Central School',
            'email' => ' Juan@Gmail.com ',
            'delivery_method' => Document::DELIVERY_METHODS[0],
            'title' => 'Leave application',
            'type' => Document::typeOptions()[0],
            'privacy_consent' => '1',
        ])->assertSessionHasNoErrors();

        $this->assertSame('juan@gmail.com', Document::query()->sole()->client_email);
        Notification::assertSentOnDemand(
            DocumentRoutedClientNotification::class,
            fn (DocumentRoutedClientNotification $notification, array $channels, object $notifiable): bool => $notifiable->routes['mail'] === 'juan@gmail.com'
                && $notification->offices === ['SDO-Records Unit'],
        );
    }

    public function test_client_routing_email_names_the_office_sender_and_spam_folder(): void
    {
        $message = (new DocumentRoutedClientNotification('SDONV12345678912', ['SDO-Budget Unit', 'SDO-Legal Unit']))
            ->toMail(new AnonymousNotifiable);
        $html = (string) $message->render();

        $this->assertSame('Your document SDONV12345678912 was routed', $message->subject);
        $this->assertContains('Your document has been routed to the SDO-Budget Unit and SDO-Legal Unit.', $message->introLines);
        $this->assertContains(DocumentRoutedClientNotification::SPAM_NOTE, $message->outroLines);
        $this->assertSame(EmailLink::route('tracking.index', ['tracking' => 'SDONV12345678912']), $message->actionUrl);
        $this->assertStringContainsString('Regards,<br>Deped SDO Records Unit', $html);
        $this->assertStringContainsString('Deped Nueva Vizcaya Records Unit', $html);
        $this->assertStringNotContainsString('DocuTrackS', $html);
        $this->assertSame('Deped Nueva Vizcaya Records Unit', config('mail.from.name'));
    }

    public function test_staff_can_set_and_clear_an_optional_notification_email_without_changing_login(): void
    {
        Notification::fake();
        $user = User::factory()->create(['permissions' => ['platform.index' => true]]);
        $loginEmail = $user->email;

        $this->actingAs($user)->post(route('platform.profile').'/save', [
            'user' => ['name' => $user->name, 'email' => $loginEmail, 'notification_email' => 'alerts@gmail.com'],
        ])->assertSessionHasNoErrors();

        $this->assertSame($loginEmail, $user->fresh()->email);
        $this->assertSame('alerts@gmail.com', $user->fresh()->notification_email);

        $this->post(route('platform.profile').'/save', [
            'user' => ['name' => $user->name, 'email' => $loginEmail, 'notification_email' => ''],
        ])->assertSessionHasNoErrors();

        $this->assertNull($user->fresh()->notification_email);
        // There is no confirmation email: the address works as soon as it is saved.
        Notification::assertNothingSent();
    }

    public function test_profile_shows_the_email_alert_toggle_on_only_when_an_address_is_saved(): void
    {
        $user = User::factory()->create(['permissions' => ['platform.index' => true]]);

        $html = $this->actingAs($user)->get(route('platform.profile'))
            ->assertOk()
            ->assertSee('Add a recovery email for forgotten passwords')
            ->assertSee('Recovery email (Gmail)')
            ->assertSee('Report not spam')
            ->getContent();
        $this->assertDoesNotMatchRegularExpression('/data-email-alerts-toggle[^>]*checked/', $html);

        $user->forceFill(['notification_email' => 'alerts@gmail.com'])->save();

        $html = $this->get(route('platform.profile'))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/data-email-alerts-toggle[^>]*checked/', $html);
        $this->assertStringContainsString('value="alerts@gmail.com"', $html);
        $this->assertStringContainsString('there is no confirmation email', $html);
        $this->assertStringNotContainsString('Check your inbox to confirm this address', $html);
    }

    public function test_notification_email_must_be_a_valid_address(): void
    {
        $user = User::factory()->create(['permissions' => ['platform.index' => true]]);

        $this->actingAs($user)->post(route('platform.profile').'/save', [
            'user' => ['name' => $user->name, 'email' => $user->email, 'notification_email' => 'not-an-email'],
        ])->assertSessionHasErrors('user.notification_email');

        $this->assertNull($user->fresh()->notification_email);
    }

    public function test_administrator_can_set_a_staff_notification_email_without_changing_the_login(): void
    {
        $role = Role::create(['name' => 'Admin', 'slug' => User::ADMIN_ROLE_SLUG, 'permissions' => []]);
        $administrator = User::factory()->create();
        $administrator->replaceRoles([$role->id]);
        $staff = User::factory()->create();

        $this->actingAs($administrator)->post(route('platform.systems.users.edit', $staff).'/save', [
            'user' => ['name' => $staff->name, 'email' => $staff->email, 'notification_email' => 'staff@gmail.com'],
        ])->assertRedirectToRoute('platform.systems.users');

        $this->assertSame($staff->email, $staff->fresh()->email);
        $this->assertSame('staff@gmail.com', $staff->fresh()->notification_email);
    }

    public function test_forwarding_notifies_users_assigned_to_the_destination_office(): void
    {
        Notification::fake();
        $actor = User::factory()->create([
            'office' => 'Records Unit',
            'permissions' => ['platform.index' => true],
        ]);
        $administrativeUnit = Office::query()->updateOrCreate(['name' => 'Administrative Unit'], [
            'is_sdo' => true,
            'is_active' => true,
        ]);
        $recipient = User::factory()->create(['office' => null]);
        $recipient->offices()->sync([$administrativeUnit->id]);
        $unrelatedUser = User::factory()->create(['office' => null]);
        $document = Document::factory()->received()->create(['current_office' => 'Records Unit']);

        $this->actingAs($actor)
            ->post(route('platform.documents.movements.store', $document), [
                'workflow' => [
                    'action' => 'forward',
                    'destination' => 'Administrative Unit',
                    'remarks' => 'For administrative review.',
                ],
            ])
            ->assertRedirectToRoute('platform.main');

        Notification::assertSentTo(
            $recipient,
            DocumentAssignedNotification::class,
            fn (DocumentAssignedNotification $notification): bool => $notification->action === 'forwarded'
                && $notification->document->is($document)
                && $notification->actorName === $actor->name,
        );
        Notification::assertNotSentTo($unrelatedUser, DocumentAssignedNotification::class);
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

    public function test_registering_a_document_notifies_other_users_in_the_destination_office(): void
    {
        Notification::fake();
        $actor = User::factory()->create([
            'office' => 'Records Unit',
            'permissions' => ['platform.index' => true],
        ]);
        $recipient = User::factory()->create(['office' => 'Administrative Unit']);
        $unrelatedUser = User::factory()->create(['office' => 'Curriculum Implementation Division']);

        $this->actingAs($actor)->post(route('platform.documents.create').'/save', [
            'document' => [
                'title' => 'Division records request',
                'type' => 'Request',
                'origin' => 'Regional Office',
                'sender' => 'Records Officer',
                'received' => '2026-09-28',
                'destinations' => ['Administrative Unit', 'SDO-Budget Unit', 'SDO-Accounting Unit'],
                'priority' => 'Standard',
                'remarks' => 'For administrative action.',
            ],
        ])->assertRedirectToRoute('platform.documents');

        $document = Document::query()->sole();

        Notification::assertSentTo(
            $recipient,
            DocumentAssignedNotification::class,
            fn (DocumentAssignedNotification $notification): bool => $notification->action === 'register'
                && $notification->document->is($document)
                && $notification->actorName === $actor->name,
        );
        Notification::assertNotSentTo($unrelatedUser, DocumentAssignedNotification::class);
    }

    public function test_registering_for_multiple_offices_notifies_each_office(): void
    {
        Notification::fake();
        $registrar = User::factory()->create([
            'office' => 'Records Unit',
            'permissions' => ['platform.index' => true],
        ]);
        $administrativeUser = User::factory()->create(['office' => 'Administrative Unit']);
        $curriculumUser = User::factory()->create(['office' => 'Curriculum Implementation Division']);

        $this->actingAs($registrar)->post(route('platform.documents.create').'/save', [
            'document' => [
                'title' => 'Parallel office review',
                'type' => 'Request',
                'origin' => 'Regional Office',
                'sender' => 'Records Officer',
                'received' => '2026-09-28',
                'destinations' => ['Administrative Unit', 'SDO-Budget Unit', 'Curriculum Implementation Division'],
                'route_remarks' => ['Review records.', '', 'Review content.'],
                'priority' => 'Standard',
            ],
        ])->assertRedirectToRoute('platform.documents');

        Notification::assertSentTo([$administrativeUser, $curriculumUser], DocumentAssignedNotification::class);
        Notification::assertNotSentTo($registrar, DocumentAssignedNotification::class);
    }

    public function test_updating_document_details_notifies_the_actor_office_users_and_administrators(): void
    {
        Notification::fake();
        $actor = User::factory()->create([
            'office' => 'Records Unit',
            'permissions' => [
                'platform.index' => true,
                'platform.documents.edit' => true,
            ],
        ]);
        $recipient = User::factory()->create(['office' => 'Records Unit']);
        $adminRole = Role::create([
            'name' => 'Admin',
            'slug' => User::ADMIN_ROLE_SLUG,
            'permissions' => [],
        ]);
        $administrator = User::factory()->create(['office' => null]);
        $administrator->replaceRoles([$adminRole->id]);
        $unrelatedUser = User::factory()->create(['office' => 'Administrative Unit']);
        $document = Document::factory()->create([
            'current_office' => 'Records Unit',
            'subject' => 'Original subject',
        ]);

        $this->actingAs($actor)->post(route('platform.documents.edit', $document).'/save', [
            'document' => [
                'details_fingerprint' => $document->detailsFingerprint(),
                'title' => 'Corrected subject',
                'type' => $document->document_type,
                'origin' => $document->origin,
                'sender' => $document->sender,
                'received' => $document->received_at->toDateString(),
                'priority' => $document->priority,
            ],
        ])->assertRedirectToRoute('platform.documents.view', $document);

        Notification::assertSentTo(
            [$actor, $recipient, $administrator],
            DocumentChangedNotification::class,
            fn (DocumentChangedNotification $notification): bool => $notification->change === 'details'
                && $notification->document->is($document),
        );
        Notification::assertNotSentTo($unrelatedUser, DocumentChangedNotification::class);
    }

    public function test_document_notifications_are_visible_in_the_orchid_notification_feed(): void
    {
        $recipient = User::factory()->create([
            'office' => 'Records Unit',
            'permissions' => ['platform.index' => true],
        ]);
        $document = Document::factory()->create([
            'tracking_number' => 'DTS-BELL-001',
            'current_office' => 'Records Unit',
        ]);

        $recipient->notify(new DocumentChangedNotification($document, 'details', 'Records Officer'));

        $notification = $recipient->unreadNotifications()->sole();
        $this->assertSame(DashboardMessage::class, $notification->type);
        $this->assertSame('DTS-BELL-001', $notification->data['title']);
        $this->assertSame('details', $notification->data['event']);
        $this->assertSame(route('platform.notifications.open', $notification->id), $notification->data['action']);

        $this->actingAs($recipient)
            ->get(route('platform.notifications'))
            ->assertOk()
            ->assertSee('DTS-BELL-001')
            ->assertSee('details were updated by Records Officer.');
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

    public function test_dashboard_shows_the_office_queue_and_leaves_notifications_to_the_bell(): void
    {
        $user = User::factory()->create([
            'office' => 'Records Unit',
            'permissions' => ['platform.index' => true],
        ]);
        $receivedDocument = Document::factory()->received()->create([
            'tracking_number' => 'DTS-OFFICE-RECEIVED',
            'current_office' => 'Records Unit',
        ]);
        Document::factory()->outgoing()->create([
            'tracking_number' => 'DTS-OFFICE-FORWARDED',
            'current_office' => 'Records Unit',
        ]);
        Document::factory()->incoming()->create([
            'tracking_number' => 'DTS-OTHER-OFFICE',
            'current_office' => 'Administrative Unit',
        ]);
        Document::factory()->archived()->create([
            'tracking_number' => 'DTS-OFFICE-ARCHIVED',
            'current_office' => 'Records Unit',
        ]);
        $user->notify(new DocumentAssignedNotification($receivedDocument, 'forward', 'Administrative Officer'));

        $response = $this->actingAs($user)->get(route('platform.main'));

        $response->assertOk()
            ->assertSee('<h3 id="office-queue-title">Records Unit</h3>', false)
            ->assertSee('Documents waiting for your office')
            ->assertSee('Records Unit')
            ->assertSee('<strong>2</strong> actions', false)
            ->assertSee('DTS-OFFICE-RECEIVED')
            ->assertSee('DTS-OFFICE-FORWARDED')
            ->assertSee('data-dts-notification-count="1"', false)
            ->assertSee('Recent activity')
            // Notifications are in the top-bar bell, not repeated on the dashboard.
            ->assertDontSee('Mark all notifications as read')
            ->assertDontSee('DTS-OFFICE-RECEIVED was forwarded to your office by Administrative Officer.');
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

    public function test_user_can_mark_all_of_their_notifications_as_read(): void
    {
        $user = User::factory()->create(['permissions' => ['platform.index' => true]]);
        $otherUser = User::factory()->create();
        $document = Document::factory()->create();
        $user->notify(new DocumentAssignedNotification($document, 'forward', 'Records Officer'));
        $user->notify(new DocumentChangedNotification($document, 'details', 'Records Officer'));
        $otherUser->notify(new DocumentAssignedNotification($document, 'forward', 'Records Officer'));

        $this->actingAs($user)
            ->from(route('platform.main'))
            ->post(route('platform.notifications.read-all'))
            ->assertRedirectToRoute('platform.main');

        $this->assertSame(0, $user->unreadNotifications()->count());
        $this->assertSame(1, $otherUser->unreadNotifications()->count());
    }

    public function test_notification_pruning_deletes_only_items_read_more_than_ninety_days_ago(): void
    {
        $this->travelTo(Carbon::parse('2026-10-13 12:00:00'));
        $document = Document::factory()->create();
        $oldReadUser = User::factory()->create();
        $recentReadUser = User::factory()->create();
        $oldUnreadUser = User::factory()->create();

        foreach ([$oldReadUser, $recentReadUser, $oldUnreadUser] as $user) {
            $user->notify(new DocumentAssignedNotification($document, 'forward', 'Records Officer'));
        }

        $oldRead = $oldReadUser->notifications()->sole();
        $recentRead = $recentReadUser->notifications()->sole();
        $oldUnread = $oldUnreadUser->notifications()->sole();
        $oldRead->update(['read_at' => now()->subDays(91)]);
        $recentRead->update(['read_at' => now()->subDays(30)]);
        $oldUnread->update(['created_at' => now()->subDays(120)]);

        $this->artisan('notifications:prune-read')
            ->expectsOutput('Deleted 1 expired read notification(s).')
            ->assertSuccessful();

        $this->assertDatabaseMissing('notifications', ['id' => $oldRead->id]);
        $this->assertDatabaseHas('notifications', ['id' => $recentRead->id]);
        $this->assertDatabaseHas('notifications', ['id' => $oldUnread->id, 'read_at' => null]);
    }
}
