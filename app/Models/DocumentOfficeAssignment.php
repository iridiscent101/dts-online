<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DocumentOfficeAssignment extends Model
{
    protected $fillable = [
        'document_id',
        'office',
        'status',
        'assigned_by',
        'movement_id',
    ];

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    public function assignedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }

    public function movement(): BelongsTo
    {
        return $this->belongsTo(DocumentMovement::class, 'movement_id');
    }
}
