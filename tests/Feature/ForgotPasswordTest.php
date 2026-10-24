<?php

namespace Tests\Feature;

use App\Auth\AccountActivity;
use App\Http\Controllers\ForgotPasswordController;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use RuntimeException;
use Tests\TestCase;

class ForgotPasswordTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_sign_in_page_links_to_the_forgot_password_page(): void
    {
        $this->get(route('platform.login'))->assertOk()->assertSee(route('password.forgot'), false);
        $this->get(route('password.forgot'))->assertOk()->assertSee('Forgot your password?')->assertSee('Send reset link');
    }

    public function test_a_reset_link_goes_to_the_confirmed_recovery_email_without_changing_the_password(): void
    {
        Notification::fake();
        $staff = $this->staffWithRecoveryEmail();
        $originalPassword = $staff->password;

        $this->post(route('password.forgot.send'), ['email' => ' Clerk@Deped.local '])
            ->assertRedirect(route('password.forgot'))
            ->assertSessionHas('status', ForgotPasswordController::SENT_MESSAGE);

        Notification::assertSentTo($staff, ResetPassword::class, function (ResetPassword $notification) use ($staff): bool {
            return $staff->routeNotificationForMail($notification) === 'recovery@gmail.com';
        });
        $this->assertSame($originalPassword, $staff->fresh()->password);
        $this->assertDatabaseHas('password_reset_tokens', ['email' => $staff->email]);
    }

    public function test_nothing_is_sent_or_changed_for_accounts_that_cannot_receive_one(): void
    {
        Notification::fake();
        $unconfirmed = User::factory()->create(['email' => 'unconfirmed@deped.local', 'notification_email' => 'u@gmail.com', 'notification_email_verified_at' => null]);
        $noRecovery = User::factory()->create(['email' => 'none@deped.local']);
        $deactivated = $this->staffWithRecoveryEmail('gone@deped.local');
        $deactivated->forceFill(['deactivated_at' => now()])->save();

        foreach (['unconfirmed@deped.local', 'none@deped.local', 'gone@deped.local', 'nobody@deped.local'] as $email) {
            // The same answer every time, so the page does not reveal which emails have accounts.
            $this->post(route('password.forgot.send'), ['email' => $email])
                ->assertSessionHas('status', ForgotPasswordController::SENT_MESSAGE);
        }

        Notification::assertNothingSent();
        foreach ([$unconfirmed, $noRecovery, $deactivated] as $user) {
            $this->assertTrue(Hash::check('password', $user->fresh()->password));
            $this->assertFalse((bool) $user->fresh()->must_change_password);
        }
    }

    public function test_a_valid_link_resets_the_password_once(): void
    {
        Notification::fake();
        $staff = $this->staffWithRecoveryEmail();
        $token = null;

        $this->post(route('password.forgot.send'), ['email' => $staff->email]);
        Notification::assertSentTo($staff, ResetPassword::class, function (ResetPassword $notification) use (&$token): bool {
            $token = $notification->token;

            return true;
        });

        $this->get(route('password.reset', ['token' => $token, 'email' => $staff->email]))
            ->assertOk()
            ->assertSee('Choose a new password');
        $this->post(route('password.update'), [
            'token' => $token,
            'email' => $staff->email,
            'password' => 'Brand-new-Pass-2026!',
            'password_confirmation' => 'Brand-new-Pass-2026!',
        ])->assertRedirect(route('platform.login'));

        $this->assertTrue(Hash::check('Brand-new-Pass-2026!', $staff->fresh()->password));
        $this->assertFalse((bool) $staff->must_change_password);
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => $staff->email]);
        $this->assertDatabaseHas('activity_log', [
            'event' => AccountActivity::PASSWORD_CHANGED,
            'subject_id' => $staff->id,
            'properties->recovered' => true,
        ]);

        $this->post(route('password.update'), [
            'token' => $token,
            'email' => $staff->email,
            'password' => 'Another-new-Pass-2026!',
            'password_confirmation' => 'Another-new-Pass-2026!',
        ])->assertSessionHasErrors('email');
        $this->assertTrue(Hash::check('Brand-new-Pass-2026!', $staff->fresh()->password));
    }

    public function test_an_account_gets_one_reset_link_per_cooldown(): void
    {
        Notification::fake();
        $staff = $this->staffWithRecoveryEmail();

        $this->post(route('password.forgot.send'), ['email' => 'clerk@deped.local']);
        $firstHash = $staff->fresh()->password;
        $this->post(route('password.forgot.send'), ['email' => 'clerk@deped.local'])
            ->assertSessionHas('status', ForgotPasswordController::SENT_MESSAGE);

        Notification::assertSentToTimes($staff, ResetPassword::class, 1);
        $this->assertSame($firstHash, $staff->fresh()->password);

        $this->travel(ForgotPasswordController::ACCOUNT_COOLDOWN_SECONDS + 1)->seconds();
        $this->post(route('password.forgot.send'), ['email' => 'clerk@deped.local']);
        Notification::assertSentToTimes($staff, ResetPassword::class, 2);
    }

    public function test_a_mail_failure_keeps_the_old_password(): void
    {
        $staff = $this->staffWithRecoveryEmail();
        Notification::shouldReceive('send')->andThrow(new RuntimeException('SMTP unavailable'));

        $this->post(route('password.forgot.send'), ['email' => 'clerk@deped.local'])
            ->assertSessionHas('status', ForgotPasswordController::SENT_MESSAGE);

        $this->assertTrue(Hash::check('password', $staff->fresh()->password));
        $this->assertFalse((bool) $staff->fresh()->must_change_password);
    }

    public function test_changing_the_recovery_address_invalidates_an_existing_link(): void
    {
        Notification::fake();
        $staff = $this->staffWithRecoveryEmail();

        $this->post(route('password.forgot.send'), ['email' => $staff->email]);
        $this->assertDatabaseHas('password_reset_tokens', ['email' => $staff->email]);

        $staff->setNotificationEmail('new-recovery@gmail.com');

        $this->assertDatabaseMissing('password_reset_tokens', ['email' => $staff->email]);
    }

    private function staffWithRecoveryEmail(string $email = 'clerk@deped.local'): User
    {
        return User::factory()->create([
            'email' => $email,
            'permissions' => ['platform.index' => true],
            'notification_email' => 'recovery@gmail.com',
            'notification_email_verified_at' => now(),
        ]);
    }
}
