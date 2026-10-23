<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class DashboardDescriptionsTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_each_tab_explains_what_it_lists(): void
    {
        $user = User::factory()->create(['office' => 'SDO-Records Unit', 'permissions' => ['platform.index' => true]]);

        foreach ([
            'platform.incoming' => 'Documents sent to your office that are waiting to be received.',
            'platform.outgoing' => 'Documents your office sent on to other offices that are still being processed.',
            'platform.archived' => 'Documents that are completed, released or archived.',
            'platform.documents' => 'Every document your office is working on or has handled.',
        ] as $route => $description) {
            $this->actingAs($user)->get(route($route))
                ->assertOk()
                ->assertSee($description)
                ->assertDontSee('Department of Education | Records Management');
        }
    }
}
