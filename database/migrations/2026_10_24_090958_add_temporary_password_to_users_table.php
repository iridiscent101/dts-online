<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Forgot password keeps the temporary password apart from the real one, so asking for a colleague's reset does not
     * lock them out: both work until one of them is used to sign in, and the temporary one expires.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('temporary_password')->nullable()->after('password');
            $table->timestamp('temporary_password_expires_at')->nullable()->after('temporary_password');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['temporary_password', 'temporary_password_expires_at']);
        });
    }
};
