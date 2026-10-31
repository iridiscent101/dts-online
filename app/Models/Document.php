<?php

namespace App\Models;

use App\Support\StatusLabel;
use Database\Factories\DocumentFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Orchid\Attachment\Attachable;
use Orchid\Filters\Filterable;
use Orchid\Screen\AsSource;
use Spatie\Activitylog\Models\Activity;

class Document extends Model
{
    /** @use HasFactory<DocumentFactory> */
    use AsSource, Attachable, Filterable, HasFactory, SoftDeletes;

    /** Remembered for the request: the panel checks the lock several times while rendering. */
    private ?bool $hasBeenProcessed = null;

    public const AUDIT_DISPLAY_LIMIT = 20;

    /** Shown for a paper submission's offices until the Records Unit confirms the paper copy. */
    public const ROUTE_WAITING_FOR_PAPER = 'Waiting for physical document/s';

    public const PRIORITIES = [
        'Urgent' => 3,
        'Standard' => 7,
        'Extended' => 20,
    ];

    public const DELIVERY_METHODS = ['Hand carry', 'Official channel'];

    public const AUDIT_DESCRIPTIONS = [
        'Document registered',
        'Document submitted',
        'Document details updated',
        'Document routes updated',
        'Document route corrected',
        // "Delete this route" (DocumentRoutingService::deletePendingRoute): the route itself is gone, this keeps the trace.
        'Document route deleted',
        // "Add office route" (DocumentRoutingService::addAdditionalRoute), with the reason it was added.
        'Document route added',
        'Attachments added',
        'Attachment replaced',
        'Attachment removed',
        // The client signed a released document on the Fast Track page (PublicReleaseSignatureController).
        'Release signed',
    ];

    public const STATUSES = [
        DocumentMovement::STATUS_AWAITING_PHYSICAL_DOCUMENT,
        DocumentMovement::STATUS_AWAITING_RECEIPT,
        DocumentMovement::STATUS_RECEIVED,
        DocumentMovement::STATUS_FORWARDED,
        DocumentMovement::STATUS_RETURNED,
        DocumentMovement::STATUS_RESUBMITTED,
        DocumentMovement::STATUS_REVIEWED,
        DocumentMovement::STATUS_APPROVED,
        DocumentMovement::STATUS_REJECTED,
        DocumentMovement::STATUS_ON_HOLD,
        DocumentMovement::STATUS_RELEASED,
        DocumentMovement::STATUS_COMPLETED,
        DocumentMovement::STATUS_ARCHIVED,
    ];

    public const TYPES = [
        'Memorandum',
        'Letter',
        'Report',
        'Request',
        'Endorsement',
        'Other',
    ];

    /**
     * @return list<string>
     */
    public static function typeOptions(): array
    {
        if (! Schema::hasTable('document_types')) {
            return self::TYPES;
        }

        $types = DocumentType::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->pluck('name')
            ->all();

        return $types === [] && ! DocumentType::query()->exists() ? self::TYPES : $types;
    }

    public static function newTrackingNumber(): string
    {
        do {
            $trackingNumber = 'SDONV';
            for ($index = 0; $index < 11; $index++) {
                $trackingNumber .= random_int(1, 9);
            }
        } while (self::withTrashed()->where('tracking_number', $trackingNumber)->exists());

        return $trackingNumber;
    }

