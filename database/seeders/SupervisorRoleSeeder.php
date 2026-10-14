<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Orchid\Platform\Models\Role;

class SupervisorRoleSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        Role::query()->firstOrCreate(
            ['slug' => 'supervisor'],
            [
                'name' => 'Supervisor',
                'description' => 'Read-only oversight of documents across all offices.',
                'permissions' => [
                    'platform.index' => true,
                    'platform.documents.view-all' => true,
                ],
            ],
        );
    }
}
