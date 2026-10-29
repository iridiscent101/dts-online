<?php

namespace Tests\Feature;

use App\Http\Controllers\ForgotPasswordController;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class ForgotPasswordTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_sign_in_page_links_to_the_forgot_password_page(): void
    {
        $this->get(route('platform.login'))->assertOk()->assertSee(route('password.forgot'), false);
        $this->get(route('password.forgot'))->assertOk()->assertSee('Send reset link');
    }

    public function test_requesting_a_reset_does_not_change_the_password_and_the_link_expires(): void
    {
        Notification::fake();
        $user = $this->staff();
        $original = $user->password;
        $this->post(route('password.forgot.send'), ['email' => $user->email])->assertSessionHas('status', ForgotPasswordController::SENT_MESSAGE);
        $this->assertSame($original, $user->fresh()->password);
        $token = null;
        Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use (&$token): bool {
            $token = $notification->token;

            return true;
        });
        $this->travel(config('auth.passwords.users.expire') + 1)->minutes();
        $this->post(route('password.update'), ['email' => $user->email, 'token' => $token, 'password' => 'Strong-Password-123!', 'password_confirmation' => 'Strong-Password-123!'])->assertSessionHasErrors();
        $this->assertSame($original, $user->fresh()->password);
    }

    public function test_ineligible_and_unknown_accounts_get_the_same_response_without_email(): void
    {
        Notification::fake();
        $this->withoutMiddleware(ThrottleRequests::class);
        $unverified = $this->staff(['email' => 'unverified@example.com', 'notification_email_verified_at' => null]);
        $inactive = $this->staff(['email' => 'inactive@example.com', 'deactivated_at' => now()]);
        $kiosk = $this->staff(['email' => 'kiosk@example.com', 'permissions' => ['platform.kiosk' => true]]);
        foreach (['missing@example.com', $unverified->email, $inactive->email, $kiosk->email] as $email) {
            $this->post(route('password.forgot.send'), ['email' => $email])->assertSessionHas('status', ForgotPasswordController::SENT_MESSAGE);
        }
        Notification::assertNothingSent();
    }

    public function test_reset_requests_are_limited_per_account(): void
    {
        Notification::fake();
        $user = $this->staff();
        $this->post(route('password.forgot.send'), ['email' => $user->email]);
        $this->post(route('password.forgot.send'), ['email' => $user->email]);
        Notification::assertSentToTimes($user, ResetPassword::class, 1);
    }

    public function test_password_change_invalidates_a_pending_reset_link(): void
    {
        Notification::fake();
        $user = $this->staff();
        $this->post(route('password.forgot.send'), ['email' => $user->email]);
        $token = null;
        Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use (&$token): bool {
            $token = $notification->token;

            return true;
        });
        $user->forceFill(['password' => Hash::make('Current-Password-123!')])->save();
        $this->post(route('password.update'), ['email' => $user->email, 'token' => $token, 'password' => 'Another-Password-123!', 'password_confirmation' => 'Another-Password-123!'])->assertSessionHasErrors();
    }

    private function staff(array $attributes = []): User
    {
        return User::factory()->create(array_merge(['email' => 'clerk@deped.local', 'permissions' => ['platform.index' => true], 'notification_email' => 'recovery@gmail.com', 'notification_email_verified_at' => now()], $attributes));
    }
}
