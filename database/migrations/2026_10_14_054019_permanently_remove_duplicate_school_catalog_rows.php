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
        foreach ([
            298 => 'Bugkalot High School',
            424 => 'Luclocos Integrated School',
            519 => 'San Juan ES',
            522 => 'San Luis ES',
            531 => 'Solano East CS',
        ] as $sourceId => $name) {
            DB::table('schools')
                ->where('source_unit_id', $sourceId)
                ->where('name', $name)
                ->whereNotNull('deleted_at')
                ->delete();
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        throw new RuntimeException('Permanently deleted school rows cannot be restored by rollback.');
    }
};
