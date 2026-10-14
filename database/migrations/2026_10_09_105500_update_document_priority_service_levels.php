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
        DB::table('documents')->where('priority', 'Normal')->update(['priority' => 'Standard']);
        DB::table('documents')->where('priority', 'High')->update(['priority' => 'Urgent']);

        Schema::table('documents', function (Blueprint $table) {
            $table->string('priority', 16)->default('Standard')->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('documents')->where('priority', 'Standard')->update(['priority' => 'Normal']);
        DB::table('documents')->where('priority', 'Extended')->update(['priority' => 'Normal']);
        DB::table('documents')->where('priority', 'Urgent')->update(['priority' => 'High']);

        Schema::table('documents', function (Blueprint $table) {
            $table->string('priority', 16)->default('Normal')->change();
        });
    }
};
