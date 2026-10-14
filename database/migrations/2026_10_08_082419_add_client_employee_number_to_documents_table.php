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
            // ponytail: Keep legacy values until every deployed database is checked before dropping this column.
            $table->string('client_employee_number', 32)->nullable()->after('registered_by');
            $table->uuid('client_submission_key')->nullable()->unique()->after('client_employee_number');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->dropColumn(['client_employee_number', 'client_submission_key']);
        });
    }
};
