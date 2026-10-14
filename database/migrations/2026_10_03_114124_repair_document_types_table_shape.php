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
        if (! Schema::hasTable('document_types')) {
            return;
        }

        if (! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }

        if (! Schema::hasColumn('document_types', 'id')) {
            DB::statement('ALTER TABLE document_types ADD COLUMN id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY FIRST');
        }

        DB::statement(<<<'SQL'
            DELETE duplicate_document_types
            FROM document_types duplicate_document_types
            INNER JOIN document_types original_document_types
                ON duplicate_document_types.name = original_document_types.name
                AND duplicate_document_types.id > original_document_types.id
            SQL);

        DB::statement('ALTER TABLE document_types MODIFY name VARCHAR(180) NOT NULL');
        DB::statement('ALTER TABLE document_types MODIFY sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0');
        DB::statement('ALTER TABLE document_types MODIFY is_active TINYINT(1) NOT NULL DEFAULT 1');

        if (! $this->indexExists('document_types_name_unique')) {
            DB::statement('ALTER TABLE document_types ADD UNIQUE document_types_name_unique (name)');
        }

        if (! $this->indexExists('document_types_is_active_sort_order_index')) {
            DB::statement('ALTER TABLE document_types ADD INDEX document_types_is_active_sort_order_index (is_active, sort_order)');
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
        return collect(DB::select('SHOW INDEX FROM document_types'))
            ->contains(fn (object $index): bool => $index->Key_name === $indexName);
    }
};
