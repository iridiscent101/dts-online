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
            $table->string('current_office', 180)->change();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->string('office', 180)->nullable()->change();
        });

        Schema::table('document_movements', function (Blueprint $table) {
            $table->string('from_office', 180)->nullable()->change();
            $table->string('to_office', 180)->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Shrinking these columns could truncate office names written after this migration.
    }
};
