<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

/**
 * Error boxes, field messages and error pages read like the rest of DTS.
 */
class FeedbackMessagesUiTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_admin_form_errors_use_plain_wording_and_field_names(): void
    {
        $admin = User::factory()->create(['permissions' => [
            'platform.index' => true,
            'platform.systems.offices' => true,
        ]]);

        $this->actingAs($admin)
            ->followingRedirects()
            ->from(route('platform.systems.offices.create'))
            ->post(route('platform.systems.offices.create').'/save', ['office' => ['name' => '', 'sort_order' => 1]])
            ->assertOk()
            ->assertSee('class="alert alert-danger dts-form-errors mb-3"', false)
            ->assertSee('Please fix this before saving:')
            ->assertSee('The office name field is required.')
            ->assertDontSee('Oh snap!')
            ->assertDontSee('office.name field');
    }

    public function test_guests_on_an_error_page_are_sent_to_the_tracking_page(): void
    {
        $this->get('/this-page-does-not-exist')
            ->assertNotFound()
            ->assertSee('Page not found')
            ->assertSee('Go to the tracking page')
            ->assertDontSee('Return to dashboard')
            ->assertSee('Docu<span>TrackS</span>', false);
    }

    public function test_signed_in_staff_on_an_error_page_go_back_to_the_dashboard(): void
    {
        $user = User::factory()->create(['permissions' => ['platform.index' => true]]);

        $this->actingAs($user)
            ->get(route('platform.documents.view', 999999))
            ->assertNotFound()
            ->assertSee('Return to dashboard');
    }

    public function test_expired_and_rate_limited_pages_have_their_own_wording(): void
    {
        $this->assertStringContainsString('Page expired', view('errors.419')->render());
        $this->assertStringContainsString('Too many requests', view('errors.429')->render());
        $this->assertStringContainsString('Briefly unavailable', view('errors.503')->render());
    }
}
