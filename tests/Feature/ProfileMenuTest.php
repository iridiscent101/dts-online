<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\Office;
use App\Models\User;
use App\Notifications\DocumentAssignedNotification;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Orchid\Platform\Models\Role;
use Tests\TestCase;

class ProfileMenuTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_sidebar_profile_menu_offers_settings_fast_track_and_logout(): void
    {
        $user = User::factory()->create(['permissions' => ['platform.index' => true]]);

        $response = $this->actingAs($user)
            ->get(route('platform.main'))
            ->assertOk()
            ->assertSee('<dts-profile-menu', false)
            ->assertSee('data-profile-menu-toggle', false)
            ->assertSee('aria-controls="profile-menu"', false)
            ->assertSee('data-profile-menu-panel hidden', false)
            ->assertSee('Settings')
            ->assertSee('Fast Track page')
            ->assertSee('Logout')
            ->assertSee('href="'.route('platform.profile').'"', false)
            ->assertSee('href="'.route('tracking.index').'"', false);

        $response->assertSee(
            '<form method="POST" action="'.route('platform.logout').'"',
            false,
        );
    }

    public function test_my_account_shows_offices_roles_and_access(): void
    {
        $office = Office::create(['name' => 'SDO-Budget Unit', 'sort_order' => 1]);
        $role = Role::create(['name' => 'Office User', 'slug' => 'office-user', 'permissions' => []]);
        $user = User::factory()->create(['permissions' => ['platform.index' => true]]);
        $user->offices()->attach($office);
        $user->roles()->attach($role);

        $this->actingAs($user)
            ->get(route('platform.profile'))
            ->assertOk()
            ->assertSee('Account access')
            ->assertSee('SDO-Budget Unit')
            ->assertSee('Office User')
            ->assertSee('Work on documents assigned to your offices');

        $newcomer = User::factory()->create(['permissions' => ['platform.index' => true]]);

        $this->actingAs($newcomer)
            ->get(route('platform.profile'))
            ->assertSee('Not assigned to an office yet')
            ->assertSee('No role yet');
    }

    public function test_guests_are_redirected_instead_of_seeing_the_profile_menu(): void
    {
        $this->get(route('platform.main'))
            ->assertRedirectToRoute('platform.login');
    }

    public function test_notification_badge_starts_with_the_signed_in_users_count(): void
    {
        $notifiedUser = User::factory()->create(['permissions' => ['platform.index' => true]]);
        $otherUser = User::factory()->create(['permissions' => ['platform.index' => true]]);
        $document = Document::factory()->create();
        $notifiedUser->notify(new DocumentAssignedNotification($document, 'register', 'System'));

        $this->actingAs($notifiedUser)
            ->get(route('platform.main'))
            ->assertOk()
            ->assertSee('data-dts-notification-count="1"', false);
        $this->post(route('platform.api.notifications'))
            ->assertOk()
            ->assertJsonPath('total', 1);

        $this->actingAs($otherUser)
            ->get(route('platform.main'))
            ->assertOk()
            ->assertSee('data-dts-notification-count="0"', false)
            ->assertDontSee('data-controller="notification"', false);
        $this->post(route('platform.api.notifications'))
            ->assertOk()
            ->assertJsonPath('total', 0);
    }

    public function test_logout_action_from_the_sidebar_signs_the_user_out(): void
    {
        $user = User::factory()->create(['permissions' => ['platform.index' => true]]);

        $this->actingAs($user)
            ->post(route('platform.logout'))
            ->assertRedirect(route('tracking.index'));

        $this->assertGuest();
    }
}
