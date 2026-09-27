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
        Schema::create('document_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id');
            $table->string('from_office', 128)->nullable();
            $table->string('to_office', 128);
            $table->string('from_status', 32)->nullable();
            $table->string('to_status', 32);
            $table->text('remarks')->nullable();
            $table->foreignId('acted_by')->nullable();
            $table->timestamps();

            $table->index(['document_id', 'created_at']);
            $table->foreign('document_id')->references('id')->on('documents')->cascadeOnDelete();
            $table->foreign('acted_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('document_movements');
    }
};
