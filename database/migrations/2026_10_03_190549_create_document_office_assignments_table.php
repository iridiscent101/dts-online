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
        Schema::create('document_office_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id')->constrained()->cascadeOnDelete();
            $table->string('office', 180);
            $table->string('status', 32);
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('movement_id')->nullable()->constrained('document_movements')->nullOnDelete();
            $table->timestamps();

            $table->unique(['document_id', 'office']);
            $table->index(['office', 'status']);
            $table->index(['document_id', 'status']);
        });

        DB::table('documents')
            ->select(['id', 'current_office', 'status', 'registered_by', 'created_at', 'updated_at'])
            ->orderBy('id')
            ->chunk(200, function ($documents): void {
                $assignments = $documents->map(fn ($document): array => [
                    'document_id' => $document->id,
                    'office' => $document->current_office,
                    'status' => $document->status,
                    'assigned_by' => $document->registered_by,
                    'movement_id' => null,
                    'created_at' => $document->created_at,
                    'updated_at' => $document->updated_at,
                ])->all();

                DB::table('document_office_assignments')->insertOrIgnore($assignments);
            });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('document_office_assignments');
    }
};
