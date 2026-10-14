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
        Schema::create('offices', function (Blueprint $table) {
            $table->id();
            $table->string('name', 180)->unique();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->unsignedSmallInteger('sdo_sort_order')->nullable();
            $table->unsignedSmallInteger('client_sort_order')->nullable();
            $table->boolean('is_sdo')->default(false);
            $table->boolean('is_client')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['is_active', 'sort_order']);
            $table->index(['is_sdo', 'sdo_sort_order']);
            $table->index(['is_client', 'client_sort_order']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('offices');
    }
};