    public const OFFICES = [
        'SDO-Schools Division Superintendent (SDS) Office',
        'SDO-Assistant Schools Division Superintendent (ASDS) Office',
        'SDO-Budget Unit',
        'SDO-Accounting Unit',
        'SDO-Legal Unit',
        'SDO-Information and Communications Technology (ICT) Unit',
        'SDO-Procurement Unit',
        'SDO-Administrative Section (AO V)',
        'SDO-Personnel Unit (HRMO)',
        'SDO-Property and Supply Unit',
        Office::RECORDS_UNIT_NAME,
        'SDO-Cash Unit',
        'SDO-School Governance and Operations Division (SGOD) Chief',
        'SDO-SGOD Personnel (SMME/SMAN/EFU/YFP/DRRM/HNU)',
        'SDO-EPS (School Sports/ Quality Management Office (QMO))',
        'SDO-Planning and Research (PAR) Unit',
        'SDO-Human Resource Development (HRD) Unit',
        'SDO-School Management Monitoring and Evaluation (SMME)',
        'SDO-Social Mobilization and Networking (SMAN)',
        'SDO-Education Facilities Unit (EFU)',
        'SDO-Youth Formation Program (YFP)',
        'SDO-Disaster Risk Reduction Management',
        'SDO-School Health Section (SHS)',
        'SDO-Curriculum Implementation Division (CID )Chief',
        'SDO-CID Personnel (EPS/PSDS)',
        'SDO-EPS (Araling Panlipunan)',
        'SDO-EPS (Kindergarten & SNED)',
        'SDO-EPS (Mathematics)',
        'SDO-EPS (Science)',
        'SDO-EPS (Edukasyon sa Pagpapakatao)',
        'SDO-EPS (English)',
        'SDO-EPS (MAPEH)',
        'SDO-EPS (Filipino)',
        'SDO-EPS (TLE)',
        'SDO-PSDS (Alfonso Castaneda District)',
        'SDO-PSDS (Ambaguio District)',
        'SDO-PSDS (Aritao 1 District)',
        'SDO-PSDS (Aritao II District)',
        'SDO-PSDS (Bagabag I District)',
        'SDO-PSDS (Bagabag II District)',
        'SDO-PSDS (Bambang I District)',
        'SDO-PSDS (Bambang II District)',
        'SDO-PSDS (Bayombong I District)',
        'SDO-PSDS (Bayombong II District)',
        'SDO-PSDS (Diadi District)',
        'SDO-PSDS (Dupax Del Norte I District)',
        'SDO-PSDS (Dupax Del Norte II District)',
        'SDO-PSDS (Dupax Del Sur District)',
        'SDO-PSDS (Eastern Kayapa District)',
        'SDO-PSDS (Western Kayapa District)',
        'SDO-PSDS (Kasibu East District)',
        'SDO-PSDS (Kasibu West District)',
        'SDO-PSDS (Quezon District)',
        'SDO-PSDS (Santa Fe District)',
        'SDO-PSDS (Solano I District)',
        'SDO-PSDS (Solano II District)',
        'SDO-PSDS (Villaverde District)',
        'SDO-School Sports/ Quality Management Office (QMO)',
        'Personal Delivery',
        'Others',
        'Records Unit',
        'Administrative Unit',
        'Curriculum Implementation Division',
    ];

    /**
     * @return list<string>
     */
    public static function officeOptions(): array
    {
        if (! Schema::hasTable('offices')) {
            return self::OFFICES;
        }

        $offices = Office::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->pluck('name')
            ->all();

        return $offices === [] && ! Office::query()->exists() ? self::OFFICES : $offices;
    }

    /**
     * @return list<string>
     */
    public static function originOptions(): array
    {
        $offices = self::officeOptions();

        if (! Schema::hasTable('schools')) {
            return $offices;
        }

        return array_values(array_unique([
            ...$offices,
            ...School::query()->orderBy('name')->distinct()->pluck('name')->all(),
        ]));
    }

    protected $fillable = [
        'tracking_number',
        'subject',
        'document_type',
        'external_reference',
        'description',
        'origin',
        'delivery_method',
        'sender',
        'client_email',
        'current_office',
        'status',
        'received_at',
        'priority',
        'remarks',
        'registered_by',
        'registered_office',
        'client_submission_key',
    ];

    protected $allowedSorts = [
        'tracking_number',
        'subject',
        'document_type',
        'sender',
        'current_office',
        'status',
        'received_at',
        'priority',
        'updated_at',
    ];

