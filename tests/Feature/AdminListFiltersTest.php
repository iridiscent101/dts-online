<?php

namespace Tests\Feature;

use App\Models\Office;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Orchid\Platform\Models\Role;
use Tests\TestCase;

class AdminListFiltersTest extends TestCase
{
    use LazilyRefreshDatabase;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->manager = User::factory()->create([
            'name' => 'List Manager',
            'office' => 'Records Unit',
            'permissions' => ['platform.index' => true, 'platform.systems.users' => true, 'platform.systems.offices' => true],
        ]);
    }

    public function test_users_can_be_filtered_by_office_from_the_office_list_or_the_legacy_column(): void
    {
        $budget = Office::create(['name' => 'Budget Unit', 'sort_order' => 1]);
        User::factory()->create(['name' => 'Linked Budget Staff'])->offices()->attach($budget);
        User::factory()->create(['name' => 'Legacy Budget Staff', 'office' => 'Budget Unit']);
        User::factory()->create(['name' => 'Legal Staff', 'office' => 'Legal Unit']);

        $this->users(['user_office' => 'Budget Unit'])
            ->assertSee('Linked Budget Staff')
            ->assertSee('Legacy Budget Staff')
            ->assertDontSee('Legal Staff');

        $this->users(['user_office' => ''])->assertSee('Legal Staff');
    }

    public function test_account_check_finds_users_without_a_role_or_an_office(): void
    {
        $role = Role::create(['name' => 'Office User', 'slug' => 'office-user', 'permissions' => []]);
        $this->manager->roles()->attach($role);
        User::factory()->create(['name' => 'Complete Person', 'office' => 'Legal Unit'])->roles()->attach($role);
        User::factory()->create(['name' => 'Roleless Person', 'office' => 'Legal Unit']);
        User::factory()->create(['name' => 'Officeless Person', 'office' => null])->roles()->attach($role);

        $this->users(['user_account' => 'no_role'])
            ->assertSee('Roleless Person')
            ->assertDontSee('Officeless Person')
            ->assertDontSee('Complete Person');

        $this->users(['user_account' => 'no_office'])
            ->assertSee('Officeless Person')
            ->assertDontSee('Roleless Person')
            ->assertDontSee('Complete Person');
    }

    public function test_offices_can_be_filtered_by_status_and_list(): void
    {
        Office::create(['name' => 'Active SDO Desk', 'sort_order' => 1, 'is_sdo' => true, 'is_client' => false, 'is_active' => true]);
        Office::create(['name' => 'Closed Desk', 'sort_order' => 2, 'is_sdo' => true, 'is_client' => false, 'is_active' => false]);
        Office::create(['name' => 'Client Window', 'sort_order' => 3, 'is_sdo' => false, 'is_client' => true, 'is_active' => true]);

        $this->offices(['office_status' => 'inactive'])
            ->assertSee('Closed Desk')
            ->assertDontSee('Active SDO Desk');

        $this->offices(['office_list' => 'client'])
            ->assertSee('Client Window')
            ->assertDontSee('Closed Desk');

        $this->offices(['office_list' => 'sdo', 'office_status' => 'active'])
            ->assertSee('Active SDO Desk')
            ->assertDontSee('Closed Desk')
            ->assertDontSee('Client Window');
    }

    public function test_unknown_role_in_the_address_does_not_break_the_users_page(): void
    {
        $this->users(['role' => 'no-such-role'])->assertOk();
    }

    private function users(array $query)
    {
        return $this->actingAs($this->manager)->get(route('platform.systems.users', $query))->assertOk();
    }

    private function offices(array $query)
    {
        return $this->actingAs($this->manager)->get(route('platform.systems.offices', $query))->assertOk();
    }
}
