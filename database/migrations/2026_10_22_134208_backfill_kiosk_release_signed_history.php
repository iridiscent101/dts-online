<?php

use App\Models\Document;
use App\Models\DocumentMovement;
use App\Models\KioskSigningRequest;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Releases saved with a kiosk signature before the history recorded the signing: add the missing "Release signed"
 * step, dated when the person signed. Only adds rows; a signing already in the history, or one no release used, is
 * skipped.
 */
return new class extends Migration
{
    public function up(): void
    {
        $recorded = DB::table('activity_log')
            ->where('description', 'Release signed')
            ->pluck('properties')
            ->map(fn (?string $properties): mixed => data_get(json_decode((string) $properties, true), 'kiosk_signing_request_id'))
            ->filter()
            ->map(fn (mixed $id): int => (int) $id)
            ->all();

        KioskSigningRequest::query()
            ->whereNotNull('signed_at')
            ->whereNotIn('id', $recorded)
            ->orderBy('id')
            ->get()
            // Only signings a Release actually used (same document, office and signature); others leave no step.
            // Signatures are stored encrypted, so they are compared after decrypting.
            ->filter(fn (KioskSigningRequest $signing): bool => filled($signing->recipient_signature) && DocumentMovement::query()
                ->where('document_id', $signing->document_id)
                ->where('action', DocumentMovement::ACTION_RELEASED)
                ->where('from_office', $signing->office)
                ->whereNotNull('recipient_signature')
                ->get()
                ->contains(fn (DocumentMovement $release): bool => hash_equals((string) $release->recipient_signature, (string) $signing->recipient_signature)))
            ->each(function (KioskSigningRequest $signing): void {
                DB::table('activity_log')->insert([
                    'log_name' => 'documents',
                    'description' => 'Release signed',
                    'subject_type' => (new Document)->getMorphClass(),
                    'subject_id' => $signing->document_id,
                    'causer_type' => null,
                    'causer_id' => null,
                    'properties' => json_encode([
                        'office' => $signing->office,
                        'signed_by' => $signing->recipient_name,
                        'signed_on' => 'Signing kiosk',
                        'kiosk_signing_request_id' => $signing->id,
                    ]),
                    'created_at' => $signing->signed_at,
                    'updated_at' => $signing->signed_at,
                ]);
            });
    }

    public function down(): void
    {
        // Left in place: these are true history steps, and new kiosk signings look the same, so a rollback must not
        // delete them.
    }
};
