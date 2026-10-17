<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $usersWithoutMemberships = DB::table('users')
            ->whereNotNull('office')
            ->where('office', '!=', '')
            ->whereNotExists(fn (QueryBuilder $query): QueryBuilder => $query->selectRaw('1')
                ->from('office_user')
                ->whereColumn('office_user.user_id', 'users.id'))
            ->get(['id', 'office']);

        foreach ($usersWithoutMemberships as $user) {
            $officeId = DB::table('offices')->where('name', $user->office)->value('id');

            if ($officeId === null) {
                throw new RuntimeException("Cannot migrate user {$user->id}: office {$user->office} does not exist.");
            }

            DB::table('office_user')->insertOrIgnore([
                'office_id' => $officeId,
                'user_id' => $user->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex('users_office_index');
            $table->dropColumn('office');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('office', 180)->nullable();
            $table->index('office');
        });

        DB::table('office_user')
            ->join('offices', 'offices.id', '=', 'office_user.office_id')
            ->orderBy('office_user.office_id')
            ->get(['office_user.user_id', 'offices.name'])
            ->unique('user_id')
            ->each(fn (object $assignment): int => DB::table('users')
                ->where('id', $assignment->user_id)
                ->update(['office' => $assignment->name]));
    }
};
