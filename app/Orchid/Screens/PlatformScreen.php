<?php

declare(strict_types=1);

namespace App\Orchid\Screens;

use App\Models\Document;
use App\Models\DocumentMovement;
use App\Models\DocumentOfficeAssignment;
use App\Models\User;
use App\Orchid\Layouts\Document\DocumentListLayout;
use App\Support\StatusLabel;
use DateTimeImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Orchid\Screen\Action;
use Orchid\Screen\Screen;
use Orchid\Support\Facades\Layout;

class PlatformScreen extends Screen
{
    /** Documents per dashboard queue page; five show at first, the rest behind "Show more". */
    private const OFFICE_QUEUE_LIMIT = 25;

    // "Rows per page" choices for the dashboard queue, as in the document registry.
    private const OFFICE_QUEUE_PAGE_SIZES = [10, 25, 50, 100];

    // Sent to an office that has not received it yet ("Pending · Awaiting acknowledgment of receipt").
    private const NOT_RECEIVED_STATUSES = [
        DocumentMovement::STATUS_AWAITING_RECEIPT,
        DocumentMovement::STATUS_FORWARDED,
        DocumentMovement::STATUS_RETURNED,
        DocumentMovement::STATUS_RESUBMITTED,
    ];

    private const OFFICE_ACTION_STATUSES = [
        DocumentMovement::STATUS_AWAITING_PHYSICAL_DOCUMENT,
        ...DocumentMovement::IN_PROGRESS_STATUSES,
    ];

