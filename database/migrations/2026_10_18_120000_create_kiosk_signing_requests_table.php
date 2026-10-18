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
        // A Records clerk inside the office asks the recipient to sign on the kiosk laptop outside; the kiosk saves the
        // name and signature here and the clerk's Release form picks them up.
        Schema::create('kiosk_signing_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id')->constrained()->cascadeOnDelete();
            $table->foreignId('requested_by')->constrained('users')->cascadeOnDelete();
            $table->string('office', 180);
            $table->string('status', 20)->default('pending');
            $table->string('recipient_name')->nullable();
            $table->longText('recipient_signature')->nullable();
            $table->timestamp('signed_at')->nullable();
            $table->dateTime('expires_at');
            $table->timestamps();

            $table->index(['status', 'expires_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('kiosk_signing_requests');
    }
};
