<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Facades\DB;
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
        if (in_array($this->name, self::RECORDS_UNIT_NAMES, true) || $this->users()->exists()) {
            return false;
        }

        return ! Document::query()->where('current_office', $this->name)->exists()
            && ! Document::query()->where('registered_office', $this->name)->exists()
            && ! DocumentOfficeAssignment::query()->where('office', $this->name)->exists()
            && ! DocumentMovement::query()->where('from_office', $this->name)->orWhere('to_office', $this->name)->exists()
            && ! DB::table('document_route_steps')->where('office', $this->name)->exists();
    }
}
