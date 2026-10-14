<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->index('received_at', 'documents_received_at_index');
            $table->index(
                ['current_office', 'status', 'received_at'],
                'documents_office_status_received_index',
            );
            $table->index(
                ['current_office', 'status', 'due_at'],
                'documents_office_status_due_index',
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->dropIndex('documents_received_at_index');
            $table->dropIndex('documents_office_status_received_index');
            $table->dropIndex('documents_office_status_due_index');
        });
    }
};
