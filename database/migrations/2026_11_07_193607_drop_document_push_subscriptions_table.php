<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('document_push_subscriptions');
    }

    public function down(): void
    {
        Schema::create('document_push_subscriptions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('document_id')->constrained()->cascadeOnDelete();
            $table->string('token', 512);
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();
            $table->unique(['document_id', 'token']);
            $table->index('token');
        });
    }
};
