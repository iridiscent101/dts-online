<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->date('received_at')->nullable()->change();
        });

        DB::table('documents')
            ->where('status', 'Awaiting physical document')
            ->whereNotNull('client_submission_key')
            ->update(['received_at' => null]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::table('documents')->whereNull('received_at')->exists()) {
            throw new RuntimeException('Cannot restore a required received date while paper documents remain unreceived.');
        }

        Schema::table('documents', function (Blueprint $table) {
            $table->date('received_at')->change();
        });
    }
};
