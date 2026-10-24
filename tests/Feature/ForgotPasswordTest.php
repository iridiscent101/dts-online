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
        // The real password is untouched: the temporary one waits beside it.
        $staff->refresh();
        $this->assertTrue(Hash::check('password', $staff->password));
        $this->assertTrue(Hash::check($temporaryPassword, $staff->temporary_password));
        $this->assertFalse($staff->must_change_password);
        $this->assertTrue(Activity::query()->where('event', AccountActivity::TEMPORARY_PASSWORD_SENT)->where('subject_id', $staff->id)->exists());

        // Signing in with it makes it the password and leads straight to setting a new one, with a note about it.
        $this->post(route('platform.login.auth'), ['email' => 'clerk@deped.local', 'password' => $temporaryPassword]);
        $this->assertAuthenticatedAs($staff);
        $staff->refresh();
        $this->assertTrue(Hash::check($temporaryPassword, $staff->password));
        $this->assertNull($staff->temporary_password);
        $this->assertTrue($staff->must_change_password);
        $this->assertTrue(Activity::query()->where('event', AccountActivity::TEMPORARY_PASSWORD_USED)->where('subject_id', $staff->id)->exists());
        $this->get(route('platform.main'))->assertRedirect(route('platform.profile'));
        $this->get(route('platform.profile'))->assertOk()->assertSee('You signed in with a temporary password.');

        $this->post(route('platform.profile').'/changePassword', [
            'old_password' => $temporaryPassword,
            'password' => 'Brand-new-Pass-2026!',
            'password_confirmation' => 'Brand-new-Pass-2026!',
        ])->assertSessionHasNoErrors();
        $this->assertFalse($staff->fresh()->must_change_password);
    }

    public function test_someone_asking_for_your_reset_cannot_lock_you_out(): void
    {
        Notification::fake();
        $staff = $this->staffWithRecoveryEmail();
        // A password that meets the rule, so signing in with it asks for no change.
        $staff->forceFill(['password' => Hash::make('Usual-Strong-Pass-2026!')])->save();

        $this->post(route('password.forgot.send'), ['email' => 'clerk@deped.local']);
        $temporaryPassword = null;
        Notification::assertSentTo($staff, TemporaryPasswordNotification::class, function (TemporaryPasswordNotification $notification) use (&$temporaryPassword): bool {
            $temporaryPassword = $notification->temporaryPassword;

            return true;
        });

        // The owner signs in with their usual password: nothing to change, and the unused temporary one stops working.
        $this->post(route('platform.login.auth'), ['email' => 'clerk@deped.local', 'password' => 'Usual-Strong-Pass-2026!']);
        $this->assertAuthenticatedAs($staff);
        $staff->refresh();
        $this->assertFalse($staff->must_change_password);
        $this->assertNull($staff->temporary_password);
        $this->get(route('platform.main'))->assertOk();

        $this->post(route('platform.logout'));
        $this->post(route('platform.login.auth'), ['email' => 'clerk@deped.local', 'password' => $temporaryPassword]);
        $this->assertGuest();
    }

    public function test_a_temporary_password_expires(): void
    {
        Notification::fake();
        $staff = $this->staffWithRecoveryEmail();

        $this->post(route('password.forgot.send'), ['email' => 'clerk@deped.local']);
        $temporaryPassword = null;
        Notification::assertSentTo($staff, TemporaryPasswordNotification::class, function (TemporaryPasswordNotification $notification) use (&$temporaryPassword): bool {
            $temporaryPassword = $notification->temporaryPassword;

            return true;
        });

        $this->travel(config('dts.temporary_password_minutes') + 1)->minutes();
        $this->post(route('platform.login.auth'), ['email' => 'clerk@deped.local', 'password' => $temporaryPassword]);
        $this->assertGuest();
        $this->assertTrue(Hash::check('password', $staff->fresh()->password));
    }

    public function test_setting_a_new_password_cancels_a_pending_temporary_one(): void
    {
        Notification::fake();
        $staff = $this->staffWithRecoveryEmail();
        $this->post(route('password.forgot.send'), ['email' => 'clerk@deped.local']);
        $this->assertNotNull($staff->fresh()->temporary_password);

        $staff = $staff->fresh();
        $staff->password = Hash::make('Another-new-Pass-2026!');
        $staff->save();

        $this->assertNull($staff->fresh()->temporary_password);
        $this->assertNull($staff->fresh()->temporary_password_expires_at);
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
        $this->assertNotNull($staff->fresh()->temporary_password);
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
            $this->assertNull($user->fresh()->temporary_password);
        }
    }

    public function test_an_account_gets_one_temporary_password_per_cooldown(): void
    {
        Notification::fake();
        $staff = $this->staffWithRecoveryEmail();

        $this->post(route('password.forgot.send'), ['email' => 'clerk@deped.local']);
        $firstHash = $staff->fresh()->temporary_password;
        $this->post(route('password.forgot.send'), ['email' => 'clerk@deped.local'])
            ->assertSessionHas('status', ForgotPasswordController::SENT_MESSAGE);

        Notification::assertSentToTimes($staff, TemporaryPasswordNotification::class, 1);
        $this->assertSame($firstHash, $staff->fresh()->temporary_password);

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
        $this->assertNull($staff->fresh()->temporary_password);
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
