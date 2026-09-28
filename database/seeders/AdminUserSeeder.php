<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class AdminUserSeeder extends Seeder
{
    /**
     * Seed the default administrator account.
     */
    public function run(): void
    {
        $password = config('services.admin_seed.password');

        if (blank($password)) {
            $this->command?->warn('Skipping admin@test.com: set ADMIN_SEEDER_PASSWORD before running this seeder.');

            return;
        }

        User::query()->updateOrCreate(
            ['email' => 'admin@test.com'],
            [
                'name' => 'Admin User',
                'password' => Hash::make((string) $password),
                'email_verified_at' => now(),
                'permissions' => [
                    'platform.index' => true,
                    'platform.systems.users' => true,
                    'platform.systems.roles' => true,
                ],
            ],
        );
    }
}
