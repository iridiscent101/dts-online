<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One "please sign at the kiosk" request for a Release: pending until the recipient signs on the kiosk laptop, the
 * clerk cancels it, or it expires. The signature is encrypted like the one stored on the release movement.
 */
class KioskSigningRequest extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_SIGNED = 'signed';

    public const STATUS_CANCELLED = 'cancelled';

    /** A pending request older than this shows as "expired" and can no longer be signed. */
    public const LIFETIME_MINUTES = 10;

    protected $fillable = [
        'document_id',
        'requested_by',
        'office',
        'status',
        'recipient_name',
        'recipient_signature',
        'signed_at',
        'expires_at',
    ];

    protected $hidden = [
        'recipient_signature',
    ];

    protected function casts(): array
    {
        return [
            'recipient_signature' => 'encrypted',
            'signed_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Document, $this>
     */
    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    /**
     * Requests the kiosk may still show: pending and not yet expired.
     *
     * @param  Builder<self>  $query
     */
    public function scopeWaiting(Builder $query): void
    {
        $query->where('status', self::STATUS_PENDING)->where('expires_at', '>', now());
    }

    public function isWaiting(): bool
    {
        return $this->status === self::STATUS_PENDING && $this->expires_at->isFuture();
    }

    /**
     * What the clerk's screen shows: pending, signed, cancelled, or expired (a pending request past its time).
     */
    public function displayStatus(): string
    {
        return $this->status === self::STATUS_PENDING && ! $this->expires_at->isFuture() ? 'expired' : $this->status;
    }
}
