<?php

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\VerifyRecoveryEmail;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class RecoveryEmailResetTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_recovery_email_must_be_verified_before_a_reset_link_is_sent(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);
        Notification::fake();
        $user = User::factory()->create(['email' => 'clerk@example.com', 'permissions' => ['platform.index' => true]]);
        $this->actingAs($user)->post(route('platform.profile').'/save', ['user' => ['name' => $user->name, 'email' => $user->email, 'notification_email' => 'recovery@gmail.com']])->assertSessionHasNoErrors();
        $user->refresh();
        $this->assertNull($user->notification_email_verified_at);
        $url = null;
        Notification::assertSentTo($user, VerifyRecoveryEmail::class, function (VerifyRecoveryEmail $notification) use ($user, &$url): bool {
            $url = $notification->toMail($user)->actionUrl;

            return $user->routeNotificationForMail($notification) === 'recovery@gmail.com';
        });
        $this->post(route('password.forgot.send'), ['email' => $user->email]);
        Notification::assertNotSentTo($user, ResetPassword::class);
        $this->get($url)->assertOk();
        $this->assertNotNull($user->fresh()->notification_email_verified_at);
        $this->get($url)->assertForbidden();
        $this->post(route('password.forgot.send'), ['email' => $user->email]);
        $token = null;
        Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use ($user, &$token): bool {
            $token = $notification->token;

            return $user->routeNotificationForMail($notification) === 'recovery@gmail.com';
        });
        $this->post(route('password.update'), ['email' => $user->email, 'token' => $token, 'password' => 'Secure-Password-123!', 'password_confirmation' => 'Secure-Password-123!'])->assertSessionHasNoErrors();
        $this->assertTrue(Hash::check('Secure-Password-123!', $user->fresh()->password));
        $this->post(route('password.update'), ['email' => $user->email, 'token' => $token, 'password' => 'Another-Password-123!', 'password_confirmation' => 'Another-Password-123!'])->assertSessionHasErrors();
    }

    public function test_existing_unverified_address_can_request_a_verification_link_from_the_profile(): void
    {
        Notification::fake();
        $user = User::factory()->create(['permissions' => ['platform.index' => true], 'notification_email' => 'recovery@gmail.com']);

        $this->actingAs($user)->get(route('platform.profile'))->assertOk()->assertSee('Send recovery email verification');
        $this->post(route('platform.profile').'/verifyRecoveryEmail')->assertSessionHasNoErrors();
        Notification::assertSentTo($user, VerifyRecoveryEmail::class);
    }

    public function test_changing_the_recovery_email_invalidates_old_verification_and_reset_links(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);
        Notification::fake();
        $user = User::factory()->create(['email' => 'clerk@example.com', 'permissions' => ['platform.index' => true], 'notification_email' => 'old@gmail.com', 'notification_email_verified_at' => now()]);
        $this->post(route('password.forgot.send'), ['email' => $user->email]);
        $token = null;
        Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use (&$token): bool {
            $token = $notification->token;

            return true;
        });
        $this->actingAs($user)->post(route('platform.profile').'/save', ['user' => ['name' => $user->name, 'email' => $user->email, 'notification_email' => 'new@gmail.com']])->assertSessionHasNoErrors();
        $this->assertNull($user->fresh()->notification_email_verified_at);
        $this->post(route('password.update'), ['email' => $user->email, 'token' => $token, 'password' => 'Secure-Password-123!', 'password_confirmation' => 'Secure-Password-123!'])->assertSessionHasErrors();
    }

    public function test_a_verification_link_expires_and_is_invalidated_when_the_address_changes(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);
        Notification::fake();
        $user = User::factory()->create(['permissions' => ['platform.index' => true]]);
        $this->actingAs($user)->post(route('platform.profile').'/save', ['user' => ['name' => $user->name, 'email' => $user->email, 'notification_email' => 'first@gmail.com']]);
        $url = null;
        Notification::assertSentTo($user, VerifyRecoveryEmail::class, function (VerifyRecoveryEmail $notification) use ($user, &$url): bool {
            $url = $notification->toMail($user)->actionUrl;

            return true;
        });
        $this->post(route('platform.profile').'/save', ['user' => ['name' => $user->name, 'email' => $user->email, 'notification_email' => 'second@gmail.com']]);
        $this->get($url)->assertForbidden();
        $latest = Notification::sent($user, VerifyRecoveryEmail::class)->last()->toMail($user->fresh())->actionUrl;
        $this->travel(61)->minutes();
        $this->get($latest)->assertForbidden();
        $this->assertNull($user->fresh()->notification_email_verified_at);
    }

    public function test_reset_rejects_weak_passwords_and_deactivated_accounts(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);
        Notification::fake();
        $user = User::factory()->create(['permissions' => ['platform.index' => true], 'notification_email' => 'recovery@gmail.com', 'notification_email_verified_at' => now()]);
        $this->post(route('password.forgot.send'), ['email' => $user->email]);
        $token = null;
        Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use (&$token): bool {
            $token = $notification->token;

            return true;
        });
        $this->post(route('password.update'), ['email' => $user->email, 'token' => $token, 'password' => 'weak', 'password_confirmation' => 'weak'])->assertSessionHasErrors('password');
        $user->forceFill(['deactivated_at' => now()])->save();
        $this->post(route('password.update'), ['email' => $user->email, 'token' => $token, 'password' => 'Secure-Password-123!', 'password_confirmation' => 'Secure-Password-123!'])->assertForbidden();
    }
}
