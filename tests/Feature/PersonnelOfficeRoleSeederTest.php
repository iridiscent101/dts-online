<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\PersonnelOfficeRoleSeeder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Orchid\Platform\Models\Role;
use Tests\TestCase;

class PersonnelOfficeRoleSeederTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_it_creates_personnel_users_with_their_offices_and_role(): void
    {
        $this->seed(PersonnelOfficeRoleSeeder::class);

        $role = Role::query()->where('slug', 'personnel')->firstOrFail();

        $this->assertDatabaseCount('roles', 4); // Personnel, Records, Supervisor and Kiosk.
        $this->assertDatabaseCount('users', 22);
        $this->assertDatabaseCount('role_users', 22);
        $this->assertDatabaseHas('roles', [
            'slug' => 'personnel',
            'name' => 'Personnel',
            'description' => 'DepEd personnel assigned to an office document queue.',
        ]);
        foreach ([
            'marilou-b-mendoza@deped.local' => 'SDO-Schools Division Superintendent (SDS) Office',
            'julie-g-mamugay@deped.local' => 'SDO-School Governance and Operations Division (SGOD) Chief',
            'julie-g-mamugay-sgod-personnel@deped.local' => 'SDO-SGOD Personnel (SMME/SMAN/EFU/YFP/DRRM/HNU)',
            'jovelyn-l-masa@deped.local' => 'SDO-CID Personnel (EPS/PSDS)',
        ] as $email => $office) {
            $this->assertSame([$office], User::query()->where('email', $email)->sole()->assignedOfficeNames()->all());
        }

        $this->assertSame(17, User::query()->whereHas('roles', fn (Builder $query): Builder => $query->whereKey($role->id))->count());
        $recordsStaff = User::query()->whereHas('offices', fn (Builder $query): Builder => $query->where('name', 'SDO-Records Unit'))->get();
        $this->assertCount(5, $recordsStaff);
        foreach ($recordsStaff as $staff) {
            $this->assertTrue($staff->inRole('records-unit'));
            $this->assertTrue($staff->hasAccess('platform.documents.edit'));
            $this->assertFalse($staff->hasAccess('platform.systems.users'));
        }
    }

    public function test_it_is_idempotent_and_preserves_existing_roles(): void
    {
        $existingRole = Role::query()->create([
            'slug' => 'document-reviewer',
            'name' => 'Document Reviewer',
            'permissions' => [],
        ]);

        $existingUser = User::factory()->create([
            'name' => 'Old Name',
            'email' => 'marilou-b-mendoza@deped.local',
            'office' => 'Old Office',
        ]);
        $existingUser->roles()->syncWithoutDetaching([$existingRole->id]);

        $this->seed(PersonnelOfficeRoleSeeder::class);
        $this->seed(PersonnelOfficeRoleSeeder::class);

        $personnelRole = Role::query()->where('slug', 'personnel')->firstOrFail();
        $existingUser->refresh();

        $this->assertDatabaseCount('roles', 5); // Default roles plus the existing reviewer role.
        $this->assertDatabaseCount('users', 22);
        $this->assertDatabaseCount('role_users', 23);
        $this->assertSame('Marilou B. Mendoza', $existingUser->name);
        $this->assertEqualsCanonicalizing(['Old Office', 'SDO-Schools Division Superintendent (SDS) Office'], $existingUser->assignedOfficeNames()->all());
        $this->assertTrue($existingUser->roles()->whereKey($existingRole->id)->exists());
        $this->assertTrue($existingUser->roles()->whereKey($personnelRole->id)->exists());
    }
}
