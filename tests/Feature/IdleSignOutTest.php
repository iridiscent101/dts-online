<?php

namespace Tests\Feature;

use App\Http\Middleware\LogoutInactiveStaff;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class IdleSignOutTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function staff(): User
    {
        return User::factory()->create(['permissions' => ['platform.index' => true]]);
    }

    public function test_staff_pages_carry_the_idle_warning_settings(): void
    {
        config(['session.idle_minutes' => 30]);

        $this->actingAs($this->staff())
            ->get(route('platform.main'))
            ->assertOk()
            ->assertSee('<dts-idle-sign-out', false)
            ->assertSee('data-idle-seconds="1800"', false)
            ->assertSee(route('platform.session.keep-alive'), false);
    }

    public function test_active_staff_stay_signed_in(): void
    {
        $this->actingAs($this->staff())
            ->withSession([LogoutInactiveStaff::SESSION_KEY => now()->subMinutes(29)->getTimestamp()])
            ->get(route('platform.main'))
            ->assertOk();

        $this->assertAuthenticated();
    }

    public function test_idle_staff_are_signed_out_with_a_notice(): void
    {
        $this->actingAs($this->staff())
            ->withSession([LogoutInactiveStaff::SESSION_KEY => now()->subMinutes(31)->getTimestamp()])
            ->get(route('platform.main'))
            ->assertRedirectToRoute('platform.login')
            ->assertSessionHas('dts_signed_out');

        $this->assertGuest();
        $this->get(route('platform.login'))->assertSee('You were signed out because there was no activity for a while.');
    }

    public function test_notification_polling_does_not_keep_an_idle_session_alive(): void
    {
        $idleSince = now()->subMinutes(10)->getTimestamp();

        $this->actingAs($this->staff())
            ->withSession([LogoutInactiveStaff::SESSION_KEY => $idleSince])
            ->postJson(route('platform.api.notifications'))
            ->assertOk()
            ->assertSessionHas(LogoutInactiveStaff::SESSION_KEY, $idleSince);
    }

    public function test_keep_alive_records_activity(): void
    {
        $this->freezeTime();

        $this->actingAs($this->staff())
            ->withSession([LogoutInactiveStaff::SESSION_KEY => now()->subMinutes(10)->getTimestamp()])
            ->postJson(route('platform.session.keep-alive'))
            ->assertNoContent()
            ->assertSessionHas(LogoutInactiveStaff::SESSION_KEY, now()->getTimestamp());
    }

    public function test_the_page_countdown_signs_out(): void
    {
        $this->actingAs($this->staff())
            ->post(route('platform.session.idle-sign-out'))
            ->assertRedirectToRoute('platform.login');

        $this->assertGuest();
    }
}
