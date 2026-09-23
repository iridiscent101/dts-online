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
        Schema::create('documents', function (Blueprint $table) {
            $table->id();
            $table->string('tracking_number', 32)->unique();
            $table->string('subject');
            $table->string('document_type', 64);
            $table->string('sender');
            $table->string('current_office', 128);
            $table->string('status', 32);
            $table->date('received_at');
            $table->date('due_at')->nullable();
            $table->string('priority', 16)->default('Normal');
            $table->timestamps();

            $table->index(['status', 'received_at']);
            $table->index('current_office');
            $table->index('document_type');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('documents');
    }
};
