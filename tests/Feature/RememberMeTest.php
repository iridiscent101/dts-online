<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class RememberMeTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_keep_me_signed_in_is_off_unless_ticked(): void
    {
        $html = $this->get(route('platform.login'))->assertOk()->assertSee('Keep me signed in on this computer for 12 hours')->getContent();

        $this->assertDoesNotMatchRegularExpression('/type="checkbox" name="remember"[^>]*checked/', $html);
    }

    public function test_a_ticked_box_remembers_the_person_for_twelve_hours_only(): void
    {
        User::factory()->create(['email' => 'clerk@deped.local', 'permissions' => ['platform.index' => true]]);
        $response = $this->post(route('platform.login.auth'), ['email' => 'clerk@deped.local', 'password' => 'password', 'remember' => 'true']);

        $cookie = collect($response->headers->getCookies())->first(fn ($cookie): bool => str_starts_with($cookie->getName(), 'remember_web_'));
        $this->assertNotNull($cookie, 'A remember-me cookie is set when the box is ticked.');
        $this->assertEqualsWithDelta(now()->addHours(12)->getTimestamp(), $cookie->getExpiresTime(), 60);
    }

    public function test_no_remember_cookie_when_the_box_is_left_empty(): void
    {
        User::factory()->create(['email' => 'clerk@deped.local', 'permissions' => ['platform.index' => true]]);
        $response = $this->post(route('platform.login.auth'), ['email' => 'clerk@deped.local', 'password' => 'password', 'remember' => '']);

        $this->assertAuthenticated();
        $this->assertNull(collect($response->headers->getCookies())->first(fn ($cookie): bool => str_starts_with($cookie->getName(), 'remember_web_')));
    }
}
