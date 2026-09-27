<?php

namespace App\Models;

use Database\Factories\DocumentMovementFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DocumentMovement extends Model
{
    /** @use HasFactory<DocumentMovementFactory> */
    use HasFactory;

    protected $fillable = [
        'action',
        'from_office',
        'to_office',
        'from_status',
        'to_status',
        'remarks',
        'acted_by',
    ];

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'acted_by');
    }

    public function title(): string
    {
        $actionTitle = match ($this->action) {
            'acknowledge' => 'Receipt acknowledged',
            'forward' => "Forwarded to {$this->to_office}",
            'archive' => 'Document archived',
            'restore' => "Restored to {$this->to_office}",
            default => null,
        };

        if ($actionTitle !== null) {
            return $actionTitle;
        }

        if ($this->from_office !== $this->to_office) {
            return "Forwarded to {$this->to_office}";
        }

        if ($this->from_status !== $this->to_status) {
            return "Status changed to {$this->to_status}";
        }

        return 'Routing note added';
    }
}
