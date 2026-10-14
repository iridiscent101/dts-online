<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Orchid\Platform\Models\Role;

class AdminUserSeeder extends Seeder
{
    /**
     * Seed the default administrator account and the Admin role that grants it full access.
     */
    public function run(): void
    {
        // User::isAdministrator() looks for this role slug; holding it grants every permission.
        $role = Role::query()->firstOrCreate(
            ['slug' => User::ADMIN_ROLE_SLUG],
            ['name' => 'Admin', 'description' => 'Full system access.', 'permissions' => []],
        );

        $password = config('services.admin_seed.password');
        $admin = User::query()->where('email', 'admin@test.com')->first();

        if (blank($password)) {
            if ($admin === null) {
                $this->command?->warn('Skipping admin@test.com: set ADMIN_SEEDER_PASSWORD before running this seeder.');

                return;
            }

            // Existing account: give it the Admin role but leave its password alone.
            $admin->roles()->syncWithoutDetaching([$role->id]);

            return;
        }

        $admin = User::query()->updateOrCreate(
            ['email' => 'admin@test.com'],
            [
                'name' => 'Admin User',
                'password' => Hash::make((string) $password),
                'email_verified_at' => now(),
            ],
        );

        $admin->roles()->syncWithoutDetaching([$role->id]);
    }
}
