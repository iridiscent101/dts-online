<?php

use Illuminate\Database\Migrations\Migration;
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
        Schema::create('office_user', function (Blueprint $table) {
            $table->foreignId('office_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->primary(['office_id', 'user_id']);
        });

        DB::table('users')
            ->join('offices', 'users.office', '=', 'offices.name')
            ->whereNotNull('users.office')
            ->where('users.office', '!=', '')
            ->select('users.id as user_id', 'offices.id as office_id')
            ->orderBy('users.id')
            ->get()
            ->each(function (object $assignment): void {
                DB::table('office_user')->insertOrIgnore([
                    'office_id' => $assignment->office_id,
                    'user_id' => $assignment->user_id,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('office_user');
    }
};
