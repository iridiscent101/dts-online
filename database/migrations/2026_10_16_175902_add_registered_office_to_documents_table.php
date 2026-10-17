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
        if (! Schema::hasColumn('documents', 'registered_office')) {
            Schema::table('documents', function (Blueprint $table) {
                $table->string('registered_office', 180)->nullable()->after('registered_by');
            });
        }

        if (! Schema::hasIndex('documents', ['registered_office'])) {
            Schema::table('documents', function (Blueprint $table) {
                $table->index('registered_office');
            });
        }

        DB::table('documents')
            ->whereNotNull('registered_by')
            ->whereNotNull('client_submission_key')
            ->update(['registered_office' => 'SDO-Records Unit']);

        foreach (['SDO-Records Unit', 'Records Unit'] as $recordsOffice) {
            DB::table('documents')
                ->whereNull('registered_office')
                ->whereNull('client_submission_key')
                ->whereIn('registered_by', DB::table('office_user')
                    ->join('offices', 'offices.id', '=', 'office_user.office_id')
                    ->where('offices.name', $recordsOffice)
                    ->select('office_user.user_id'))
                ->update(['registered_office' => $recordsOffice]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->dropColumn('registered_office');
        });
    }
};
