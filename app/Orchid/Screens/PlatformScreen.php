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
    /** Documents the dashboard queue holds; five show at first, the rest behind "Show more". */
    private const OFFICE_QUEUE_LIMIT = 25;

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
            $canConfirmPaperIntake = $user->canConfirmPaperIntake();
            $isReadOnlySupervisor = $user->isReadOnlySupervisor();

            return [
                'section' => $section,
                'dashboardUser' => $user,
                'workspaceOffice' => $canViewAllDocuments
                    ? 'All offices'
                    : ($offices->isNotEmpty() ? $offices->join(', ') : 'No office assigned'),
                'officeQueue' => $this->officeQueueItems($officeQueueQuery),
                'officeQueueCount' => (int) ($officeQueueCounts->total ?? 0),
                'officeQueueDocumentCount' => (int) ($officeQueueCounts->documents ?? 0),
                // The fourth summary card: Records counts paper waiting at the counter, other offices what they finished.
                'paperWaitingCount' => $canConfirmPaperIntake ? (int) ($officeQueueCounts->paper_waiting ?? 0) : null,
                'actedThisWeekCount' => $canConfirmPaperIntake || $isReadOnlySupervisor ? null : $this->actedThisWeekCount($offices),
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

        $documents = Document::query()
            ->with('officeAssignments')
            ->visibleTo($user)
            ->when(
                ! $user->isAdministrator() && ! $user->hasAccess('platform.documents.view-all') && $section !== 'documents',
                fn (Builder $query): Builder => $this->applyOfficeSection($query, $user, $section),
                fn (Builder $query): Builder => $query->forSection($section),
            )
            ->registryFilters($filters)
            ->when($filterError !== '', fn (Builder $query): Builder => $query->whereKey(-1))
            ->filters()
            ->defaultSort('received_at', 'desc')
            ->paginate($this->perPage());

        return [
            'section' => $section,
            'documents' => $documents,
            'documentCount' => $documents->total(),
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
            'archived' => 'Finished documents',
            default => 'Dashboard',
        };
    }

    /**
     * Display header description.
     */
    public function description(): ?string
    {
        return 'Department of Education | Records Management';
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
            ->first();
    }

    /**
     * Documents the user's offices moved on this week (forwarded, completed, returned…), not counting receipts.
     *
     * @param  Collection<int, string>  $offices
     */
    private function actedThisWeekCount(Collection $offices): int
    {
        if ($offices->isEmpty()) {
            return 0;
        }

        return DocumentMovement::query()
            ->whereIn('from_office', $offices)
            ->whereNotIn('action', [DocumentMovement::ACTION_RECEIVED, DocumentMovement::ACTION_ACKNOWLEDGE])
            ->where('created_at', '>=', today('Asia/Manila')->startOfWeek()->utc())
            ->whereHas('document')
            ->distinct()
            ->count('document_id');
    }

    /**
     * One row per document, each listing its offices that still have work to do.
     *
     * @return Collection<int, array{document: Document, offices: Collection<int, array{office: string, status: string, action: string}>}>
     */
    private function officeQueueItems(Builder $officeQueueQuery): Collection
    {
        $documentIds = (clone $officeQueueQuery)
            ->join('documents', 'documents.id', '=', 'document_office_assignments.document_id')
            ->whereNull('documents.deleted_at')
            ->toBase()
            ->select('document_office_assignments.document_id')
            ->groupBy('document_office_assignments.document_id', 'documents.updated_at')
            ->orderByRaw('MAX(CASE WHEN document_office_assignments.status = ? THEN 1 ELSE 0 END) DESC', [DocumentMovement::STATUS_AWAITING_PHYSICAL_DOCUMENT])
            ->orderByDesc('documents.updated_at')
            ->limit(self::OFFICE_QUEUE_LIMIT)
            ->pluck('document_id')
            ->map(fn (int|string $documentId): int => (int) $documentId);

        $assignments = (clone $officeQueueQuery)
            ->whereIn('document_office_assignments.document_id', $documentIds)
            ->with('document')
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
            DocumentMovement::STATUS_AWAITING_PHYSICAL_DOCUMENT => 'Confirm paper received',
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
