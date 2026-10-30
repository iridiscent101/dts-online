<?php

namespace Tests\Feature;

use App\Auth\AccountActivity;
use App\Models\Office;
use App\Models\User;
use App\Notifications\VerifyRecoveryEmail;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Orchid\Platform\Models\Role;
use Tests\TestCase;

class DemoRegistrationTest extends TestCase
{
    use LazilyRefreshDatabase;

    private const PASSWORD = 'Demo-Pass-2026!x';

    protected function setUp(): void
    {
        parent::setUp();
        config(['dts.demo_registration.enabled' => true, 'dts.demo_registration.email_domain' => 'test.com']);
        foreach (['personnel' => 'Personnel', 'records-unit' => 'Records Unit', User::ADMIN_ROLE_SLUG => 'Admin'] as $slug => $name) {
            Role::firstOrCreate(['slug' => $slug], ['name' => $name]);
        }
        foreach (['SDO-Records Unit', 'SDO-Budget Unit'] as $name) {
            Office::query()->updateOrCreate(['name' => $name], ['is_sdo' => true, 'is_active' => true]);
        }
    }

    private function form(array $overrides = []): array
    {
        return [
            'name' => 'Juan Dela Cruz',
            'email' => 'juan@test.com',
            'office' => 'SDO-Budget Unit',
            'role' => 'personnel',
            'recovery_email' => '',
            'password' => self::PASSWORD,
            'password_confirmation' => self::PASSWORD,
            ...$overrides,
        ];
    }

    public function test_the_page_is_hidden_unless_demo_sign_up_is_switched_on(): void
    {
        config(['dts.demo_registration.enabled' => false]);

        $this->get(route('demo.register'))->assertNotFound();
        $this->post(route('demo.register.store'), $this->form())->assertNotFound();
        $this->get(route('platform.login'))->assertDontSee('Create a demo account');
        $this->assertDatabaseMissing('users', ['email' => 'juan@test.com']);
    }

    public function test_the_page_shows_the_domain_offices_and_the_personnel_hint(): void
    {
        $this->get(route('platform.login'))->assertSee('Create a demo account');

        $this->get(route('demo.register'))
            ->assertOk()
            ->assertSee('@test.com', false)
            ->assertSee('SDO-Budget Unit')
            ->assertSee('choose <strong>Personnel</strong>', false)
            ->assertSee('Gmail for password resets');
    }

    public function test_a_participant_creates_a_personnel_account_then_signs_in_on_the_sign_in_page(): void
    {
        $this->post(route('demo.register.store'), $this->form(['email' => ' Juan@Test.com ']))
            ->assertRedirect(route('platform.login'))
            ->assertSessionHas('status', 'Your demo account is ready. Sign in with your email and the password you chose.')
            ->assertSessionHasInput('email', 'juan@test.com');

        // Not signed in yet: they sign in themselves.
        $this->assertGuest();
        $this->get(route('platform.login'))
            ->assertOk()
            ->assertSee('Your demo account is ready.')
            ->assertSee('value="juan@test.com"', false);

        $user = User::query()->where('email', 'juan@test.com')->sole();
        $this->post(route('platform.login.auth'), ['email' => 'juan@test.com', 'password' => self::PASSWORD])
            ->assertRedirect(route('platform.main'));
        $this->assertAuthenticatedAs($user);
        $this->assertSame(['personnel'], $user->roles->pluck('slug')->all());
        $this->assertSame(['SDO-Budget Unit'], $user->offices->pluck('name')->all());
        $this->assertFalse($user->isAdministrator());
        $this->assertFalse((bool) $user->must_change_password);
        $this->assertDatabaseHas('activity_log', ['log_name' => AccountActivity::LOG_NAME, 'event' => AccountActivity::DEMO_REGISTERED, 'subject_id' => $user->id]);
    }

    public function test_records_unit_staff_get_the_records_unit_role(): void
    {
        $this->post(route('demo.register.store'), $this->form(['office' => 'SDO-Records Unit', 'role' => 'records-unit']))
            ->assertRedirect(route('platform.login'));

        $this->assertSame(['records-unit'], User::query()->where('email', 'juan@test.com')->sole()->roles->pluck('slug')->all());
    }

    public function test_the_role_must_match_the_office_and_only_two_roles_are_offered(): void
    {
        foreach ([
            ['office' => 'SDO-Budget Unit', 'role' => 'records-unit'],
            ['office' => 'SDO-Records Unit', 'role' => 'personnel'],
            ['office' => 'SDO-Budget Unit', 'role' => User::ADMIN_ROLE_SLUG],
            ['office' => 'SDO-Budget Unit', 'role' => 'kiosk'],
        ] as $pair) {
            $this->from(route('demo.register'))
                ->post(route('demo.register.store'), $this->form($pair))
                ->assertRedirect(route('demo.register'))
                ->assertSessionHasErrors('role');
        }

        $this->assertDatabaseMissing('users', ['email' => 'juan@test.com']);
        $this->assertGuest();
    }

    public function test_only_demo_domain_emails_and_new_addresses_are_accepted(): void
    {
        User::factory()->create(['email' => 'taken@test.com']);

        foreach (['juan@gmail.com', 'juan@test.com.ph', 'juan@nottest.com', 'taken@test.com'] as $email) {
            $this->from(route('demo.register'))
                ->post(route('demo.register.store'), $this->form(['email' => $email]))
                ->assertSessionHasErrors('email');
        }

        $this->assertSame(1, User::query()->where('email', 'like', '%test.com%')->count());
    }

    public function test_the_gmail_for_resets_must_be_gmail_and_gets_a_verification_email(): void
    {
        Notification::fake();

        $this->from(route('demo.register'))
            ->post(route('demo.register.store'), $this->form(['recovery_email' => 'juan@yahoo.com']))
            ->assertSessionHasErrors('recovery_email');
        $this->assertDatabaseMissing('users', ['email' => 'juan@test.com']);

        $this->post(route('demo.register.store'), $this->form(['recovery_email' => 'Juan.Recovery@Gmail.com']))
            ->assertRedirect(route('platform.login'));

        $user = User::query()->where('email', 'juan@test.com')->sole();
        $this->assertSame('juan.recovery@gmail.com', $user->notification_email);
        $this->assertNull($user->notification_email_verified_at);
        Notification::assertSentTo($user, VerifyRecoveryEmail::class);
    }

    public function test_a_typed_office_must_be_picked_from_the_list(): void
    {
        foreach (['legal', 'Budget', 'SDO-Imaginary Unit', ''] as $office) {
            $this->from(route('demo.register'))
                ->post(route('demo.register.store'), $this->form(['office' => $office]))
                ->assertSessionHasErrors('office');
        }

        $this->assertDatabaseMissing('users', ['email' => 'juan@test.com']);
    }

    public function test_the_usual_password_rules_apply(): void
    {
        $this->from(route('demo.register'))
            ->post(route('demo.register.store'), $this->form(['password' => 'short', 'password_confirmation' => 'short']))
            ->assertSessionHasErrors('password');

        $this->from(route('demo.register'))
            ->post(route('demo.register.store'), $this->form(['password_confirmation' => 'Something-Else-1!']))
            ->assertSessionHasErrors('password');

        $this->assertDatabaseMissing('users', ['email' => 'juan@test.com']);
    }

    public function test_signed_in_staff_are_not_shown_the_sign_up_page(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('demo.register'))
            ->assertRedirect();
    }
}
