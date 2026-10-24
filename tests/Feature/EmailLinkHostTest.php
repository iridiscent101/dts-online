<?php

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\DocumentFinishedClientNotification;
use App\Notifications\DocumentRoutedClientNotification;
use App\Notifications\TemporaryPasswordNotification;
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

    public function test_a_forged_host_header_does_not_change_the_temporary_password_email_link(): void
    {
        Notification::fake();
        $clerk = User::factory()->create([
            'email' => 'clerk@deped.local', 'permissions' => ['platform.index' => true],
            'notification_email' => 'clerk@gmail.com',
        ]);

        $this->withHeader('Host', 'evil-login.test')
            ->post(route('password.forgot.send'), ['email' => 'clerk@deped.local'])
            ->assertRedirect(route('password.forgot'));

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
        $signIn = (new TemporaryPasswordNotification('Temp-Pass-2026!'))->toMail($client)->actionUrl;

        $this->assertSame('https://dts.example.gov.ph/?tracking=SDONV12345678912', $routed);
        $this->assertSame('https://dts.example.gov.ph/?tracking=SDONV12345678912', $finished);
        $this->assertSame('https://dts.example.gov.ph/admin/login', $signIn);
    }
}