    /**
     * Documents in a registry tab (incoming, outgoing, archived). Given offices, only those offices' part counts;
     * without, any office's (the administrator view).
     *
     * @param  Collection<int, string>|null  $offices
     */
    public function scopeForSection(Builder $query, string $section, ?Collection $offices = null): Builder
    {
        if ($section === 'outgoing') {
            return $query->sentOnBy($offices)->whereNotIn('documents.status', [
                DocumentMovement::STATUS_COMPLETED,
                DocumentMovement::STATUS_RELEASED,
                DocumentMovement::STATUS_ARCHIVED,
            ])
                // A part the office sent back to Records that is already released is done, not outgoing (Archived).
                ->when($offices !== null, fn (Builder $query): Builder => $query->whereNot(fn (Builder $done): Builder => $done->partReleasedFor($offices)));
        }

        $statuses = match ($section) {
            // "Forwarded" is the status of the office a document was forwarded to, so it is incoming there.
            'incoming' => [DocumentMovement::STATUS_AWAITING_PHYSICAL_DOCUMENT, DocumentMovement::STATUS_AWAITING_RECEIPT, DocumentMovement::STATUS_FORWARDED],
            'archived' => [
                DocumentMovement::STATUS_ARCHIVED,
                DocumentMovement::STATUS_COMPLETED,
                DocumentMovement::STATUS_RELEASED,
            ],
            default => null,
        };

        if ($statuses === null) {
            return $query;
        }

        if ($offices !== null) {
            return $query->where(function (Builder $query) use ($offices, $section, $statuses): void {
                $query->whereHas('officeAssignments', fn (Builder $assignments): Builder => $assignments
                    ->whereIn('office', $offices)
                    ->whereIn('status', $statuses));

                if ($section === 'archived') {
                    $query->orWhere(fn (Builder $sent): Builder => $sent
                        ->whereIn('documents.status', $statuses)
                        ->sentOnBy($offices))
                        // Bundles: the office's part came back to Records and was released while others still work.
                        ->orWhere(fn (Builder $done): Builder => $done->partReleasedFor($offices));
                }
            });
        }

        return $query->where(function (Builder $query) use ($statuses): void {
            $query->whereHas('officeAssignments', fn (Builder $assignments): Builder => $assignments->whereIn('status', $statuses))
                ->orWhere(function (Builder $legacy) use ($statuses): void {
                    $legacy->doesntHave('officeAssignments')->whereIn('status', $statuses);
                });
        });
    }

    /**
     * Bundles: the office sent its part back to the Records Unit and Records has released (or completed) it, so that
     * office's work is done even while other offices still work on the rest of the document.
     *
     * @param  Collection<int, string>  $offices
     */
    public function scopePartReleasedFor(Builder $query, Collection $offices): Builder
    {
        return $query
            ->whereHas('movements', fn (Builder $movements): Builder => $movements
                ->whereIn('action', [DocumentMovement::ACTION_FORWARD, DocumentMovement::ACTION_FORWARDED])
                ->whereIn('from_office', $offices)
                ->whereIn('to_office', Office::RECORDS_UNIT_NAMES))
            ->whereHas('officeAssignments', fn (Builder $assignments): Builder => $assignments
                ->whereIn('office', Office::RECORDS_UNIT_NAMES)
                ->whereIn('status', [DocumentMovement::STATUS_RELEASED, DocumentMovement::STATUS_COMPLETED]));
    }

    /**
     * Documents an office sent on to another office (forwarded, returned, resubmitted, or sent after registration).
     * With no offices given, documents any office has sent on.
     *
     * @param  Collection<int, string>|null  $offices
     */
    public function scopeSentOnBy(Builder $query, ?Collection $offices = null): Builder
    {
        return $query->whereHas('movements', fn (Builder $movements): Builder => $movements
            ->whereIn('action', DocumentMovement::OUTGOING_ACTIONS)
            ->whereNotNull('from_office')
            ->when($offices !== null, fn (Builder $movements): Builder => $movements->whereIn('from_office', $offices)));
    }

    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if ($user->isAdministrator() || $user->hasAccess('platform.documents.view-all')) {
            return $query;
        }

        $offices = $user->assignedOfficeNames();

