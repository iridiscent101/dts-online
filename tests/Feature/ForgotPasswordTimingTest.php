<?php

namespace Tests\Feature;

use App\Http\Controllers\ForgotPasswordController;
use App\Models\User;
use App\Notifications\TemporaryPasswordNotification;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class ForgotPasswordTimingTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_on_vercel_every_answer_takes_the_same_minimum_time(): void
    {
        config(['queue.default' => 'sync', 'dts.forgot_password_answer_seconds' => 0.4]);
        Notification::fake();
        $this->staffWithRecoveryEmail();

        foreach (['nobody@deped.local', 'clerk@deped.local'] as $email) {
            $started = microtime(true);
            $this->post(route('password.forgot.send'), ['email' => $email])
                ->assertSessionHas('status', ForgotPasswordController::SENT_MESSAGE);
            // An unknown address waits as long as a real one, so the time gives nothing away.
            $this->assertGreaterThanOrEqual(0.4, microtime(true) - $started, $email);
        }

        Notification::assertSentToTimes(User::query()->where('email', 'clerk@deped.local')->sole(), TemporaryPasswordNotification::class, 1);
    }

    public function test_on_other_servers_the_email_goes_out_after_the_answer(): void
    {
        config(['queue.default' => 'database']);
        Notification::fake();
        $clerk = $this->staffWithRecoveryEmail();

        // Deferred work runs once the answer has been sent (here, when the test request finishes).
        $this->post(route('password.forgot.send'), ['email' => 'clerk@deped.local'])
            ->assertSessionHas('status', ForgotPasswordController::SENT_MESSAGE);

        Notification::assertSentToTimes($clerk, TemporaryPasswordNotification::class, 1);
        $this->assertNotNull($clerk->fresh()->temporary_password);
    }

    private function staffWithRecoveryEmail(): User
    {
        return User::factory()->create([
            'email' => 'clerk@deped.local',
            'permissions' => ['platform.index' => true],
            'notification_email' => 'recovery@gmail.com',
            'notification_email_verified_at' => now(),
        ]);
    }
}
