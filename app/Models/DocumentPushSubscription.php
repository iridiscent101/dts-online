<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A client's phone or browser that asked for push updates on one document (tracking or receipt page).
 */
class DocumentPushSubscription extends Model
{
    /** One document can be followed from a few phones; this stops one tracking number collecting endless tokens. */
    public const MAX_PER_DOCUMENT = 10;

    protected $fillable = ['document_id', 'token', 'last_used_at'];

    protected function casts(): array
    {
        return ['last_used_at' => 'datetime'];
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }
}
