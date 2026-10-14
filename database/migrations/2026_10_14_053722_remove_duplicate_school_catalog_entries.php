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
        $duplicates = [
            298 => 'Bugkalot High School',
            424 => 'Luclocos Integrated School',
            519 => 'San Juan ES',
            522 => 'San Luis ES',
            531 => 'Solano East CS',
        ];

        foreach ($duplicates as $sourceId => $name) {
            DB::table('schools')
                ->where('source_unit_id', $sourceId)
                ->where('name', $name)
                ->whereNull('deleted_at')
                ->update(['deleted_at' => now()]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Restoring catalog duplicates on rollback would undo the correction.
    }
};