    /**
     * Fetch data to be displayed on the screen.
     *
     * @return array
     */
    public function query(): iterable
    {
        $section = $this->section();
        $user = request()->user();
        abort_unless($user instanceof User, 403);

        if (! $user->isAdministrator()) {
            $user->loadMissing('offices');
        }

        if ($section === 'dashboard') {
            $offices = $user->assignedOfficeNames();
            $isAdministrator = $user->isAdministrator();
            $canViewAllDocuments = $isAdministrator || $user->hasAccess('platform.documents.view-all');
            $visibleDocuments = Document::query()->visibleTo($user);
            $officeQueueQuery = $this->officeQueueQuery($user, $offices, $isAdministrator);
            $officeQueueCounts = $this->officeQueueCounts($officeQueueQuery);
            $officeQueueDocumentCount = (int) ($officeQueueCounts->documents ?? 0);
            $queuePerPage = request()->integer('queue_per_page', self::OFFICE_QUEUE_LIMIT);
            $queuePerPage = in_array($queuePerPage, self::OFFICE_QUEUE_PAGE_SIZES, true) ? $queuePerPage : self::OFFICE_QUEUE_LIMIT;
            $queueLastPage = max(1, (int) ceil($officeQueueDocumentCount / $queuePerPage));
            $queuePage = min(max(1, request()->integer('queue_page', 1)), $queueLastPage);
            $canConfirmPaperIntake = $user->canConfirmPaperIntake();
            $isReadOnlySupervisor = $user->isReadOnlySupervisor();

            return [
                'section' => $section,
                'dashboardUser' => $user,
                'workspaceOffice' => $canViewAllDocuments
                    ? 'All offices'
                    : ($offices->isNotEmpty() ? $offices->join(', ') : 'No office assigned'),
                'officeQueue' => $this->officeQueueItems($officeQueueQuery, $queuePage, $queuePerPage),
                'officeQueueCount' => (int) ($officeQueueCounts->total ?? 0),
                'officeQueueDocumentCount' => $officeQueueDocumentCount,
                'queuePage' => $queuePage,
                'queueLastPage' => $queueLastPage,
                'queuePerPage' => $queuePerPage,
                'queuePageSizes' => self::OFFICE_QUEUE_PAGE_SIZES,
                // Summary cards: what is waiting for the office, what it sent that is still waiting elsewhere, and what it
                // finished this month. Records also sees paper waiting at the counter.
                'notReceivedCount' => (int) ($officeQueueCounts->not_received ?? 0),
                'paperWaitingCount' => $canConfirmPaperIntake ? (int) ($officeQueueCounts->paper_waiting ?? 0) : null,
                'sentWaitingCount' => $isReadOnlySupervisor ? 0 : $this->sentWaitingCount($offices, $isAdministrator),
                'archivedCount' => $isReadOnlySupervisor ? 0 : $this->archivedCount($user),
                'unreadNotifications' => $user->unreadNotifications()->limit(4)->get(),
                'unreadNotificationCount' => $user->unreadNotifications()->count(),
                'canConfirmPaperIntake' => $canConfirmPaperIntake,
                // Supervisors have no action queue, so their dashboard lists recent documents instead.
                'recentDocuments' => $isReadOnlySupervisor
                    ? (clone $visibleDocuments)->with('officeAssignments')->latest('updated_at')->limit(5)->get()
                    : collect(),
                'recentActivity' => DocumentMovement::query()
                    ->with(['document', 'actor'])
                    ->whereHas('document', fn (Builder $query): Builder => $query->visibleTo($user))
                    ->when(! $isAdministrator, fn (Builder $query): Builder => $query->where(fn (Builder $query): Builder => $query->whereIn('from_office', $offices)->orWhereIn('to_office', $offices)))
                    ->latest()
                    ->limit(4)
                    ->get(),
            ];
        }

        $typeOptions = collect(Document::typeOptions())
            ->merge(Document::query()->visibleTo($user)->distinct()->pluck('document_type'))
            ->unique()
            ->values()
            ->all();
        $officeOptions = collect(Document::officeOptions())
            ->merge(DocumentOfficeAssignment::query()
                ->whereHas('document', fn (Builder $document): Builder => $document->visibleTo($user))
                ->distinct()
                ->pluck('office'))
            ->merge(Document::query()
                ->visibleTo($user)
                ->doesntHave('officeAssignments')
                ->distinct()
                ->pluck('current_office'))
            ->unique()
            ->values()
            ->all();
        $filters = $this->filterValues($section, $typeOptions, $officeOptions);
        $filterError = $filters['from'] !== '' && $filters['to'] !== '' && $filters['to'] < $filters['from']
            ? 'The end date must be on or after the start date.'
            : '';
        $needsAction = $section === 'documents' && request()->boolean('needs_action');

        $documents = Document::query()
            ->with('officeAssignments')
            ->when($section === 'outgoing', fn (Builder $query): Builder => $query->with([
                'officeAssignments.movement',
                'forwardMovements:id,document_id,from_office',
            ]))
            ->visibleTo($user)
            ->when($needsAction, function (Builder $query) use ($user): Builder {
                if ($user->isReadOnlySupervisor()) {
                    return $query->whereKey(-1);
                }

                $offices = $user->assignedOfficeNames();

                return $query->whereHas('officeAssignments', fn (Builder $assignments): Builder => $assignments
                    ->whereIn('status', self::OFFICE_ACTION_STATUSES)
                    ->when(! $user->isAdministrator(), fn (Builder $assignments): Builder => $assignments->whereIn('office', $offices)));
            })
            ->when(
                ! $user->isAdministrator() && ! $user->hasAccess('platform.documents.view-all') && $section !== 'documents',
                fn (Builder $query): Builder => $this->applyOfficeSection($query, $user, $section),
                fn (Builder $query): Builder => $query->forSection($section),
            )
            ->registryFilters($filters)
            ->when($filterError !== '', fn (Builder $query): Builder => $query->whereKey(-1))
            ->filters()
            ->defaultSort('received_at', 'desc')
            ->when($needsAction, fn (Builder $query): Builder => $query->orderByDesc('documents.id'))
            ->paginate($this->perPage())
            ->withQueryString();

        return [
            'section' => $section,
            'documents' => $documents,
            'documentCount' => $documents->total(),
            'needsAction' => $needsAction,
            'filterValues' => $filters,
            'filterError' => $filterError,
            'typeOptions' => $typeOptions,
            'officeOptions' => $officeOptions,
        ];
    }

    /**
     * The name of the screen displayed in the header.
     */
    public function name(): ?string
    {
        // Becomes the browser tab title ("Incoming documents - DocuTrackS"), so each section names itself.
        return match ($this->section()) {
            'documents' => 'All documents',
            'incoming' => 'Incoming documents',
            'outgoing' => 'Outgoing documents',
            'archived' => 'Archived documents',
            default => 'Dashboard',
        };
    }

