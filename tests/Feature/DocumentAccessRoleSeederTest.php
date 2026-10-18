<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\DocumentAccessRoleSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Orchid\Platform\Models\Role;
use Tests\TestCase;

class DocumentAccessRoleSeederTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_roles_grant_their_intended_access_only_when_assigned_to_the_right_office(): void
    {
        $this->seed(DocumentAccessRoleSeeder::class);

        $this->assertDatabaseCount('roles', 4); // includes the Kiosk role from the 2026_10_18 migration
        $this->assertDatabaseCount('users', 0);

        $personnel = User::factory()->create(['office' => 'SDO-Budget Unit', 'permissions' => []]);
        $personnel->replaceRoles([Role::query()->where('slug', 'personnel')->sole()->id]);
        $records = User::factory()->create(['office' => 'SDO-Records Unit', 'permissions' => []]);
        $records->replaceRoles([Role::query()->where('slug', 'records-unit')->sole()->id]);
        $otherOfficeRecords = User::factory()->create(['office' => 'SDO-Budget Unit', 'permissions' => []]);
        $otherOfficeRecords->replaceRoles([Role::query()->where('slug', 'records-unit')->sole()->id]);
        $supervisor = User::factory()->create(['office' => 'SDO-Records Unit', 'permissions' => []]);
        $supervisor->replaceRoles([Role::query()->where('slug', 'supervisor')->sole()->id]);

        $this->assertTrue($personnel->hasAccess('platform.index'));
        $this->assertFalse($personnel->hasAccess('platform.documents.view-all'));
        $this->assertFalse($personnel->canConfirmPaperIntake());

        $this->assertTrue($records->hasAccess('platform.documents.edit'));
        $this->assertTrue($records->hasAccess('platform.documents.routes.manage'));
        $this->assertFalse($records->hasAccess('platform.systems.users'));
        $this->assertTrue($records->canConfirmPaperIntake());
        $this->assertFalse($otherOfficeRecords->canConfirmPaperIntake());

        $this->assertTrue($supervisor->hasAccess('platform.documents.view-all'));
        $this->assertTrue($supervisor->isReadOnlySupervisor());
        $this->assertFalse($supervisor->canConfirmPaperIntake());
    }

    public function test_seeding_roles_twice_creates_no_duplicate_roles_or_users(): void
    {
        $this->seed(DocumentAccessRoleSeeder::class);
        $this->seed(DocumentAccessRoleSeeder::class);

        $this->assertDatabaseCount('roles', 4); // includes the Kiosk role from the 2026_10_18 migration
        $this->assertDatabaseCount('users', 0);
    }
}
