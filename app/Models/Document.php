<?php

namespace App\Models;

use Database\Factories\DocumentFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Orchid\Attachment\Attachable;
use Orchid\Filters\Filterable;
use Orchid\Screen\AsSource;

class Document extends Model
{
    /** @use HasFactory<DocumentFactory> */
    use AsSource, Attachable, Filterable, HasFactory;

    public const STATUSES = [
        'Awaiting receipt',
        'Forwarded',
        'Archived',
    ];

    public const TYPES = [
        'Memorandum',
        'Letter',
        'Report',
        'Request',
        'Endorsement',
        'Other',
    ];

    public const OFFICES = [
        'Records Unit',
        'Administrative Unit',
        'Curriculum Implementation Division',
    ];

    protected $fillable = [
        'tracking_number',
        'subject',
        'document_type',
        'external_reference',
        'description',
        'origin',
        'sender',
        'current_office',
        'status',
        'received_at',
        'due_at',
        'priority',
        'remarks',
        'registered_by',
    ];

    protected $allowedSorts = [
        'tracking_number',
        'subject',
        'document_type',
        'sender',
        'current_office',
        'status',
        'received_at',
        'due_at',
        'priority',
        'updated_at',
    ];

    public function scopeForSection(Builder $query, string $section): Builder
    {
        return match ($section) {
            'incoming' => $query->where('status', 'Awaiting receipt'),
            'outgoing' => $query->where('status', 'Forwarded'),
            'archived' => $query->where('status', 'Archived'),
            default => $query,
        };
    }

    /**
     * @param  array{search: string, office: string, type: string, status: string, from: string, to: string}  $filters
     */
    public function scopeRegistryFilters(Builder $query, array $filters): Builder
    {
        return $query
            ->when($filters['search'], function (Builder $query, string $search): void {
                $query->where(function (Builder $query) use ($search): void {
                    $query->where('tracking_number', 'like', "%{$search}%")
                        ->orWhere('subject', 'like', "%{$search}%");
                });
            })
            ->when($filters['office'], fn (Builder $query, string $office): Builder => $query->where('current_office', $office))
            ->when($filters['type'], fn (Builder $query, string $type): Builder => $query->where('document_type', $type))
            ->when($filters['status'], fn (Builder $query, string $status): Builder => $query->where('status', $status))
            ->when($filters['from'], fn (Builder $query, string $from): Builder => $query->whereDate('received_at', '>=', $from))
            ->when($filters['to'], fn (Builder $query, string $to): Builder => $query->whereDate('received_at', '<=', $to));
    }

    protected function casts(): array
    {
        return [
            'received_at' => 'date',
            'due_at' => 'date',
        ];
    }
}
