<?php

namespace Tests\Feature;

use App\Auth\AccountActivity;
use App\Http\Controllers\ForgotPasswordController;
use App\Models\User;
use App\Notifications\TemporaryPasswordNotification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use RuntimeException;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class ForgotPasswordTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_sign_in_page_links_to_the_forgot_password_page(): void
    {
        $this->get(route('platform.login'))->assertOk()->assertSee(route('password.forgot'), false);
        $this->get(route('password.forgot'))->assertOk()->assertSee('Forgot your password?')->assertSee('Send temporary password');
    }

    public function test_a_temporary_password_goes_to_the_recovery_email_and_must_be_changed(): void
    {
        Notification::fake();
        $staff = $this->staffWithRecoveryEmail();

        $this->post(route('password.forgot.send'), ['email' => ' Clerk@Deped.local '])
            ->assertRedirect(route('password.forgot'))
            ->assertSessionHas('status', ForgotPasswordController::SENT_MESSAGE);

        $temporaryPassword = null;
        Notification::assertSentTo($staff, TemporaryPasswordNotification::class, function (TemporaryPasswordNotification $notification) use (&$temporaryPassword, $staff): bool {
            $temporaryPassword = $notification->temporaryPassword;

            return $staff->routeNotificationForMail($notification) === 'recovery@gmail.com';
        });
        $staff->refresh();
        $this->assertTrue(Hash::check($temporaryPassword, $staff->password));
        $this->assertFalse(Hash::check('password', $staff->password));
        $this->assertTrue($staff->must_change_password);
        $this->assertTrue(Activity::query()->where('event', AccountActivity::TEMPORARY_PASSWORD_SENT)->where('subject_id', $staff->id)->exists());

        // Signing in with it leads straight to setting a new password, with a note about the temporary one.
        $this->post(route('platform.login.auth'), ['email' => 'clerk@deped.local', 'password' => $temporaryPassword]);
        $this->assertAuthenticatedAs($staff);
        $this->get(route('platform.main'))->assertRedirect(route('platform.profile'));
        $this->get(route('platform.profile'))->assertOk()->assertSee('You signed in with a temporary password.');

        $this->post(route('platform.profile').'/changePassword', [
            'old_password' => $temporaryPassword,
            'password' => 'Brand-new-Pass-2026!',
            'password_confirmation' => 'Brand-new-Pass-2026!',
        ])->assertSessionHasNoErrors();
        $this->assertFalse($staff->fresh()->must_change_password);
    }

    public function test_a_recovery_email_works_without_a_confirmation_step(): void
    {
        Notification::fake();
        $staff = User::factory()->create([
            'email' => 'new@deped.local',
            'permissions' => ['platform.index' => true],
            'notification_email' => 'new.recovery@gmail.com',
            'notification_email_verified_at' => null,
        ]);

        $this->post(route('password.forgot.send'), ['email' => 'new@deped.local'])
            ->assertSessionHas('status', ForgotPasswordController::SENT_MESSAGE);

        Notification::assertSentTo($staff, TemporaryPasswordNotification::class);
        $this->assertTrue($staff->fresh()->must_change_password);
    }

    public function test_nothing_is_sent_or_changed_for_accounts_that_cannot_receive_one(): void
    {
        Notification::fake();
        $noRecovery = User::factory()->create(['email' => 'none@deped.local']);
        $deactivated = $this->staffWithRecoveryEmail('gone@deped.local');
        $deactivated->forceFill(['deactivated_at' => now()])->save();

        foreach (['none@deped.local', 'gone@deped.local', 'nobody@deped.local'] as $email) {
            // The same answer every time, so the page does not reveal which emails have accounts.
            $this->post(route('password.forgot.send'), ['email' => $email])
                ->assertSessionHas('status', ForgotPasswordController::SENT_MESSAGE);
        }

        Notification::assertNothingSent();
        foreach ([$noRecovery, $deactivated] as $user) {
            $this->assertTrue(Hash::check('password', $user->fresh()->password));
            $this->assertFalse((bool) $user->fresh()->must_change_password);
        }
    }

    public function test_an_account_gets_one_temporary_password_per_cooldown(): void
    {
        Notification::fake();
        $staff = $this->staffWithRecoveryEmail();

        $this->post(route('password.forgot.send'), ['email' => 'clerk@deped.local']);
        $firstHash = $staff->fresh()->password;
        $this->post(route('password.forgot.send'), ['email' => 'clerk@deped.local'])
            ->assertSessionHas('status', ForgotPasswordController::SENT_MESSAGE);

        Notification::assertSentToTimes($staff, TemporaryPasswordNotification::class, 1);
        $this->assertSame($firstHash, $staff->fresh()->password);

        $this->travel(ForgotPasswordController::ACCOUNT_COOLDOWN_SECONDS + 1)->seconds();
        $this->post(route('password.forgot.send'), ['email' => 'clerk@deped.local']);
        Notification::assertSentToTimes($staff, TemporaryPasswordNotification::class, 2);
    }

    public function test_a_mail_failure_keeps_the_old_password(): void
    {
        $staff = $this->staffWithRecoveryEmail();
        Notification::shouldReceive('sendNow')->andThrow(new RuntimeException('SMTP unavailable'));

        $this->post(route('password.forgot.send'), ['email' => 'clerk@deped.local'])
            ->assertSessionHas('status', ForgotPasswordController::SENT_MESSAGE);

        $this->assertTrue(Hash::check('password', $staff->fresh()->password));
        $this->assertFalse((bool) $staff->fresh()->must_change_password);
    }

    public function test_temporary_passwords_meet_the_password_rule_and_are_never_queued(): void
    {
        foreach (range(1, 50) as $ignored) {
            $password = ForgotPasswordController::generate();
            $this->assertSame(14, strlen($password));
            $this->assertTrue(Validator::make(['password' => $password], ['password' => [Password::defaults()]])->passes(), $password);
        }

        $this->assertNotInstanceOf(ShouldQueue::class, new TemporaryPasswordNotification('x'));
        $message = (new TemporaryPasswordNotification('Abc-123-xyz!Q9'))->toMail(new User);
        $this->assertSame('Your temporary password', $message->subject);
        $this->assertStringContainsString('Report not spam', implode(' ', $message->outroLines));
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
