<?php

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\DocumentFinishedClientNotification;
use App\Notifications\DocumentRoutedClientNotification;
use App\Notifications\TemporaryPasswordNotification;
use App\Notifications\VerifyNotificationEmail;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class EmailLinkHostTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.url' => 'https://dts.example.gov.ph']);
    }

    public function test_a_forged_host_header_does_not_change_the_reset_email_link(): void
    {
        Notification::fake();
        $clerk = User::factory()->create([
            'email' => 'clerk@deped.local', 'permissions' => ['platform.index' => true],
            'notification_email' => 'clerk@gmail.com', 'notification_email_verified_at' => now(),
        ]);

        $this->withServerVariables(['HTTP_HOST' => 'evil-login.test'])
            ->post('http://evil-login.test/forgot-password', ['email' => 'clerk@deped.local']);

        Notification::assertSentTo($clerk, TemporaryPasswordNotification::class, function (TemporaryPasswordNotification $notification) use ($clerk): bool {
            return $notification->toMail($clerk)->actionUrl === 'https://dts.example.gov.ph/admin/login';
        });
    }

    public function test_every_email_link_uses_the_site_address(): void
    {
        // A request arrived with a forged host; links built afterwards must still use APP_URL.
        $this->app->instance('request', Request::create('http://evil.test/'));
        app('url')->setRequest(app('request'));
        $client = new AnonymousNotifiable;

        $routed = (new DocumentRoutedClientNotification('SDONV12345678912', ['SDO-Budget Unit']))->toMail($client)->actionUrl;
        $finished = (new DocumentFinishedClientNotification('SDONV12345678912', DocumentFinishedClientNotification::RELEASED, 'SDO-Records Unit'))->toMail($client)->actionUrl;
        $verify = (new VerifyNotificationEmail(1, str_repeat('a', 64)))->toMail($client)->actionUrl;

        $this->assertSame('https://dts.example.gov.ph/?tracking=SDONV12345678912', $routed);
        $this->assertSame('https://dts.example.gov.ph/?tracking=SDONV12345678912', $finished);
        $this->assertStringStartsWith('https://dts.example.gov.ph/notification-email/verify/1/', $verify);
        $this->assertStringContainsString('signature=', $verify);
    }

    public function test_the_confirmation_link_still_works_from_the_site_address(): void
    {
        $user = User::factory()->create(['notification_email' => 'clerk@gmail.com']);
        $link = (new VerifyNotificationEmail($user->id, hash('sha256', 'clerk@gmail.com')))->toMail($user)->actionUrl;

        // The signature covers only the path and query, so the link opens on the real site.
        $this->get(str_replace('https://dts.example.gov.ph', '', $link))->assertOk();
        $this->assertNotNull($user->fresh()->notification_email_verified_at);
    }
}
