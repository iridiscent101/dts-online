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
            $table->dropIndex('documents_office_status_due_index');
            $table->dropColumn(['due_at', 'client_employee_number']);
        });

        Schema::table('document_route_steps', function (Blueprint $table) {
            $table->dropIndex('document_route_steps_document_id_sequence_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->date('due_at')->nullable();
            $table->string('client_employee_number', 32)->nullable();
            $table->index(['current_office', 'status', 'due_at'], 'documents_office_status_due_index');
        });

        Schema::table('document_route_steps', function (Blueprint $table) {
            $table->index(['document_id', 'sequence'], 'document_route_steps_document_id_sequence_index');
        });
    }
};