    /**
     * Display header description.
     */
    public function description(): ?string
    {
        // Each tab says in plain words what it lists.
        return match ($this->section()) {
            'documents' => 'Every document your office is working on or has handled.',
            'incoming' => 'Documents sent to your office that are waiting to be received.',
            'outgoing' => 'Documents your office sent on to other offices that are still being processed.',
            'archived' => 'Documents that are completed, released or archived.',
            default => 'Your office\'s documents and what needs attention today.',
        };
    }

    /**
     * The screen's action buttons.
     *
     * @return Action[]
     */
    public function commandBar(): iterable
    {
        return [];
    }

    /**
     * The screen's layout elements.
     *
     * @return \Orchid\Screen\Layout[]
     */
    public function layout(): iterable
    {
        if ($this->section() !== 'dashboard') {
            return [
                Layout::wrapper('orchid.registry-page', [
                    'controls' => Layout::view('orchid.registry'),
                    'pageSize' => Layout::view('orchid.table-page-size'),
                    'table' => DocumentListLayout::class,
                ]),
            ];
        }

        return [
            Layout::view('orchid.dashboard'),
        ];
    }

    private function section(): string
    {
        return request()->route()->defaults['section'] ?? 'dashboard';
    }

    private function officeQueueQuery(User $user, Collection $offices, bool $isAdministrator): Builder
    {
        $query = DocumentOfficeAssignment::query()
            ->whereIn('document_office_assignments.status', self::OFFICE_ACTION_STATUSES);

        if ($user->isReadOnlySupervisor()) {
            return $query->whereKey(-1);
        }

        if ($isAdministrator) {
            return $query;
        }

        if ($offices->isEmpty()) {
            return $query->whereKey(-1);
        }

        return $query
            ->whereIn('document_office_assignments.office', $offices)
            ->whereHas('document', fn (Builder $document): Builder => $document->visibleTo($user));
    }

    private function officeQueueCounts(Builder $officeQueueQuery): object
    {
        return (clone $officeQueueQuery)
            ->join('documents', 'documents.id', '=', 'document_office_assignments.document_id')
            ->whereNull('documents.deleted_at')
            ->toBase()
            ->selectRaw('COUNT(*) as total')
            ->selectRaw('COUNT(DISTINCT document_office_assignments.document_id) as documents')
            ->selectRaw('COUNT(DISTINCT CASE WHEN document_office_assignments.status = ? THEN documents.id END) as paper_waiting', [DocumentMovement::STATUS_AWAITING_PHYSICAL_DOCUMENT])
            ->selectRaw('COUNT(DISTINCT CASE WHEN document_office_assignments.status IN (?, ?, ?, ?) THEN documents.id END) as not_received', self::NOT_RECEIVED_STATUSES)
            ->first();
    }

    /**
     * Documents the user's offices sent on that the receiving office has not received yet (stuck hand-offs).
     * Administrators see every such hand-off.
     *
     * @param  Collection<int, string>  $offices
     */
    private function sentWaitingCount(Collection $offices, bool $isAdministrator): int
    {
        if (! $isAdministrator && $offices->isEmpty()) {
            return 0;
        }

        return DocumentOfficeAssignment::query()
            ->whereIn('document_office_assignments.status', self::NOT_RECEIVED_STATUSES)
            ->when(! $isAdministrator, fn (Builder $query): Builder => $query->whereNotIn('document_office_assignments.office', $offices))
            ->whereHas('document')
            ->whereExists(fn ($movements) => $movements->selectRaw('1')
                ->from('document_movements')
                ->whereColumn('document_movements.id', 'document_office_assignments.movement_id')
                ->whereColumn('document_movements.document_id', 'document_office_assignments.document_id')
                ->whereColumn('document_movements.to_office', 'document_office_assignments.office')
                ->whereNotNull('document_movements.from_office')
                ->whereColumn('document_movements.from_office', '!=', 'document_movements.to_office')
                ->when(! $isAdministrator, fn ($query) => $query->whereIn('document_movements.from_office', $offices)))
            ->distinct()
            ->count('document_office_assignments.document_id');
    }

    /**
     * Every document on the user's Archived page, so the card and that page always show the same number.
     */
    private function archivedCount(User $user): int
    {
        return Document::query()
            ->visibleTo($user)
            ->when(
                ! $user->isAdministrator() && ! $user->hasAccess('platform.documents.view-all'),
                fn (Builder $query): Builder => $this->applyOfficeSection($query, $user, 'archived'),
                fn (Builder $query): Builder => $query->forSection('archived'),
            )
            ->count();
    }

