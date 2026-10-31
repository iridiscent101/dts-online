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
            'platform.incoming' => 'Documents awaiting receipt.',
            'platform.outgoing' => 'Forwarded documents still in progress.',
            'platform.archived' => 'Documents that are completed, released or archived.',
            'platform.documents' => 'Document records and routing status.',
        ] as $route => $description) {
            $this->actingAs($user)->get(route($route))
                ->assertOk()
                ->assertSee($description)
                ->assertDontSee('Department of Education | Records Management');
        }
    }
}
