<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Client push notifications: a phone or browser (its Firebase Cloud Messaging token) that asked for updates on one
 * document from the tracking or receipt page. Clients do not sign in, so the link is to the document, not a user.
 * A token Firebase reports as no longer valid is deleted when a push to it fails.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_push_subscriptions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('document_id')->constrained()->cascadeOnDelete();
            // FCM tokens are long (~160 characters, no fixed maximum).
            $table->string('token', 512);
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();
            $table->unique(['document_id', 'token']);
            $table->index('token');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_push_subscriptions');
    }
};
