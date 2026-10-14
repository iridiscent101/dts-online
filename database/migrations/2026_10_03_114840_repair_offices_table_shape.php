<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (! Schema::hasTable('offices')) {
            return;
        }

        if (! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }

        DB::statement('ALTER TABLE offices MODIFY sdo_sort_order SMALLINT UNSIGNED NULL');
        DB::statement('ALTER TABLE offices MODIFY client_sort_order SMALLINT UNSIGNED NULL');

        if (! $this->indexExists('offices_is_sdo_sdo_sort_order_index')) {
            DB::statement('ALTER TABLE offices ADD INDEX offices_is_sdo_sdo_sort_order_index (is_sdo, sdo_sort_order)');
        }

        if (! $this->indexExists('offices_is_client_client_sort_order_index')) {
            DB::statement('ALTER TABLE offices ADD INDEX offices_is_client_client_sort_order_index (is_client, client_sort_order)');
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Intentionally irreversible; this repairs the table shape expected by the application.
    }

    private function indexExists(string $indexName): bool
    {
        return collect(DB::select('SHOW INDEX FROM offices'))
            ->contains(fn (object $index): bool => $index->Key_name === $indexName);
    }
};
