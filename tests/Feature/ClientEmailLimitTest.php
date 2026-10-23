<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\User;
use App\Notifications\DocumentNotificationDispatcher;
use App\Notifications\DocumentRoutedClientNotification;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\TestCase;

class ClientEmailLimitTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('mail.document_assignment_notifications_enabled', true);
        config()->set('queue.default', 'sync');
        Notification::fake();
    }

    public function test_one_address_can_be_given_on_only_a_few_online_submissions_a_day(): void
    {
        config()->set('dts.client_email.submissions_per_address_per_day', 3);

        foreach (range(1, 3) as $i) {
            $this->post(route('submission.store'), $this->submission('Victim@Example.com'))->assertSessionHasNoErrors();
        }

        // The fourth is refused with a clear way forward: leave the email blank.
        $this->post(route('submission.store'), $this->submission('victim@example.com'))
            ->assertSessionHasErrors(['email' => 'This email was already used on several documents today. Leave it blank to send your document now, or try again tomorrow.']);
        $this->post(route('submission.store'), $this->submission(null))->assertSessionHasNoErrors();

        $this->assertSame(3, Document::query()->where('client_email', 'victim@example.com')->count());
        $this->assertSame(4, Document::query()->count());
        Notification::assertSentOnDemandTimes(DocumentRoutedClientNotification::class, 3);

        // A day later the address can be used again.
        $this->travel(25)->hours();
        $this->post(route('submission.store'), $this->submission('victim@example.com'))->assertSessionHasNoErrors();
    }

    public function test_sending_the_same_submission_again_does_not_count_against_the_address(): void
    {
        config()->set('dts.client_email.submissions_per_address_per_day', 1);
        $data = $this->submission('client@gmail.com');

        $this->post(route('submission.store'), $data)->assertSessionHasNoErrors();
        $this->post(route('submission.store'), $data)->assertSessionHasNoErrors()->assertRedirect();

        $this->assertSame(1, Document::query()->count());
    }

    public function test_client_emails_stop_for_the_day_at_the_daily_limit_but_routing_goes_on(): void
    {
        config()->set('dts.client_email.daily_limit', 2);
        $actor = User::factory()->create(['office' => 'Records Unit']);
        $document = Document::factory()->received()->create(['client_email' => 'client@gmail.com']);
        $dispatcher = app(DocumentNotificationDispatcher::class);

        foreach (range(1, 4) as $ignored) {
            $dispatcher->assigned($document, 'forward', $actor, ['Administrative Unit']);
        }

        Notification::assertSentOnDemandTimes(DocumentRoutedClientNotification::class, 2);

        $this->travelTo(today('Asia/Manila')->addDay()->setTime(8, 0));
        $dispatcher->assigned($document, 'forward', $actor, ['Administrative Unit']);
        Notification::assertSentOnDemandTimes(DocumentRoutedClientNotification::class, 3);
    }

    /**
     * @return array<string, mixed>
     */
    private function submission(?string $email): array
    {
        return [
            'submission_key' => (string) Str::uuid(),
            'name' => 'Juan Dela Cruz',
            'office' => 'Bayombong Central School',
            'email' => $email,
            'delivery_method' => Document::DELIVERY_METHODS[0],
            'title' => 'Leave application',
            'type' => Document::typeOptions()[0],
            'privacy_consent' => '1',
        ];
    }
}
