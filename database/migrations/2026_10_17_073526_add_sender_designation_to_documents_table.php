<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The sender's designation (position), e.g. "Teacher I" or "School Head". Nullable: older documents have none.
     */
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->string('sender_designation', 120)->nullable()->after('sender');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->dropColumn('sender_designation');
        });
    }
};
