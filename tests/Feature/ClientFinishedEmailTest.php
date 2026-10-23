<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\DocumentMovement;
use App\Models\User;
use App\Notifications\DocumentFinishedClientNotification;
use App\Support\EmailLink;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class ClientFinishedEmailTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('mail.document_assignment_notifications_enabled', true);
        config()->set('queue.default', 'sync');
        Notification::fake();
    }

    public function test_the_client_is_emailed_when_the_document_is_released(): void
    {
        $records = User::factory()->create(['office' => 'SDO-Records Unit', 'permissions' => ['platform.index' => true]]);
        $document = Document::factory()->incoming()->create(['current_office' => 'SDO-Records Unit', 'client_email' => 'client@gmail.com']);
        $this->actingAs($records)->postJson(route('platform.documents.movements.store', $document), [
            'workflow' => ['action' => 'received', 'source_office' => 'SDO-Records Unit'],
        ])->assertOk();

        $this->postJson(route('platform.documents.movements.store', $document), [
            'workflow' => ['action' => 'released', 'source_office' => 'SDO-Records Unit', 'released_to' => 'Juan Dela Cruz'],
        ])->assertOk();

        Notification::assertSentOnDemand(
            DocumentFinishedClientNotification::class,
            fn (DocumentFinishedClientNotification $notification, array $channels, AnonymousNotifiable $notifiable): bool => $notifiable->routes['mail'] === 'client@gmail.com'
                && $notification->kind === DocumentFinishedClientNotification::RELEASED
                && $notification->office === 'SDO-Records Unit',
        );
        Notification::assertSentOnDemandTimes(DocumentFinishedClientNotification::class, 1);
    }

    public function test_the_client_is_emailed_once_when_the_last_office_completes(): void
    {
        $budget = User::factory()->create(['office' => 'SDO-Budget Unit', 'permissions' => ['platform.index' => true]]);
        $legal = User::factory()->create(['office' => 'SDO-Legal Unit', 'permissions' => ['platform.index' => true]]);
        $document = Document::factory()->create(['current_office' => 'SDO-Budget Unit', 'status' => DocumentMovement::STATUS_RECEIVED, 'client_email' => 'client@gmail.com']);
        foreach (['SDO-Budget Unit', 'SDO-Legal Unit'] as $office) {
            $receipt = $document->movements()->create([
                'action' => DocumentMovement::ACTION_RECEIVED, 'from_office' => $office, 'to_office' => $office,
                'from_status' => DocumentMovement::STATUS_FORWARDED, 'to_status' => DocumentMovement::STATUS_RECEIVED,
            ]);
            $document->officeAssignments()->updateOrCreate(['office' => $office], ['status' => DocumentMovement::STATUS_RECEIVED, 'movement_id' => $receipt->id]);
        }

        // Budget finishes first: Legal is still working, so the client is not told yet.
        $this->actingAs($budget)->postJson(route('platform.documents.movements.store', $document), [
            'workflow' => ['action' => 'completed', 'source_office' => 'SDO-Budget Unit'],
        ])->assertOk();
        Notification::assertSentOnDemandTimes(DocumentFinishedClientNotification::class, 0);

        $this->actingAs($legal)->postJson(route('platform.documents.movements.store', $document), [
            'workflow' => ['action' => 'completed', 'source_office' => 'SDO-Legal Unit'],
        ])->assertOk();

        Notification::assertSentOnDemand(
            DocumentFinishedClientNotification::class,
            fn (DocumentFinishedClientNotification $notification): bool => $notification->kind === DocumentFinishedClientNotification::COMPLETED
                && $notification->office === 'SDO-Legal Unit',
        );
        Notification::assertSentOnDemandTimes(DocumentFinishedClientNotification::class, 1);
    }

    public function test_no_finished_email_without_an_address(): void
    {
        $records = User::factory()->create(['office' => 'SDO-Records Unit', 'permissions' => ['platform.index' => true]]);
        $document = Document::factory()->incoming()->create(['current_office' => 'SDO-Records Unit']);
        $this->actingAs($records)->postJson(route('platform.documents.movements.store', $document), [
            'workflow' => ['action' => 'received', 'source_office' => 'SDO-Records Unit'],
        ])->assertOk();
        $this->postJson(route('platform.documents.movements.store', $document), [
            'workflow' => ['action' => 'completed', 'source_office' => 'SDO-Records Unit'],
        ])->assertOk();

        Notification::assertSentOnDemandTimes(DocumentFinishedClientNotification::class, 0);
    }

    public function test_finished_emails_say_what_happened(): void
    {
        $released = (new DocumentFinishedClientNotification('SDONV12345678912', DocumentFinishedClientNotification::RELEASED, 'SDO-Records Unit'))
            ->toMail(new AnonymousNotifiable);
        $this->assertSame('Your document SDONV12345678912 was released', $released->subject);
        $this->assertContains('Your document has been released by the SDO-Records Unit.', $released->introLines);
        $this->assertSame(EmailLink::route('tracking.index', ['tracking' => 'SDONV12345678912']), $released->actionUrl);
        $this->assertStringContainsString('Report not spam', implode(' ', $released->outroLines));
        $this->assertStringContainsString('Deped SDO Records Unit', (string) $released->salutation);

        $completed = (new DocumentFinishedClientNotification('SDONV12345678912', DocumentFinishedClientNotification::COMPLETED, 'SDO-Legal Unit'))
            ->toMail(new AnonymousNotifiable);
        $this->assertSame('Your document SDONV12345678912 is complete', $completed->subject);
        $this->assertContains('All offices have finished processing your document. The last office to finish was the SDO-Legal Unit.', $completed->introLines);
    }
}
