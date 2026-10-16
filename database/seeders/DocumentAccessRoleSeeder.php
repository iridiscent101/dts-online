<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Orchid\Platform\Models\Role;

class DocumentAccessRoleSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        Role::query()->firstOrCreate(
            ['slug' => 'personnel'],
            [
                'name' => 'Personnel',
                'description' => 'DepEd personnel assigned to an office document queue.',
                'permissions' => ['platform.index' => true],
            ],
        );

        Role::query()->firstOrCreate(
            ['slug' => 'records-unit'],
            [
                'name' => 'Records Unit',
                'description' => 'Records counter intake and document corrections for assigned Records Unit staff.',
                'permissions' => [
                    'platform.index' => true,
                    'platform.documents.edit' => true,
                    'platform.documents.routes.manage' => true,
                ],
            ],
        );

        $this->call(SupervisorRoleSeeder::class);
    }
}
