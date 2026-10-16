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

    public const ACTION_ACKNOWLEDGE = 'acknowledge';

    public const ACTION_ARCHIVE = 'archive';

    public const ACTION_CANCELLED = 'cancelled';

    public const ACTION_APPROVED = 'approved';

    public const ACTION_COMPLETED = 'completed';

    public const ACTION_FORWARD = 'forward';

    public const ACTION_FORWARDED = 'forwarded';

    public const ACTION_ON_HOLD = 'on_hold';

    public const ACTION_RECEIVED = 'received';

    public const ACTION_REJECTED = 'rejected';

    public const ACTION_RELEASED = 'released';

    public const ACTION_RESTORED = 'restore';

    public const ACTION_RETURNED = 'returned';

    public const ACTION_RESUBMITTED = 'resubmitted';

    public const ACTION_REVIEWED = 'reviewed';

    public const ACTION_SENT = 'sent';

    public const STATUS_APPROVED = 'Approved';

    public const STATUS_ARCHIVED = 'Archived';

    public const STATUS_CANCELLED = 'Cancelled';

    public const STATUS_AWAITING_RECEIPT = 'Awaiting receipt';

    public const STATUS_AWAITING_PHYSICAL_DOCUMENT = 'Awaiting physical document';

    public const STATUS_COMPLETED = 'Completed';

    public const STATUS_FORWARDED = 'Forwarded';

    public const STATUS_ON_HOLD = 'On Hold';

    public const STATUS_RECEIVED = 'Received';

    public const STATUS_REJECTED = 'Rejected';

    public const STATUS_RELEASED = 'Released';

    public const STATUS_RESUBMITTED = 'Resubmitted';

    public const STATUS_RETURNED = 'Returned';

    public const STATUS_REVIEWED = 'Reviewed';

    /**
     * Office route statuses that mean the office has finished with the document.
     */
    public const FINISHED_STATUSES = [
        self::STATUS_COMPLETED,
        self::STATUS_ARCHIVED,
        self::STATUS_REJECTED,
        self::STATUS_RELEASED,
    ];

    /**
     * Plain-language explanation of each status for clients on the public tracking page.
     */
    public const STATUS_MEANINGS = [
        self::STATUS_AWAITING_PHYSICAL_DOCUMENT => 'The Records Unit must receive the document before processing can start.',
        self::STATUS_AWAITING_RECEIPT => 'The document was sent to the office, but the office has not confirmed receiving it yet.',
        self::STATUS_RECEIVED => 'The office has the document and is working on it.',
        self::STATUS_FORWARDED => 'The document was sent on to another office.',
        self::STATUS_REVIEWED => 'The office has reviewed the document.',
        self::STATUS_APPROVED => 'The document was approved.',
        self::STATUS_REJECTED => 'The document was not approved. Contact the office for details.',
        self::STATUS_RETURNED => 'The document was sent back for corrections or more information.',
        self::STATUS_RESUBMITTED => 'The document was sent again after corrections.',
        self::STATUS_ON_HOLD => 'Processing is paused for now.',
        self::STATUS_RELEASED => 'The office has released the document.',
        self::STATUS_COMPLETED => 'The office has finished its part.',
        self::STATUS_ARCHIVED => 'Processing is finished and the document is filed.',
    ];

    public const IN_PROGRESS_STATUSES = [
        self::STATUS_AWAITING_RECEIPT,
        self::STATUS_FORWARDED,
        self::STATUS_RECEIVED,
        self::STATUS_RETURNED,
        self::STATUS_RESUBMITTED,
        self::STATUS_REVIEWED,
        self::STATUS_APPROVED,
        self::STATUS_ON_HOLD,
    ];

    public const ROUTING_ACTIONS = [
        self::ACTION_SENT,
        self::ACTION_RECEIVED,
        self::ACTION_FORWARD,
        self::ACTION_FORWARDED,
        self::ACTION_RETURNED,
        self::ACTION_RESUBMITTED,
        self::ACTION_REVIEWED,
        self::ACTION_APPROVED,
        self::ACTION_REJECTED,
        self::ACTION_ON_HOLD,
        self::ACTION_RELEASED,
        self::ACTION_COMPLETED,
        self::ACTION_ACKNOWLEDGE,
        self::ACTION_ARCHIVE,
        self::ACTION_RESTORED,
    ];

    /** Actions by which an office sends a document on; they put it in that office's Outgoing tab. */
    public const OUTGOING_ACTIONS = [
        self::ACTION_SENT,
        self::ACTION_FORWARD,
        self::ACTION_FORWARDED,
        self::ACTION_RETURNED,
        self::ACTION_RESUBMITTED,
    ];

    public const DESTINATION_ACTIONS = [
        self::ACTION_SENT,
        self::ACTION_FORWARD,
        self::ACTION_FORWARDED,
        self::ACTION_RETURNED,
        self::ACTION_RESUBMITTED,
        self::ACTION_RESTORED,
    ];

    protected $fillable = [
        'action',
        'from_office',
        'to_office',
        'from_status',
        'to_status',
        'remarks',
        'released_to',
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
            self::ACTION_ACKNOWLEDGE, self::ACTION_RECEIVED => $this->from_status === self::STATUS_AWAITING_PHYSICAL_DOCUMENT ? 'Received by Records Unit' : 'Document received',
            self::ACTION_SENT => "Sent to {$this->to_office}",
            self::ACTION_FORWARD, self::ACTION_FORWARDED => "Forwarded to {$this->to_office}",
            self::ACTION_RETURNED => "Returned to {$this->to_office}",
            self::ACTION_RESUBMITTED => "Resubmitted to {$this->to_office}",
            self::ACTION_REVIEWED => 'Document reviewed',
            self::ACTION_APPROVED => 'Document approved',
            self::ACTION_REJECTED => 'Document rejected',
            self::ACTION_ON_HOLD => 'Placed on hold',
            self::ACTION_RELEASED => 'Document released',
            self::ACTION_COMPLETED => 'Document completed',
            self::ACTION_ARCHIVE => 'Document archived',
            self::ACTION_RESTORED => "Restored to {$this->to_office}",
            self::ACTION_CANCELLED => "Route to {$this->to_office} cancelled",
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

    /**
     * A plain sentence naming the office that acted, for the public tracking page.
     */
    public function publicSummary(): string
    {
        $actingOffice = $this->from_office ?: $this->to_office ?: 'The office';

        return match ($this->action) {
            self::ACTION_ACKNOWLEDGE, self::ACTION_RECEIVED => "{$this->to_office} received the document",
            self::ACTION_SENT => $this->from_office ? "{$this->from_office} sent it to {$this->to_office}" : "Sent to {$this->to_office}",
            self::ACTION_FORWARD, self::ACTION_FORWARDED => "Document/s forwarded to {$this->to_office}",
            self::ACTION_RETURNED => "{$actingOffice} returned it to {$this->to_office}",
            self::ACTION_RESUBMITTED => "{$actingOffice} resubmitted it to {$this->to_office}",
            self::ACTION_REVIEWED => "{$actingOffice} reviewed the document",
            self::ACTION_APPROVED => "{$actingOffice} approved the document",
            self::ACTION_REJECTED => "{$actingOffice} rejected the document",
            self::ACTION_ON_HOLD => "{$actingOffice} placed it on hold",
            self::ACTION_RELEASED => "{$actingOffice} released the document",
            self::ACTION_COMPLETED => "{$actingOffice} marked it completed",
            self::ACTION_ARCHIVE => "{$actingOffice} archived the document",
            self::ACTION_RESTORED => "Restored to {$this->to_office}",
            self::ACTION_CANCELLED => "Route to {$this->to_office} cancelled",
            default => match (true) {
                $this->from_office !== $this->to_office => "Document/s forwarded to {$this->to_office}",
                $this->from_status !== $this->to_status => "{$actingOffice} changed the status to {$this->to_status}",
                default => "{$actingOffice} added a routing note",
            },
        };
    }

    /**
     * The kind of step, used to color the public timeline: moved, received, done, hold, returned or note.
     */
    public function publicKind(): string
    {
        return match ($this->action) {
            self::ACTION_SENT, self::ACTION_FORWARD, self::ACTION_FORWARDED, self::ACTION_RESUBMITTED, self::ACTION_RESTORED => 'moved',
            self::ACTION_ACKNOWLEDGE, self::ACTION_RECEIVED => 'received',
            self::ACTION_APPROVED, self::ACTION_COMPLETED, self::ACTION_ARCHIVE => 'done',
            self::ACTION_ON_HOLD => 'hold',
            self::ACTION_RETURNED, self::ACTION_REJECTED => 'returned',
            self::ACTION_CANCELLED => 'cancelled',
            default => $this->from_office !== $this->to_office ? 'moved' : 'note',
        };
    }

    /**
     * @return array<string, string>
     */
    public static function actionOptions(): array
    {
        return [
            self::ACTION_RECEIVED => 'Receive',
            self::ACTION_FORWARDED => 'Forward',
            self::ACTION_COMPLETED => 'Complete',
            self::ACTION_RELEASED => 'Release',
            self::ACTION_RESTORED => 'Restore',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function availableActionOptions(Document $document): array
    {
        return self::availableActionOptionsForStatus($document->status);
    }

    /**
     * @return array<string, string>
     */
    public static function availableActionOptionsForStatus(string $status): array
    {
        return collect(self::actionOptions())->only(self::availableActionsForStatus($status))->all();
    }

    /**
     * @return list<string>
     */
    public static function availableActions(Document $document): array
    {
        return self::availableActionsForStatus($document->status);
    }

    /**
     * @return list<string>
     */
    public static function availableActionsForStatus(string $status): array
    {
        return match ($status) {
            self::STATUS_AWAITING_PHYSICAL_DOCUMENT => [
                self::ACTION_RECEIVED,
            ],
            self::STATUS_AWAITING_RECEIPT, self::STATUS_FORWARDED, self::STATUS_RETURNED, self::STATUS_RESUBMITTED => [
                self::ACTION_RECEIVED,
            ],
            self::STATUS_RECEIVED, self::STATUS_REVIEWED, self::STATUS_APPROVED, self::STATUS_ON_HOLD => [
                self::ACTION_FORWARDED,
                self::ACTION_COMPLETED,
                self::ACTION_RELEASED,
            ],
            self::STATUS_ARCHIVED, self::STATUS_COMPLETED, self::STATUS_REJECTED, self::STATUS_RELEASED => [
                self::ACTION_RESTORED,
            ],
            default => [],
        };
    }

    public static function requiresDestination(string $action): bool
    {
        return in_array($action, self::DESTINATION_ACTIONS, true);
    }

    /**
     * Whether an office in this status is still waiting to acknowledge receipt.
     */
    public static function requiresAcknowledgement(string $status): bool
    {
        return in_array($status, [
            self::STATUS_AWAITING_PHYSICAL_DOCUMENT,
            self::STATUS_AWAITING_RECEIPT,
            self::STATUS_FORWARDED,
            self::STATUS_RETURNED,
            self::STATUS_RESUBMITTED,
        ], true);
    }
}
