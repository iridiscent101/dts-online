<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Adds a "Kiosk" role for the laptop outside the Records Unit: create a user in Users, give it only this role, and
     * sign in with it at /kiosk. The role has only the "Signing kiosk" permission.
     */
    public function up(): void
    {
        if (DB::table('roles')->where('slug', 'kiosk')->exists()) {
            return;
        }

        DB::table('roles')->insert([
            'slug' => 'kiosk',
            'name' => 'Kiosk',
            'permissions' => json_encode(['platform.kiosk' => true]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Remove the role again, unless a user still has it.
     */
    public function down(): void
    {
        $roleId = DB::table('roles')->where('slug', 'kiosk')->value('id');
        if ($roleId === null || DB::table('role_users')->where('role_id', $roleId)->exists()) {
            return;
        }

        DB::table('roles')->where('id', $roleId)->delete();
    }
};
