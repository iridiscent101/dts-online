<?php

namespace Tests\Feature;

use App\Auth\AccountActivity;
use App\Http\Middleware\EnsureAccountIsActive;
use App\Http\Middleware\LimitLoginAttempts;
use App\Models\User;
use App\Notifications\RepeatedSignInLockoutNotification;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Orchid\Platform\Models\Role;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class AccountSecurityTest extends TestCase
{
    use LazilyRefreshDatabase;

    private const STRONG_PASSWORD = 'Records-Unit-2026!';

    public function test_new_passwords_need_8_characters_a_capital_a_number_and_a_symbol(): void
    {
        $staff = User::factory()->create(['permissions' => ['platform.index' => true]]);

        foreach ([
            'Sh0rt-a',               // 7 characters
            'records-unit-2026!',    // no capital letter
            'Records-Unit-Office!',  // no number
            'RecordsUnit20261',      // no symbol
            '1234567890123!A',       // no small letter
        ] as $weak) {
            $this->actingAs($staff)
                ->from(route('platform.profile'))
                ->post(route('platform.profile').'/changePassword', [
                    'old_password' => 'password',
                    'password' => $weak,
                    'password_confirmation' => $weak,
                ])
                ->assertSessionHasErrors('password');
        }
        $this->assertTrue(Hash::check('password', $staff->fresh()->password));

        $this->actingAs($staff)
            ->post(route('platform.profile').'/changePassword', [
                'old_password' => 'password',
                'password' => self::STRONG_PASSWORD,
                'password_confirmation' => self::STRONG_PASSWORD,
            ])
            ->assertSessionHasNoErrors();

        $this->assertTrue(Hash::check(self::STRONG_PASSWORD, $staff->fresh()->password));
        $this->assertDatabaseHas('activity_log', [
            'log_name' => AccountActivity::LOG_NAME,
            'event' => AccountActivity::PASSWORD_CHANGED,
            'subject_id' => $staff->id,
            'causer_id' => $staff->id,
        ]);
    }

    public function test_the_profile_page_shows_the_password_rule(): void
    {
        $staff = User::factory()->create(['permissions' => ['platform.index' => true]]);

        $this->actingAs($staff)->get(route('platform.profile'))->assertSee(User::PASSWORD_HINT);
    }

    public function test_an_administrator_must_confirm_a_strong_password_for_someone_else(): void
    {
        $admin = $this->administrator();
        $staff = User::factory()->create();

        $this->actingAs($admin)
            ->get(route('platform.systems.users.edit', $staff))
            ->assertSee('name="user[password_confirmation]"', false);

        $this->from(route('platform.systems.users.edit', $staff))
            ->post(route('platform.systems.users.edit', $staff).'/save', [
                'user' => ['name' => $staff->name, 'email' => $staff->email, 'password' => 'password123', 'password_confirmation' => 'password123', 'roles' => []],
            ])
            ->assertSessionHasErrors('user.password');

        $this->post(route('platform.systems.users.edit', $staff).'/save', [
            'user' => ['name' => $staff->name, 'email' => $staff->email, 'password' => self::STRONG_PASSWORD, 'password_confirmation' => 'Different-Password-2026!', 'roles' => []],
        ])->assertSessionHasErrors('user.password');

        $this->assertTrue(Hash::check('password', $staff->fresh()->password));

        $this->post(route('platform.systems.users.edit', $staff).'/save', [
            'user' => ['name' => $staff->name, 'email' => $staff->email, 'password' => self::STRONG_PASSWORD, 'password_confirmation' => self::STRONG_PASSWORD, 'roles' => []],
        ])->assertSessionHasNoErrors();

        $this->assertTrue(Hash::check(self::STRONG_PASSWORD, $staff->fresh()->password));
        $entry = Activity::query()->where('event', AccountActivity::PASSWORD_CHANGED)->sole();
        $this->assertSame($admin->id, $entry->causer_id);
        $this->assertTrue((bool) data_get($entry->properties, 'set_by_admin'));
    }

    public function test_sign_ins_failures_and_sign_outs_are_recorded_with_the_computer(): void
    {
        $staff = User::factory()->create(['email' => 'clerk@deped.local', 'permissions' => ['platform.index' => true]]);

        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.7'])
            ->post(route('platform.login.auth'), ['email' => 'nobody@deped.local', 'password' => 'guess']);
        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.7'])
            ->post(route('platform.login.auth'), ['email' => 'clerk@deped.local', 'password' => 'wrong-password']);
        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.7'])
            ->withHeader('User-Agent', 'Office PC browser')
            ->post(route('platform.login.auth'), ['email' => 'clerk@deped.local', 'password' => 'password']);
        $this->assertAuthenticatedAs($staff);
        $this->post(route('platform.logout'));

        $entries = Activity::query()->where('log_name', AccountActivity::LOG_NAME)->oldest('id')->get();

        $this->assertSame(
            // The factory password ("password") breaks the rule, so the sign-in also asks for a new one.
            [AccountActivity::SIGN_IN_FAILED, AccountActivity::SIGN_IN_FAILED, AccountActivity::SIGNED_IN, AccountActivity::PASSWORD_CHANGE_REQUIRED, AccountActivity::SIGNED_OUT],
            $entries->pluck('event')->all(),
        );
        $this->assertSame('nobody@deped.local', data_get($entries[0]->properties, 'email'));
        $this->assertSame('unknown_email', data_get($entries[0]->properties, 'reason'));
        $this->assertNull($entries[0]->subject_id);
        $this->assertSame($staff->id, $entries[1]->subject_id);
        $this->assertSame('wrong_password', data_get($entries[1]->properties, 'reason'));
        $this->assertNull($entries[1]->causer_id);
        $this->assertSame($staff->id, $entries[2]->causer_id);
        $this->assertSame('10.0.0.7', data_get($entries[2]->properties, 'ip'));
        $this->assertSame('Office PC browser', data_get($entries[2]->properties, 'browser'));
        $this->assertSame($staff->id, $entries[4]->subject_id);

        // The password itself is never stored.
        $this->assertStringNotContainsString('wrong-password', $entries->toJson());
        $this->assertStringNotContainsString('guess', $entries->toJson());
    }

    public function test_repeated_lockouts_get_longer_and_alert_administrators(): void
    {
        Notification::fake();
        $admin = $this->administrator();
        User::factory()->create(['email' => 'clerk@deped.local', 'permissions' => ['platform.index' => true]]);

        foreach (LimitLoginAttempts::LOCKOUT_STEPS as $lockout => $seconds) {
            $this->failSignInFiveTimes('clerk@deped.local');

            $this->from(route('platform.login'))
                ->post(route('platform.login.auth'), ['email' => 'clerk@deped.local', 'password' => 'password'])
                ->assertSessionHasErrors(['email' => LimitLoginAttempts::lockedMessage($seconds)]);
            $this->assertGuest();

            if ($lockout < LimitLoginAttempts::ALERT_AFTER_LOCKOUTS - 1) {
                Notification::assertNothingSent();
            }

            $this->travel($seconds + 1)->seconds();
        }

        Notification::assertSentTo($admin, RepeatedSignInLockoutNotification::class,
            fn (RepeatedSignInLockoutNotification $notification): bool => $notification->email === 'clerk@deped.local' && $notification->lockouts === 3);
        $this->assertSame(3, Activity::query()->where('event', AccountActivity::LOCKED_OUT)->count());
        $this->assertStringContainsString('15 minutes', LimitLoginAttempts::lockedMessage(900));

        // The right password after the wait signs in and starts the count over.
        $this->post(route('platform.login.auth'), ['email' => 'clerk@deped.local', 'password' => 'password'])
            ->assertSessionHasNoErrors();
        $this->assertAuthenticated();
        $this->post(route('platform.logout'));

        $this->failSignInFiveTimes('clerk@deped.local');
        $this->from(route('platform.login'))
            ->post(route('platform.login.auth'), ['email' => 'clerk@deped.local', 'password' => 'password'])
            ->assertSessionHasErrors(['email' => LimitLoginAttempts::lockedMessage(LimitLoginAttempts::LOCKOUT_STEPS[0])]);
    }

    public function test_unknown_email_lockouts_do_not_alert_administrators(): void
    {
        Notification::fake();
        $this->administrator();

        foreach (LimitLoginAttempts::LOCKOUT_STEPS as $seconds) {
            $this->failSignInFiveTimes('missing@deped.local');
            $this->travel($seconds + 1)->seconds();
        }

        Notification::assertNothingSent();
    }

    public function test_a_deactivated_account_cannot_sign_in(): void
    {
        $staff = User::factory()->create(['email' => 'clerk@deped.local', 'permissions' => ['platform.index' => true]]);
        $staff->deactivate($this->administrator());

        // A wrong password gets the usual message, so strangers cannot tell which emails exist.
        $this->from(route('platform.login'))
            ->post(route('platform.login.auth'), ['email' => 'clerk@deped.local', 'password' => 'wrong-password'])
            ->assertSessionHasErrors(['email' => 'The details you entered did not match our records. Please double-check and try again.']);

        $this->from(route('platform.login'))
            ->post(route('platform.login.auth'), ['email' => 'clerk@deped.local', 'password' => 'password'])
            ->assertRedirect(route('platform.login'))
            ->assertSessionHasErrors(['email' => EnsureAccountIsActive::DEACTIVATED_MESSAGE]);

        $this->assertGuest();
        $this->assertDatabaseHas('activity_log', ['event' => AccountActivity::DEACTIVATED_SIGN_IN, 'subject_id' => $staff->id]);
        $this->assertDatabaseMissing('activity_log', ['event' => AccountActivity::SIGNED_IN, 'subject_id' => $staff->id]);

        $staff->reactivate($this->administrator('second-admin@deped.local'));
        $this->post(route('platform.login.auth'), ['email' => 'clerk@deped.local', 'password' => 'password'])
            ->assertSessionHasNoErrors();
        $this->assertAuthenticatedAs($staff);
    }

    public function test_an_open_session_ends_when_the_account_is_deactivated(): void
    {
        $staff = User::factory()->create(['permissions' => ['platform.index' => true]]);
        $kiosk = User::factory()->create(['permissions' => ['platform.kiosk' => true]]);

        $this->actingAs($staff)->get(route('platform.main'))->assertOk();
        $staff->forceFill(['deactivated_at' => now()])->save();

        $this->actingAs($staff)->get(route('platform.main'))
            ->assertRedirect(route('platform.login'))
            ->assertSessionHasErrors(['email' => EnsureAccountIsActive::DEACTIVATED_MESSAGE]);
        $this->assertGuest();

        $kiosk->forceFill(['deactivated_at' => now()])->save();
        $this->actingAs($kiosk)->get(route('kiosk'))->assertRedirect(route('platform.login'));
        $this->assertGuest();
    }

    public function test_sign_in_activity_is_for_administrators(): void
    {
        $admin = $this->administrator();
        $userManager = User::factory()->create(['permissions' => ['platform.index' => true, 'platform.systems.users' => true]]);
        $staff = User::factory()->create(['name' => 'Rosa Clerk', 'email' => 'clerk@deped.local']);

        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.9'])
            ->post(route('platform.login.auth'), ['email' => 'clerk@deped.local', 'password' => 'wrong-password']);
        $staff->deactivate($admin);

        $this->actingAs($userManager)->get(route('platform.systems.sign-in-activity'))->assertForbidden();

        $this->actingAs($admin)->get(route('platform.systems.sign-in-activity'))
            ->assertOk()
            ->assertSee('Sign-in activity')
            ->assertSeeInOrder(['Account deactivated', 'Sign-in failed'])
            ->assertSee('Rosa Clerk (clerk@deped.local)')
            ->assertSee('Wrong password')
            ->assertSee('10.0.0.9');

        // "Account deactivated" is also an option in the filter, so it appears once more when the entry is listed.
        $all = $this->actingAs($admin)->get(route('platform.systems.sign-in-activity'))->getContent();
        $failedOnly = $this->actingAs($admin)
            ->get(route('platform.systems.sign-in-activity', ['activity_event' => AccountActivity::SIGN_IN_FAILED]))
            ->assertSee('Wrong password')
            ->getContent();
        $this->assertSame(substr_count($all, 'Account deactivated') - 1, substr_count($failedOnly, 'Account deactivated'));

        $this->actingAs($admin)
            ->get(route('platform.systems.sign-in-activity', ['activity_search' => '10.0.0.9']))
            ->assertSee('Rosa Clerk');
    }

    public function test_deactivated_administrators_do_not_receive_lockout_alerts(): void
    {
        Notification::fake();
        $active = $this->administrator();
        $deactivated = $this->administrator('former-admin@deped.local');
        $deactivated->forceFill(['deactivated_at' => now()])->save();
        User::factory()->create(['email' => 'clerk@deped.local']);

        foreach (LimitLoginAttempts::LOCKOUT_STEPS as $seconds) {
            $this->failSignInFiveTimes('clerk@deped.local');
            $this->travel($seconds + 1)->seconds();
        }

        Notification::assertSentTo($active, RepeatedSignInLockoutNotification::class);
        Notification::assertNotSentTo($deactivated, RepeatedSignInLockoutNotification::class);
    }

    public function test_signing_in_with_a_password_that_breaks_the_rule_requires_a_new_one(): void
    {
        $staff = User::factory()->create(['email' => 'clerk@deped.local', 'permissions' => ['platform.index' => true]]);

        $this->post(route('platform.login.auth'), ['email' => 'clerk@deped.local', 'password' => 'password'])
            ->assertRedirect(route('platform.main'));
        $this->assertTrue($staff->fresh()->must_change_password);
        $this->assertDatabaseHas('activity_log', ['event' => AccountActivity::PASSWORD_CHANGE_REQUIRED, 'subject_id' => $staff->id]);

        // Everything but My Account is closed until the password is changed.
        $this->get(route('platform.main'))->assertRedirect(route('platform.profile'));
        $this->get(route('platform.documents'))->assertRedirect(route('platform.profile'));
        $this->getJson(route('platform.documents.intake'))->assertForbidden();
        $this->get(route('platform.profile'))
            ->assertOk()
            ->assertSee('Please set a new password')
            ->assertSee(User::PASSWORD_HINT)
            ->assertDontSee('Profile Information');

        // A new password that still breaks the rule is refused.
        $this->from(route('platform.profile'))
            ->post(route('platform.profile').'/changePassword', [
                'old_password' => 'password', 'password' => 'password2', 'password_confirmation' => 'password2',
            ])
            ->assertSessionHasErrors('password');
        $this->assertTrue($staff->fresh()->must_change_password);

        $this->post(route('platform.profile').'/changePassword', [
            'old_password' => 'password', 'password' => self::STRONG_PASSWORD, 'password_confirmation' => self::STRONG_PASSWORD,
        ])->assertRedirect(route('platform.main'));

        $this->assertFalse($staff->fresh()->must_change_password);
        $this->get(route('platform.main'))->assertOk();
    }

    public function test_a_password_that_meets_the_rule_is_not_asked_to_change(): void
    {
        $staff = User::factory()->create([
            'email' => 'clerk@deped.local',
            'password' => Hash::make(self::STRONG_PASSWORD),
            'permissions' => ['platform.index' => true],
        ]);

        $this->post(route('platform.login.auth'), ['email' => 'clerk@deped.local', 'password' => self::STRONG_PASSWORD]);

        $this->assertAuthenticatedAs($staff);
        $this->assertFalse($staff->fresh()->must_change_password);
        $this->get(route('platform.main'))->assertOk();
    }

    public function test_the_password_change_step_can_be_switched_off(): void
    {
        config(['dts.require_password_change' => false]);
        $staff = User::factory()->create([
            'email' => 'clerk@deped.local',
            'password' => Hash::make('password'),
            'permissions' => ['platform.index' => true],
        ]);
        $setByAdmin = User::factory()->create(['must_change_password' => true, 'permissions' => ['platform.index' => true]]);

        // A short password is not flagged at sign-in, and nothing is blocked.
        $this->post(route('platform.login.auth'), ['email' => 'clerk@deped.local', 'password' => 'password'])
            ->assertRedirect(route('platform.main'));
        $this->assertFalse($staff->fresh()->must_change_password);
        $this->get(route('platform.main'))->assertOk();

        // An account already marked (an administrator set its password) is not held on My Account either.
        $this->post(route('platform.logout'));
        $this->flushSession();
        $this->actingAs($setByAdmin)->get(route('platform.main'))->assertOk();
        $this->get(route('platform.profile'))->assertOk()->assertDontSee('Please set a new password');
    }

    public function test_signing_out_still_works_while_a_new_password_is_required(): void
    {
        $staff = User::factory()->create(['must_change_password' => true, 'permissions' => ['platform.index' => true]]);

        $this->actingAs($staff)->post(route('platform.logout'))->assertRedirect('/');
        $this->assertGuest();
    }

    public function test_the_kiosk_account_is_not_asked_to_change_its_password(): void
    {
        $kiosk = User::factory()->create(['email' => 'kiosk@deped.local', 'permissions' => ['platform.kiosk' => true]]);

        $this->post(route('platform.login.auth'), ['email' => 'kiosk@deped.local', 'password' => 'password']);

        $this->assertFalse($kiosk->fresh()->must_change_password);
        $this->get(route('kiosk'))->assertOk();
    }

    public function test_a_password_an_administrator_sets_must_be_changed_at_the_next_sign_in(): void
    {
        $admin = $this->administrator();
        $staff = User::factory()->create(['must_change_password' => false, 'permissions' => ['platform.index' => true]]);

        $this->actingAs($admin)
            ->post(route('platform.systems.users.edit', $staff).'/save', [
                'user' => ['name' => $staff->name, 'email' => $staff->email, 'password' => self::STRONG_PASSWORD, 'password_confirmation' => self::STRONG_PASSWORD, 'roles' => []],
            ])
            ->assertSessionHasNoErrors();

        // The administrator knows this password, so the staff member must replace it.
        $this->assertTrue($staff->fresh()->must_change_password);
        // Saving on this screen resets direct permissions (access comes from roles); give the test account access again.
        $staff->fresh()->forceFill(['permissions' => ['platform.index' => true]])->save();
        $this->post(route('platform.logout'));
        $this->post(route('platform.login.auth'), ['email' => $staff->email, 'password' => self::STRONG_PASSWORD]);
        $this->get(route('platform.profile'))->assertOk()->assertSee('An administrator set this password for you.');
    }

    public function test_an_administrator_changing_their_own_password_is_not_asked_again(): void
    {
        $admin = $this->administrator();

        $this->actingAs($admin)
            ->post(route('platform.systems.users.edit', $admin).'/save', [
                'user' => ['name' => $admin->name, 'email' => $admin->email, 'password' => self::STRONG_PASSWORD, 'password_confirmation' => self::STRONG_PASSWORD],
            ])
            ->assertSessionHasNoErrors();

        $this->assertFalse((bool) $admin->fresh()->must_change_password);
    }

    private function failSignInFiveTimes(string $email): void
    {
        foreach (range(1, LimitLoginAttempts::MAX_ATTEMPTS) as $attempt) {
            $this->from(route('platform.login'))
                ->post(route('platform.login.auth'), ['email' => $email, 'password' => 'wrong-password']);
        }
    }

    private function administrator(string $email = 'admin@deped.local'): User
    {
        $role = Role::firstOrCreate(['slug' => User::ADMIN_ROLE_SLUG], ['name' => 'Admin', 'permissions' => []]);
        $admin = User::factory()->create(['email' => $email, 'permissions' => []]);
        $admin->replaceRoles([$role->id]);

        return $admin;
    }
}
