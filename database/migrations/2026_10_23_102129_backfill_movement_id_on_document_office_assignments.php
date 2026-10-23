<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Route rows created when office assignments were introduced (2026_10_03) have no movement_id, so features that follow
 * the link (the dashboard's Outgoing count, release and correction checks) skip them. Link each one to the latest step
 * that brought the document to that office, preferring the step that set its current status. Only empty links are
 * filled; a row with no such step is left as it is.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('document_office_assignments')
            ->whereNull('movement_id')
            ->orderBy('id')
            ->chunkById(200, function ($assignments): void {
                foreach ($assignments as $assignment) {
                    $steps = DB::table('document_movements')
                        ->where('document_id', $assignment->document_id)
                        ->where('to_office', $assignment->office)
                        ->orderByDesc('id');

                    $movementId = (clone $steps)->where('to_status', $assignment->status)->value('id')
                        ?? $steps->value('id');

                    if ($movementId !== null) {
                        DB::table('document_office_assignments')
                            ->where('id', $assignment->id)
                            ->whereNull('movement_id')
                            ->update(['movement_id' => $movementId]);
                    }
                }
            });
    }

    public function down(): void
    {
        // Left in place: the links are correct, and new rows are created with them anyway.
    }
};
