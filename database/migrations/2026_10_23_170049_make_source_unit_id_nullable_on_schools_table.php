<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Schools added by staff are not in the DepEd catalog, so they have no source unit ID. The unique index stays: catalog
 * schools still cannot share an ID, and empty IDs do not clash with each other.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('schools', function (Blueprint $table) {
            $table->unsignedSmallInteger('source_unit_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Rolling back cannot give staff-added schools a catalog ID, so they would block the NOT NULL change.
        Schema::table('schools', function (Blueprint $table) {
            $table->unsignedSmallInteger('source_unit_id')->nullable(false)->change();
        });
    }
};
