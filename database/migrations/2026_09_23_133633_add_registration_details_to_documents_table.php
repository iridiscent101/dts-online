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
            $table->string('external_reference', 100)->nullable()->after('document_type');
            $table->text('description')->nullable()->after('external_reference');
            $table->string('origin', 180)->after('description');
            $table->text('remarks')->nullable()->after('priority');
            $table->foreignId('registered_by')->nullable()->after('remarks')->constrained('users')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->dropConstrainedForeignId('registered_by');
            $table->dropColumn(['external_reference', 'description', 'origin', 'remarks']);
        });
    }
};
