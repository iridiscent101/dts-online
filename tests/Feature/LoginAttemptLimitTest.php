<?php

namespace Tests\Feature;

use App\Http\Middleware\LimitLoginAttempts;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class LoginAttemptLimitTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_five_failed_sign_ins_lock_the_account_on_that_computer_for_a_minute(): void
    {
        $user = User::factory()->create(['email' => 'clerk@deped.local', 'permissions' => ['platform.index' => true]]);

        foreach (range(1, LimitLoginAttempts::MAX_ATTEMPTS) as $attempt) {
            $this->from(route('platform.login'))
                ->post(route('platform.login.auth'), ['email' => 'clerk@deped.local', 'password' => 'wrong-password'])
                ->assertSessionHasErrors(['email' => 'The details you entered did not match our records. Please double-check and try again.']);
        }

        // Locked: even the right password waits, and the message says how long.
        $this->from(route('platform.login'))
            ->post(route('platform.login.auth'), ['email' => 'CLERK@deped.local', 'password' => 'password'])
            ->assertRedirect(route('platform.login'))
            ->assertSessionHasErrors('email');
        $this->assertMatchesRegularExpression('/^Too many failed sign-in attempts\. Please wait \d+ seconds? and try again\.$/', session('errors')->first('email'));
        $this->assertGuest();

        $this->travel(LimitLoginAttempts::LOCKOUT_SECONDS + 1)->seconds();

        $this->post(route('platform.login.auth'), ['email' => 'clerk@deped.local', 'password' => 'password'])
            ->assertSessionHasNoErrors();
        $this->assertAuthenticatedAs($user);
    }

    public function test_a_successful_sign_in_resets_the_count(): void
    {
        User::factory()->create(['email' => 'clerk@deped.local', 'permissions' => ['platform.index' => true]]);

        foreach (range(1, LimitLoginAttempts::MAX_ATTEMPTS - 1) as $attempt) {
            $this->post(route('platform.login.auth'), ['email' => 'clerk@deped.local', 'password' => 'wrong-password']);
        }
        $this->post(route('platform.login.auth'), ['email' => 'clerk@deped.local', 'password' => 'password']);
        $this->assertAuthenticated();
        $this->post(route('platform.logout'));

        foreach (range(1, LimitLoginAttempts::MAX_ATTEMPTS - 1) as $attempt) {
            $this->post(route('platform.login.auth'), ['email' => 'clerk@deped.local', 'password' => 'wrong-password'])
                ->assertSessionHasErrors(['email' => 'The details you entered did not match our records. Please double-check and try again.']);
        }
    }
}
