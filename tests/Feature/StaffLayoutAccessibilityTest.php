<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

/**
 * Staff screens hide their visible title for a compact look, so the layout must still give screen readers a page
 * heading, a main landmark and a skip link.
 */
class StaffLayoutAccessibilityTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_staff_pages_have_a_heading_main_landmark_and_skip_link(): void
    {
        $admin = User::factory()->create(['permissions' => [
            'platform.index' => true,
            'platform.systems.users' => true,
            'platform.systems.schools' => true,
            'platform.systems.sign-in-activity' => true,
        ]]);

        $pages = [
            'platform.systems.users' => 'User management',
            'platform.systems.schools' => 'School management',
            'platform.systems.sign-in-activity' => 'Sign-in activity',
        ];

        foreach ($pages as $route => $title) {
            $html = $this->actingAs($admin)->get(route($route))->assertOk()->getContent();

            $this->assertStringContainsString('<a class="visually-hidden-focusable dts-skip-link" href="#main-content">', $html, $route);
            $this->assertStringContainsString('<main id="main-content"', $html, $route);
            $this->assertMatchesRegularExpression('/<h1 class="visually-hidden">\s*'.preg_quote($title, '/').'\s*<\/h1>/', $html, $route);
            $this->assertSame(1, substr_count($html, '<h1'), $route.' has more than one h1');
        }
    }

    public function test_sign_in_activity_uses_the_shared_admin_table_page(): void
    {
        $admin = User::factory()->create(['permissions' => [
            'platform.index' => true,
            'platform.systems.sign-in-activity' => true,
        ]]);

        $this->actingAs($admin)
            ->get(route('platform.systems.sign-in-activity'))
            ->assertOk()
            ->assertSee('id="admin-sign-in-activity-table"', false)
            ->assertSee('Rows per page')
            ->assertSee('Search sign-in activity')
            ->assertSee('What happened');
    }
}
