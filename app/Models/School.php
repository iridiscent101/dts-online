<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\SchoolFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Orchid\Filters\Filterable;
use Orchid\Filters\Types\Like;
use Orchid\Screen\AsSource;

class School extends Model
{
    /** @use HasFactory<SchoolFactory> */
    use AsSource, Filterable, HasFactory, SoftDeletes;

    protected $fillable = [
        'source_unit_id',
        'name',
    ];

    /**
     * @var array<string, class-string>
     */
    protected $allowedFilters = [
        'name' => Like::class,
    ];

    /**
     * @var list<string>
     */
    protected $allowedSorts = [
        'source_unit_id',
        'name',
        'updated_at',
    ];

    protected function casts(): array
    {
        return [
            'source_unit_id' => 'integer',
        ];
    }
}
