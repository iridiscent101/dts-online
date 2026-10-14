<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class RateLimitingTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_client_submissions_allow_twenty_a_minute_from_one_connection(): void
    {
        // Rejected submissions still count, so empty ones keep this test quick.
        foreach (range(1, 20) as $attempt) {
            $this->post(route('submission.store'))->assertRedirect();
        }

        $this->post(route('submission.store'))->assertTooManyRequests();

        $this->travel(61)->seconds();
        $this->post(route('submission.store'))->assertRedirect();
    }

    public function test_staff_background_requests_allow_one_hundred_twenty_a_minute_per_user(): void
    {
        $clerk = User::factory()->create(['office' => 'SDO-Records Unit', 'permissions' => ['platform.index' => true]]);
        $colleague = User::factory()->create(['office' => 'SDO-Records Unit', 'permissions' => ['platform.index' => true]]);
        $lookup = route('platform.documents.intake', ['tracking' => 'DTS-2026-MISSING']);

        foreach (range(1, 120) as $attempt) {
            $this->actingAs($clerk)->getJson($lookup)->assertNotFound();
        }
        $this->actingAs($clerk)->getJson($lookup)->assertTooManyRequests();

        // The limit is per person, and ordinary page loads are not counted.
        $this->actingAs($colleague)->getJson($lookup)->assertNotFound();
        $this->actingAs($clerk)->get(route('platform.main'))->assertOk();
    }
}
