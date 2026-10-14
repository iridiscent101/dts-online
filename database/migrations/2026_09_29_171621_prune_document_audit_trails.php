<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $documentActivities = DB::table('activity_log')
            ->where('subject_type', 'App\\Models\\Document');

        $documentActivities
            ->clone()
            ->whereIn('description', [
                'Document viewed',
                'Attachment previewed',
                'Attachment downloaded',
            ])
            ->delete();

        $documentIds = $documentActivities
            ->clone()
            ->whereNotNull('subject_id')
            ->distinct()
            ->pluck('subject_id');

        foreach ($documentIds as $documentId) {
            $expiredActivityIds = DB::table('activity_log')
                ->where('subject_type', 'App\\Models\\Document')
                ->where('subject_id', $documentId)
                ->latest('id')
                ->pluck('id')
                ->slice(20);

            DB::table('activity_log')
                ->whereIn('id', $expiredActivityIds)
                ->delete();
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void {}
};
