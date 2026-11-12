<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

class PasswordRecoveryTotpTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_authenticator_setup_requires_password_and_a_valid_code(): void
    {
        $user = $this->staff();
        $this->actingAs($user)->get(route('platform.profile'))->assertOk()->assertSee('Enable authenticator');
        $secret = session('password_recovery_totp_setup.secret');
        $this->assertNotEmpty($secret);

        $this->post(route('platform.profile').'/enableRecoveryTotp', [
            'totp_current_password' => 'wrong',
            'totp_code' => (new Google2FA)->getCurrentOtp($secret),
        ])->assertSessionHasErrors('totp_current_password');
        $this->assertNull($user->fresh()->password_recovery_totp_secret);

        $this->post(route('platform.profile').'/enableRecoveryTotp', [
            'totp_current_password' => 'password',
            'totp_code' => (new Google2FA)->getCurrentOtp($secret),
        ])->assertSessionHasNoErrors();
        $this->assertSame($secret, $user->fresh()->password_recovery_totp_secret);
        $this->assertSame('', session('password_recovery_totp_setup.secret', ''));
        $this->get(route('platform.profile'))->assertOk()->assertDontSee($secret);
        $this->assertStringNotContainsString($secret, $user->fresh()->getRawOriginal('password_recovery_totp_secret'));

        $otp = new Google2FA;
        $this->post(route('platform.profile').'/disableRecoveryTotp', [
            'totp_current_password' => 'password',
            'totp_code' => $otp->oathTotp($secret, $otp->getTimestamp() + 1),
        ])->assertSessionHasNoErrors();
        $this->assertNull($user->fresh()->password_recovery_totp_secret);
    }

    public function test_reset_link_also_requires_authenticator_for_enrolled_user(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);
        Notification::fake();
        $user = $this->staff();
        $secret = (new Google2FA)->generateSecretKey();
        $user->forceFill(['password_recovery_totp_secret' => $secret])->save();
        $this->post(route('password.forgot.send'), ['email' => $user->email]);
        $token = null;
        Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use (&$token): bool {
            $token = $notification->token;

            return true;
        });
        $password = 'Secure-Password-123!';
        $payload = ['email' => $user->email, 'token' => $token, 'password' => $password, 'password_confirmation' => $password];
        $this->get(route('password.reset', ['token' => $token, 'email' => $user->email]))
            ->assertOk()
            ->assertSee('Authenticator code')
            ->assertSee('data-dts-reset-form', false);
        $this->post(route('password.update'), $payload)->assertSessionHasErrors('totp_code');
        $this->postJson(route('password.update'), $payload)->assertUnprocessable()->assertJsonValidationErrors('totp_code');
        $this->post(route('password.update'), $payload + ['totp_code' => '000000'])->assertSessionHasErrors('totp_code');
        $this->assertTrue(Hash::check('password', $user->fresh()->password));
        $this->postJson(route('password.update'), $payload + ['totp_code' => (new Google2FA)->getCurrentOtp($secret)])->assertOk()->assertJsonStructure(['message']);
        $this->assertTrue(Hash::check($password, $user->fresh()->password));
    }

    public function test_known_password_change_does_not_require_authenticator_code(): void
    {
        $user = $this->staff();
        $user->forceFill(['password_recovery_totp_secret' => (new Google2FA)->generateSecretKey()])->save();

        $this->actingAs($user)->post(route('platform.profile').'/changePassword', [
            'old_password' => 'password',
            'password' => 'Secure-Password-123!',
            'password_confirmation' => 'Secure-Password-123!',
        ])->assertSessionHasNoErrors();
        $this->assertTrue(Hash::check('Secure-Password-123!', $user->fresh()->password));
    }

    private function staff(): User
    {
        return User::factory()->create([
            'email' => 'clerk@example.com',
            'permissions' => ['platform.index' => true],
            'notification_email' => 'recovery@gmail.com',
            'notification_email_verified_at' => now(),
        ]);
    }
}
