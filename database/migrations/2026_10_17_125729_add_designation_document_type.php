<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Adds "Designation" as a document type, listed with "Designation Orders" (same sort order; names break the tie).
     */
    public function up(): void
    {
        // Only a database whose types were already seeded gets it here; an empty table keeps using the built-in list
        // until DocumentTypeSeeder (which now includes "Designation") fills it.
        if (! Schema::hasTable('document_types')
            || ! DB::table('document_types')->exists()
            || DB::table('document_types')->where('name', 'Designation')->exists()) {
            return;
        }

        DB::table('document_types')->insert([
            'name' => 'Designation',
            'sort_order' => (int) (DB::table('document_types')->where('name', 'Designation Orders')->value('sort_order') ?? 0),
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Remove the type again, unless documents already use it.
     */
    public function down(): void
    {
        if (Schema::hasTable('documents') && DB::table('documents')->where('document_type', 'Designation')->exists()) {
            return;
        }

        DB::table('document_types')->where('name', 'Designation')->delete();
    }
};
