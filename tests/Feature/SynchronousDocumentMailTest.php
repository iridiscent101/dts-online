<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\User;
use App\Notifications\DocumentNotificationDispatcher;
use App\Notifications\DocumentRoutedClientNotification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class SynchronousDocumentMailTest extends TestCase
{
    use DatabaseTruncation;

    protected function setUp(): void
    {
        RefreshDatabaseState::$migrated = false;
        parent::setUp();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        RefreshDatabaseState::$migrated = false;
    }

    public function test_client_mail_sends_after_commit_but_not_after_rollback(): void
    {
        // Deferred work runs straight away here; in a request it runs after the response.
        $this->withoutDefer();
        config()->set('mail.document_assignment_notifications_enabled', true);
        Notification::fake();
        $actor = User::factory()->create(['office' => 'Records Unit']);
        $document = Document::factory()->received()->create(['client_email' => 'client@gmail.com']);

        DB::beginTransaction();
        app(DocumentNotificationDispatcher::class)->assigned($document, 'forward', $actor, ['Administrative Unit']);
        Notification::assertSentOnDemandTimes(DocumentRoutedClientNotification::class, 0);
        DB::rollBack();
        Notification::assertSentOnDemandTimes(DocumentRoutedClientNotification::class, 0);

        DB::transaction(fn () => app(DocumentNotificationDispatcher::class)
            ->assigned($document, 'forward', $actor, ['Administrative Unit']));

        Notification::assertSentOnDemandTimes(DocumentRoutedClientNotification::class, 1);

        // A mail failure after the commit never undoes the routing.
        Notification::shouldReceive('send')->andReturnNull();
        Notification::shouldReceive('route')->andThrow(new \RuntimeException('SMTP unavailable'));

        $committed = DB::transaction(function () use ($document, $actor): bool {
            app(DocumentNotificationDispatcher::class)->assigned($document, 'forward', $actor, ['Administrative Unit']);

            return true;
        });

        $this->assertTrue($committed);
    }

    public function test_vercel_sends_before_the_response_and_other_servers_queue_it_with_retries(): void
    {
        config()->set('mail.document_assignment_notifications_enabled', true);
        $actor = User::factory()->create(['office' => 'Records Unit']);
        $document = Document::factory()->received()->create(['client_email' => 'client@gmail.com']);

        // A server with a queue worker hands the email to the queue, which retries it if the mail server fails.
        Notification::fake();
        config()->set('queue.default', 'database');
        app(DocumentNotificationDispatcher::class)->assigned($document, 'forward', $actor, ['Administrative Unit']);
        Notification::assertSentOnDemandTimes(DocumentRoutedClientNotification::class, 1);
        $this->assertInstanceOf(ShouldQueue::class, new DocumentRoutedClientNotification($document->tracking_number, ['Administrative Unit']));

        // Vercel (sync queue) sends it straight away, before its function can be frozen.
        Notification::fake();
        config()->set('queue.default', 'sync');
        app(DocumentNotificationDispatcher::class)->assigned($document, 'forward', $actor, ['Administrative Unit']);
        Notification::assertSentOnDemandTimes(DocumentRoutedClientNotification::class, 1);
    }
}
