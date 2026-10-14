<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::table('activity_log')
            ->where('subject_type', 'App\\Models\\Document')
            ->whereIn('description', [
                'Receipt acknowledged',
                'Document forwarded',
                'Document archived',
                'Document restored',
            ])
            ->delete();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void {}
};