        if ($offices->isEmpty()) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where(function (Builder $query) use ($offices): void {
            $query->where(fn (Builder $legacy): Builder => $legacy
                ->whereIn('current_office', $offices)
                ->doesntHave('officeAssignments'))
                ->orWhereHas('officeAssignments', fn (Builder $assignments): Builder => $assignments->whereIn('office', $offices))
                ->orWhereIn('registered_office', $offices)
                // An office keeps read access to what it sent on, so its Outgoing tab can list it.
                ->orWhere(fn (Builder $sent): Builder => $sent->sentOnBy($offices));

            if ($offices->contains(Office::RECORDS_UNIT_NAME)) {
                $query->orWhereHas('movements', fn (Builder $movements): Builder => $movements
                    ->where('action', DocumentMovement::ACTION_RECEIVED)
                    ->where('from_status', DocumentMovement::STATUS_AWAITING_PHYSICAL_DOCUMENT)
                    ->where('to_office', Office::RECORDS_UNIT_NAME));
            }
        });
    }

    /**
     * @param  bool  $includeSentOn  false leaves out the read access an office keeps to documents it sent on
     */
    public function isVisibleTo(User $user, bool $includeSentOn = true): bool
    {
        if ($user->hasAccess('platform.documents.view-all') || $this->isAssignedToUserOffice($user)) {
            return true;
        }

        $offices = $user->assignedOfficeNames();
        if ($includeSentOn && $offices->isNotEmpty() && $this->movements()
            ->whereIn('action', DocumentMovement::OUTGOING_ACTIONS)
            ->whereIn('from_office', $offices)
            ->exists()) {
            return true;
        }

        if ($offices->contains($this->registered_office)) {
            return true;
        }

        return $user->isAssignedToOffice(Office::RECORDS_UNIT_NAME)
            && $this->movements()
                ->where('action', DocumentMovement::ACTION_RECEIVED)
                ->where('from_status', DocumentMovement::STATUS_AWAITING_PHYSICAL_DOCUMENT)
                ->where('to_office', Office::RECORDS_UNIT_NAME)
                ->exists();
    }

    private function isAssignedToUserOffice(User $user): bool
    {
        if ($user->isAdministrator()) {
            return true;
        }

        return $this->assignedOfficeNames()->intersect($user->assignedOfficeNames())->isNotEmpty();
    }

    public function canBeEditedBy(User $user): bool
    {
        return ! $user->isReadOnlySupervisor()
            && $user->hasAccess('platform.documents.edit')
            && ($this->isAssignedToUserOffice($user)
                || $user->isAssignedToOffice($this->registered_office))
            && ($user->isAdministrator() || $user->assignedOfficeNames()->intersect(Office::RECORDS_UNIT_NAMES)->isNotEmpty())
            && (! $this->isClosedRecord() || $user->isAdministrator());
    }

    /**
     * Once an office has received or acted on a document, its details (title, type, origin, sender, dates, priority,
     * description) are locked. Changing them means unlocking with a reason, which is kept in the history.
     * Assigned offices are not part of the details and stay editable while the route is open.
     */
    public function detailsAreLocked(): bool
    {
        return $this->isClosedRecord() || $this->hasBeenProcessed();
    }

    public function detailsRequireUnlockFor(User $user): bool
    {
        return $this->detailsAreLocked() || ! $this->isAssignedToUserOffice($user);
    }

    /**
     * A completed, released or archived document is a closed record: only an administrator can unlock it.
     */
    public function isClosedRecord(): bool
    {
        return in_array($this->status, [DocumentMovement::STATUS_COMPLETED, DocumentMovement::STATUS_RELEASED, DocumentMovement::STATUS_ARCHIVED], true);
    }

    /**
     * Registration and adding offices only record "sent" movements; any other movement means an office has
     * received or acted on the document.
     */
    public function hasBeenProcessed(): bool
    {
        return $this->hasBeenProcessed ??= $this->movements()
            ->where('action', '!=', DocumentMovement::ACTION_SENT)
            ->exists();
    }

    public function canManageRoutesBy(User $user): bool
    {
        return ! $user->isReadOnlySupervisor()
            && $user->hasAccess('platform.documents.routes.manage')
            && $this->isAssignedToUserOffice($user);
    }

    public function canEditOfficeAssignmentsBy(User $user): bool
    {
        return $this->canBeEditedBy($user)
            && $this->canManageRoutesBy($user)
            && $user->isAdministrator();
    }

    /**
     * Destinations the user's offices sent this document to that have not received it yet, so they can still be
     * corrected (DocumentRoutingService::correctPendingRoute checks the same again when saving).
     *
     * @return Collection<int, array{source: string, destination: string}>
     */
    public function correctableRoutesFor(User $user): Collection
    {
        if ($user->isReadOnlySupervisor() || ! $user->hasAccess('platform.index') || $this->isClosedRecord()) {
            return collect();
        }

        $offices = $user->assignedOfficeNames();
        if ($offices->isEmpty() && ! $user->isAdministrator()) {
            return collect();
        }

        return $this->officeAssignments
            ->whereIn('status', [DocumentMovement::STATUS_FORWARDED, DocumentMovement::STATUS_AWAITING_RECEIPT])
            ->map(function (DocumentOfficeAssignment $assignment) use ($offices, $user): ?array {
                $movement = $assignment->movement;
                // Forwarded by one of the user's offices.
                if ($movement !== null && $assignment->status === DocumentMovement::STATUS_FORWARDED
                    && ($user->isAdministrator() || $offices->contains($movement->from_office))
                    && in_array($movement->action, [DocumentMovement::ACTION_FORWARD, DocumentMovement::ACTION_FORWARDED], true)) {
                    return ['source' => $movement->from_office, 'destination' => $assignment->office];
                }
                // The first send after registration, by someone in one of the user's offices.
                if ($movement !== null && $assignment->status === DocumentMovement::STATUS_AWAITING_RECEIPT
                    && $movement->action === DocumentMovement::ACTION_SENT && $movement->from_office === null) {
                    $source = $user->isAdministrator() || $offices->contains($this->registered_office) ? $this->registered_office : null;

                    return $source === null ? null : ['source' => $source, 'destination' => $assignment->office];
                }

                return null;
            })
            ->filter()
            ->values();
    }

    /**
     * The step a client's online submission creates (sent to Records, from no office). Its remarks are the client's
     * "Instructions for Records", which the timelines show on the "Document submitted" step instead.
     */
    public function isClientSubmissionStep(DocumentMovement $movement): bool
    {
        return $this->client_submission_key !== null
            && $movement->action === DocumentMovement::ACTION_SENT
            && $movement->from_office === null;
    }

    /**
     * What the client typed under "Instructions for Records" when submitting, or null.
     */
    public function clientInstructions(): ?string
    {
        if ($this->client_submission_key === null) {
            return null;
        }

        $movements = $this->relationLoaded('movements')
            ? $this->movements->sortBy('id')
            : $this->movements()->reorder()->orderBy('id')->get();
        $remarks = $movements->first(fn (DocumentMovement $movement): bool => $this->isClientSubmissionStep($movement))?->remarks;

        return filled($remarks) ? $remarks : null;
    }

    /**
     * The editor's assigned-office list can change only before any office processes or receives the document, and only
     * for documents with a registering office (DocumentEditScreen::updateRoutes refuses the rest). After that, new
     * offices go through Forward and a wrong pending office through Correct route.
     */
    public function routeListIsLocked(): bool
    {
        return $this->registered_office === null || $this->hasBeenProcessed();
    }

    public function routesAreEditable(): bool
    {
        return ! in_array($this->status, [
            DocumentMovement::STATUS_ARCHIVED,
            DocumentMovement::STATUS_COMPLETED,
            DocumentMovement::STATUS_RELEASED,
            DocumentMovement::STATUS_AWAITING_PHYSICAL_DOCUMENT,
        ], true);
    }

    /**
     * Files can only change while the user's office is still working on the document. A closed record's files are
     * locked like its details: only an administrator may change them.
     */
    public function canHaveAttachmentsManagedBy(User $user): bool
    {
        if ($user->isReadOnlySupervisor() || ! $user->hasAccess('platform.documents.attachments.manage')) {
            return false;
        }

        if ($user->isAdministrator()) {
            return true;
        }

        if ($this->isClosedRecord()) {
            return false;
        }

        $offices = $user->assignedOfficeNames();
        $assignments = $this->relationLoaded('officeAssignments')
            ? $this->officeAssignments
            : $this->officeAssignments()->get(['office', 'status']);

        // Legacy documents without route rows fall back to the current office.
        if ($assignments->isEmpty()) {
            return $offices->contains($this->current_office);
        }

        return $assignments->contains(fn (DocumentOfficeAssignment $assignment): bool => $offices->contains($assignment->office)
            && ! in_array($assignment->status, DocumentMovement::FINISHED_STATUSES, true));
    }

    /**
     * Adding files is a little wider than changing them: the office that released or completed the document may still
     * add files for a short time afterwards (config dts.attachment_grace_hours), such as the scanned signed receipt.
     * Replacing or deleting files stays with canHaveAttachmentsManagedBy.
     */
    public function canHaveAttachmentsAddedBy(User $user): bool
    {
        if ($this->canHaveAttachmentsManagedBy($user)) {
            return true;
        }

        if ($user->isReadOnlySupervisor() || ! $user->hasAccess('platform.documents.attachments.manage')) {
            return false;
        }

        $offices = $user->assignedOfficeNames();
        $since = now()->subHours((int) config('dts.attachment_grace_hours'));

        return $this->officeAssignments()
            ->with('movement')
            ->whereIn('office', $offices)
            ->whereIn('status', [DocumentMovement::STATUS_RELEASED, DocumentMovement::STATUS_COMPLETED])
            ->get()
            ->contains(fn (DocumentOfficeAssignment $assignment): bool => $assignment->movement !== null
                && $assignment->movement->to_status === $assignment->status
                && $assignment->movement->created_at->greaterThanOrEqualTo($since));
    }

    public function registeredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'registered_by')->withTrashed();
    }

    public function movements(): HasMany
    {
        return $this->hasMany(DocumentMovement::class)->latest();
    }

    public function forwardMovements(): HasMany
    {
        return $this->hasMany(DocumentMovement::class)
            ->whereIn('action', [DocumentMovement::ACTION_FORWARD, DocumentMovement::ACTION_FORWARDED])
            ->whereNotNull('from_office');
    }

    public function latestMovement(): HasOne
    {
        return $this->hasOne(DocumentMovement::class)->latestOfMany();
    }

    public function recordsReceipt(): HasOne
    {
        // The conditions go inside ofMany: as outer wheres they only filtered the document's oldest movement (always the
        // client's "sent" step), so a received document still showed "Not received yet".
        return $this->hasOne(DocumentMovement::class)->ofMany(['id' => 'min'], fn ($query) => $query
            ->where('action', DocumentMovement::ACTION_RECEIVED)
            ->where('from_status', DocumentMovement::STATUS_AWAITING_PHYSICAL_DOCUMENT)
            ->where('to_office', Office::RECORDS_UNIT_NAME));
    }

    /** Client phones and browsers that asked for push updates on this document (tracking or receipt page). */
    public function pushSubscriptions(): HasMany
    {
        return $this->hasMany(DocumentPushSubscription::class);
    }

    public function officeAssignments(): HasMany
    {
        return $this->hasMany(DocumentOfficeAssignment::class);
    }

    public function routeFingerprint(): string
    {
        return hash('sha256', $this->officeAssignments()
            ->orderBy('id')
            ->get(['id', 'office', 'status', 'movement_id'])
            ->toJson());
    }

    public function detailsFingerprint(): string
    {
        return hash('sha256', json_encode($this->only([
            'subject',
            'document_type',
            'external_reference',
            'description',
            'origin',
            'sender',
            'client_email',
            'received_at',
            'priority',
        ]), JSON_THROW_ON_ERROR));
    }

    /**
     * @return Collection<int, string>
     */
    public function assignedOfficeNames(): Collection
    {
        $offices = $this->relationLoaded('officeAssignments')
            ? $this->officeAssignments->pluck('office')
            : $this->officeAssignments()->pluck('office');

        return $offices
            ->when($offices->isEmpty() && filled($this->current_office), fn (Collection $officeNames): Collection => $officeNames->push($this->current_office))
            ->filter()
            ->unique()
            ->values();
    }

    /**
     * Offices a client chose for a paper submission. They are held back (the document sits with the Records Unit)
     * until Records confirms the paper copy, then all receive it at once. Records itself is left out: it already has it.
     *
     * @return Collection<int, string>
     */
    public function pendingRouteOffices(): Collection
    {
        if ($this->status !== DocumentMovement::STATUS_AWAITING_PHYSICAL_DOCUMENT) {
            return collect();
        }

        $query = DB::table('document_route_steps')
            ->where('office', '!=', Office::RECORDS_UNIT_NAME)
            ->orderBy('sequence');

        // Lists show this for every row, so a page view loads the steps of all documents awaiting paper in one query.
        // Writes, console commands and tests query per document so they never see steps cached before a change.
        $request = request();
        if (app()->runningInConsole() || ! $request->isMethodSafe()) {
            return $query->where('document_id', $this->getKey())->pluck('office');
        }

        $pending = $request->attributes->get('dts.pending-route-offices');
        if ($pending === null) {
            $pending = $query
                ->whereIn('document_id', DB::table('documents')->select('id')->where('status', DocumentMovement::STATUS_AWAITING_PHYSICAL_DOCUMENT))
                ->get(['document_id', 'office'])
                ->groupBy('document_id')
                ->map(fn (Collection $steps) => $steps->pluck('office'));
            $request->attributes->set('dts.pending-route-offices', $pending);
        }

        return $pending->get($this->getKey(), collect());
    }

    /**
     * For display only: the offices working on the document, followed by the offices still waiting for its paper
     * copy (status "Waiting for paper"); the Records Unit's holding row is left out while they wait.
     * Workflow logic must keep using assignedOfficeStatuses().
     *
     * @return Collection<int, array{office: string, status: string, pending: bool}>
     */
    public function officeRouteStatuses(): Collection
    {
        $pendingOffices = $this->pendingRouteOffices();

        return $this->assignedOfficeStatuses()
            // Until the paper arrives the Records Unit only holds the document; Paper received completes its part.
            ->reject(fn (array $assignment): bool => $pendingOffices->isNotEmpty()
                && $assignment['status'] === DocumentMovement::STATUS_AWAITING_PHYSICAL_DOCUMENT)
            ->map(fn (array $assignment): array => [...$assignment, 'pending' => false])
            ->concat($pendingOffices->map(fn (string $office): array => [
                'office' => $office,
                'status' => self::ROUTE_WAITING_FOR_PAPER,
                'pending' => true,
            ]))
            ->unique('office')
            ->values();
    }

    /**
     * @return Collection<int, array{office: string, status: string}>
     */
    public function assignedOfficeStatuses(): Collection
    {
        $assignments = $this->relationLoaded('officeAssignments')
            ? $this->officeAssignments
            : $this->officeAssignments()->get();

        if ($assignments->isEmpty()) {
            return collect([[
                'office' => $this->current_office,
                'status' => $this->status,
            ]]);
        }

        return $assignments
            ->map(fn (DocumentOfficeAssignment $assignment): array => [
                'office' => $assignment->office,
                'status' => $assignment->status,
            ])
            ->unique('office')
            ->values();
    }

    /**
     * @return array{office: string, status: string}|null
     */
    public function workflowStateFor(User $user): ?array
    {
        if ($user->isAdministrator() || $user->isAssignedToOffice($this->current_office)) {
            return ['office' => $this->current_office, 'status' => $this->status];
        }

        $offices = $user->assignedOfficeNames();

        if ($offices->isEmpty()) {
            return null;
        }

        $assignment = $this->relationLoaded('officeAssignments')
            ? $this->officeAssignments->first(fn (DocumentOfficeAssignment $assignment): bool => $offices->contains($assignment->office))
            : $this->officeAssignments()->whereIn('office', $offices)->oldest('id')->first();

        if ($assignment === null) {
            return null;
        }

        return ['office' => $assignment->office, 'status' => $assignment->status];
    }

    public function workflowOfficeFor(User $user): string
    {
        return $this->workflowStateFor($user)['office'] ?? $this->current_office;
    }

    public function workflowStatusFor(User $user): string
    {
        return $this->workflowStateFor($user)['status'] ?? $this->status;
    }

    public function nextActionLabelFor(User $user): string
    {
        return match ($this->workflowStatusFor($user)) {
            DocumentMovement::STATUS_AWAITING_PHYSICAL_DOCUMENT => 'Confirm document received',
            DocumentMovement::STATUS_AWAITING_RECEIPT,
            DocumentMovement::STATUS_FORWARDED,
            DocumentMovement::STATUS_RETURNED,
            DocumentMovement::STATUS_RESUBMITTED => 'Process',
            DocumentMovement::STATUS_RECEIVED => 'Process',
            default => 'View',
        };
    }

    public function activities(): MorphMany
    {
        return $this->morphMany(Activity::class, 'subject')->latest();
    }

    /**
     * @param  array<string, mixed>  $properties
     */
    public function recordActivity(string $description, User $actor, array $properties = []): void
    {
        activity('documents')
            ->causedBy($actor)
            ->performedOn($this)
            ->withProperties($properties)
            ->log($description);
    }

    public function recordDownload(User $actor, int $attachmentId, string $fileName): void
    {
        $this->recordActivity('Attachment downloaded', $actor, [
            'action' => 'download',
            'attachment_id' => $attachmentId,
            'file_name' => $fileName,
            'offices' => $actor->assignedOfficeNames()->all(),
        ]);
    }

    /**
     * @param  array{search: string, office: string, type: string, status: string, from: string, to: string}  $filters
     */
    public function scopeRegistryFilters(Builder $query, array $filters): Builder
    {
        $matchingStatuses = $filters['status'] === StatusLabel::PENDING
            ? [DocumentMovement::STATUS_AWAITING_RECEIPT, DocumentMovement::STATUS_AWAITING_PHYSICAL_DOCUMENT]
            : [$filters['status']];

        return $query
            ->when($filters['search'], function (Builder $query, string $search): void {
                // Staff often know only who sent it ("from Bayombong Central School"), not the tracking number.
                $query->where(function (Builder $query) use ($search): void {
                    $query->where('tracking_number', 'like', "%{$search}%")
                        ->orWhere('subject', 'like', "%{$search}%")
                        ->orWhere('sender', 'like', "%{$search}%")
                        ->orWhere('origin', 'like', "%{$search}%")
                        ->orWhere('external_reference', 'like', "%{$search}%")
                        ->orWhere('client_email', 'like', "%{$search}%");
                });
            })
            ->when($filters['type'], fn (Builder $query, string $type): Builder => $query->where('document_type', $type))
            ->when($filters['priority'], fn (Builder $query, string $priority): Builder => $query->where('priority', $priority))
            ->when($filters['office'] !== '' || $filters['status'] !== '', function (Builder $query) use ($filters, $matchingStatuses): void {
                $query->where(function (Builder $query) use ($filters, $matchingStatuses): void {
                    $query->whereHas('officeAssignments', function (Builder $assignments) use ($filters, $matchingStatuses): void {
                        $assignments
                            ->when($filters['office'], fn (Builder $assignments, string $office): Builder => $assignments->where('office', $office))
                            ->when($filters['status'], fn (Builder $assignments): Builder => $assignments->whereIn('status', $matchingStatuses));
                    })->orWhere(function (Builder $legacy) use ($filters, $matchingStatuses): void {
                        $legacy->doesntHave('officeAssignments')
                            ->when($filters['office'], fn (Builder $legacy, string $office): Builder => $legacy->where('current_office', $office))
                            ->when($filters['status'], fn (Builder $legacy): Builder => $legacy->whereIn('status', $matchingStatuses));
                    });
                });
            })
            ->when($filters['from'], fn (Builder $query, string $from): Builder => $query->whereDate('received_at', '>=', $from))
            ->when($filters['to'], fn (Builder $query, string $to): Builder => $query->whereDate('received_at', '<=', $to));
    }

    protected function casts(): array
    {
        return [
            'received_at' => 'date',
        ];
    }
}
