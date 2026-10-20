<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\User;
use App\Notifications\DocumentAssignedMailNotification;
use App\Notifications\DocumentNotificationDispatcher;
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

    public function test_vercel_style_sync_mail_sends_after_commit_but_not_after_rollback(): void
    {
        config()->set('queue.default', 'sync');
        config()->set('mail.document_assignment_notifications_enabled', true);
        Notification::fake();
        $actor = User::factory()->create(['office' => 'Records Unit']);
        $recipient = User::factory()->create([
            'office' => 'Administrative Unit',
            'notification_email' => 'staff@gmail.com',
            'notification_email_verified_at' => now(),
        ]);
        $document = Document::factory()->received()->create();

        DB::beginTransaction();
        app(DocumentNotificationDispatcher::class)->assigned($document, 'forward', $actor, ['Administrative Unit']);
        Notification::assertNotSentTo($recipient, DocumentAssignedMailNotification::class);
        DB::rollBack();
        Notification::assertNotSentTo($recipient, DocumentAssignedMailNotification::class);

        DB::transaction(fn () => app(DocumentNotificationDispatcher::class)
            ->assigned($document, 'forward', $actor, ['Administrative Unit']));

        Notification::assertSentTo($recipient, DocumentAssignedMailNotification::class);

        Notification::shouldReceive('send')->twice()->andReturnUsing(function (mixed $notifiables, mixed $notification): void {
            if ($notification instanceof DocumentAssignedMailNotification) {
                throw new \RuntimeException('SMTP unavailable');
            }
        });

        $committed = DB::transaction(function () use ($document, $actor): bool {
            app(DocumentNotificationDispatcher::class)->assigned($document, 'forward', $actor, ['Administrative Unit']);

            return true;
        });

        $this->assertTrue($committed);
    }
}
