<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        User::onlyTrashed()->orderBy('id')->chunkById(100, function ($users): void {
            DB::transaction(function () use ($users): void {
                foreach ($users as $user) {
                    $user->forceDelete();
                }
            });
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Permanently deleted accounts cannot be restored.
    }
};
