<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Orchid\Filters\Filterable;
use Orchid\Filters\Types\Like;
use Orchid\Filters\Types\Where;
use Orchid\Screen\AsSource;

class Office extends Model
{
    use AsSource, Filterable;

    public const RECORDS_UNIT_NAME = 'SDO-Records Unit';

    public const RECORDS_UNIT_NAMES = [self::RECORDS_UNIT_NAME, 'Records Unit'];

    protected $fillable = [
        'name',
        'sort_order',
        'sdo_sort_order',
        'client_sort_order',
        'is_sdo',
        'is_client',
        'is_active',
    ];

    /**
     * @var array<string, class-string>
     */
    protected $allowedFilters = [
        'name' => Like::class,
        'is_sdo' => Where::class,
        'is_client' => Where::class,
        'is_active' => Where::class,
    ];

    /**
     * @var list<string>
     */
    protected $allowedSorts = [
        'name',
        'sort_order',
        'sdo_sort_order',
        'client_sort_order',
        'is_active',
        'updated_at',
    ];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'sdo_sort_order' => 'integer',
            'client_sort_order' => 'integer',
            'is_sdo' => 'boolean',
            'is_client' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withTimestamps();
    }

    public function canBeDeleted(): bool
    {
        return ! in_array($this->name, self::RECORDS_UNIT_NAMES, true);
    }

    public function deleteAndTransferDocuments(User $actor): void
    {
        DB::transaction(function () use ($actor): void {
            $office = self::query()->lockForUpdate()->findOrFail($this->id);
            if (! $office->canBeDeleted()) {
                throw ValidationException::withMessages(['selected' => 'The Records intake office cannot be deleted.']);
            }

            $documents = Document::query()
                ->where(fn ($query) => $query->where('current_office', $office->name)
                    ->orWhereHas('officeAssignments', fn ($assignments) => $assignments->where('office', $office->name))
                    ->orWhereIn('id', DB::table('document_route_steps')->select('document_id')->where('office', $office->name)))
                ->orderBy('id')->lockForUpdate()->get();

            foreach ($documents as $document) {
                $reason = "The office {$office->name} was deleted. Its pending work was transferred to Records Unit.";
                $planned = DB::table('document_route_steps')->where('document_id', $document->id)->where('office', $office->name);
                if ((clone $planned)->exists()) {
                    $instructions = (clone $planned)->orderBy('sequence')->pluck('remarks')->filter()->implode("\n");
                    if (DB::table('document_route_steps')->where('document_id', $document->id)->where('office', self::RECORDS_UNIT_NAME)->exists()) {
                        $planned->delete();
                    } else {
                        $planned->update(['office' => self::RECORDS_UNIT_NAME, 'updated_at' => now()]);
                    }
                    $document->recordActivity('Document route deleted', $actor, [
                        'from_office' => $office->name, 'to_office' => self::RECORDS_UNIT_NAME,
                        'deleted_office' => $office->name,
                        'reason' => $reason.($instructions !== '' ? "\nOriginal routing instructions: ".$instructions : ''),
                    ]);
                }

                $assignment = $document->officeAssignments()->where('office', $office->name)->first();
                $sourceStatus = $assignment?->status ?? $document->status;
                if (in_array($sourceStatus, DocumentMovement::FINISHED_STATUSES, true)
                    || ($assignment === null && $document->current_office !== $office->name)) {
                    continue;
                }

                $records = $document->officeAssignments()->whereIn('office', self::RECORDS_UNIT_NAMES)->orderByDesc('office')->first();
                $recordsOffice = $records?->office ?? self::RECORDS_UNIT_NAME;
                $movement = $document->movements()->create([
                    'action' => DocumentMovement::ACTION_FORWARDED,
                    'from_office' => $office->name, 'to_office' => $recordsOffice,
                    'from_status' => $sourceStatus, 'to_status' => DocumentMovement::STATUS_FORWARDED,
                    'remarks' => $reason, 'acted_by' => $actor->id,
                ]);
                if ($records === null || in_array($records->status, DocumentMovement::FINISHED_STATUSES, true)) {
                    $records = $document->officeAssignments()->updateOrCreate(['office' => $recordsOffice], [
                        'status' => DocumentMovement::STATUS_FORWARDED, 'movement_id' => $movement->id, 'assigned_by' => $actor->id,
                    ]);
                }
                $assignment?->delete();
                KioskSigningRequest::query()->where('document_id', $document->id)
                    ->whereIn('status', [KioskSigningRequest::STATUS_PENDING, KioskSigningRequest::STATUS_SIGNED])
                    ->update(['status' => KioskSigningRequest::STATUS_CANCELLED, 'recipient_signature' => null, 'signed_at' => null]);
                if ($document->current_office === $office->name) {
                    $document->update(['current_office' => $recordsOffice, 'status' => $records->status]);
                }
            }

            $office->delete();
        });
    }
}