    /**
     * One row per document, each listing its offices that still have work to do.
     *
     * @return Collection<int, array{document: Document, offices: Collection<int, array{office: string, status: string, action: string}>}>
     */
    private function officeQueueItems(Builder $officeQueueQuery, int $page, int $perPage = self::OFFICE_QUEUE_LIMIT): Collection
    {
        $documentIds = (clone $officeQueueQuery)
            ->join('documents', 'documents.id', '=', 'document_office_assignments.document_id')
            ->whereNull('documents.deleted_at')
            ->toBase()
            ->select('document_office_assignments.document_id')
            ->groupBy('document_office_assignments.document_id', 'documents.updated_at')
            ->orderByRaw('MAX(CASE WHEN document_office_assignments.status = ? THEN 1 ELSE 0 END) DESC', [DocumentMovement::STATUS_AWAITING_PHYSICAL_DOCUMENT])
            ->orderByDesc('documents.updated_at')
            ->orderByDesc('document_office_assignments.document_id')
            ->offset(($page - 1) * $perPage)
            ->limit($perPage)
            ->pluck('document_id')
            ->map(fn (int|string $documentId): int => (int) $documentId);

        $assignments = (clone $officeQueueQuery)
            ->whereIn('document_office_assignments.document_id', $documentIds)
            ->with([
                'document.officeAssignments.movement',
                'document.forwardMovements:id,document_id,from_office',
            ])
            ->orderBy('document_office_assignments.id')
            ->get()
            ->groupBy('document_id');

        return $documentIds
            ->filter(fn (int $documentId): bool => $assignments->has($documentId))
            ->map(fn (int $documentId): array => [
                'document' => $assignments[$documentId]->first()->document,
                'offices' => $assignments[$documentId]
                    ->sortByDesc(fn (DocumentOfficeAssignment $assignment): bool => $assignment->status === DocumentMovement::STATUS_AWAITING_PHYSICAL_DOCUMENT)
                    ->map(fn (DocumentOfficeAssignment $assignment): array => [
                        'office' => $assignment->office,
                        'status' => $assignment->status,
                        'action' => $this->nextActionLabel($assignment->status),
                    ])
                    ->values(),
            ])
            ->values();
    }

    private function nextActionLabel(string $status): string
    {
        return match ($status) {
            DocumentMovement::STATUS_AWAITING_PHYSICAL_DOCUMENT => 'Confirm document received',
            DocumentMovement::STATUS_AWAITING_RECEIPT,
            DocumentMovement::STATUS_FORWARDED,
            DocumentMovement::STATUS_RETURNED,
            DocumentMovement::STATUS_RESUBMITTED => 'Process',
            DocumentMovement::STATUS_RECEIVED => 'Process',
            default => 'View',
        };
    }

    private function applyOfficeSection(Builder $query, User $user, string $section): Builder
    {
        $offices = $user->assignedOfficeNames();

        return $offices->isEmpty() ? $query->whereKey(-1) : $query->forSection($section, $offices);
    }

    /**
     * @return array{search: string, office: string, type: string, status: string, priority: string, from: string, to: string}
     */
    private function filterValues(string $section, array $typeOptions, array $officeOptions): array
    {
        $office = request()->string('office')->trim()->toString();
        $type = request()->string('type')->trim()->toString();
        $status = request()->string('status')->trim()->toString();

        return [
            'search' => Str::limit(request()->string('search')->trim()->toString(), 150, ''),
            'office' => in_array($office, $officeOptions, true) ? $office : '',
            'type' => in_array($type, $typeOptions, true) ? $type : '',
            'status' => $section === 'documents' && in_array($status, [...Document::STATUSES, StatusLabel::PENDING], true) ? $status : '',
            'priority' => '',
            'from' => $this->dateFilter('from'),
            'to' => $this->dateFilter('to'),
        ];
    }

    private function dateFilter(string $key): string
    {
        $value = request()->string($key)->toString();
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $date !== false && $date->format('Y-m-d') === $value ? $value : '';
    }

    private function perPage(): int
    {
        $perPage = request()->integer('per_page', 10);

        return in_array($perPage, [10, 25, 50, 100], true) ? $perPage : 10;
    }
}
