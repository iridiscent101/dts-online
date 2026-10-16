<?php

namespace App\Http\Controllers;

use App\Models\Document;
use App\Models\DocumentMovement;
use App\Models\User;
use App\Services\DocumentRoutingService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class DocumentWorkspaceController extends Controller
{
    private const HISTORY_PAGE_SIZE = 20;

    public function __invoke(Request $request, Document $document, DocumentRoutingService $routing): View
    {
        $user = $request->user();
        abort_unless($user !== null && $document->isVisibleTo($user), 404);

        if ($request->has('history_page')) {
            $validated = $request->validate([
                'history_page' => ['required', 'integer', 'min:1'],
                'office' => ['nullable', 'string', 'max:180'],
            ]);

            return view('orchid.partials.document-workspace-history', $this->historyPage($document, $user, (int) $validated['history_page'], $validated['office'] ?? null));
        }

        $document->load(['registeredBy', 'officeAssignments']);

        // Route-card dates are another office's actions, so staff limited to their own offices only get theirs.
        $historyOffices = $user->canViewFullDocumentHistory() ? null : $user->assignedOfficeNames();
        $sentDates = $document->movements()->whereIn('action', DocumentMovement::DESTINATION_ACTIONS)
            ->when($historyOffices !== null, fn (Builder $query): Builder => $query->whereIn('to_office', $historyOffices))
            ->reorder()->select('to_office')->selectRaw('MIN(created_at) as sent_at')->groupBy('to_office')->get()
            ->mapWithKeys(fn (DocumentMovement $movement): array => [$movement->to_office => Carbon::parse($movement->sent_at, 'UTC')]);
        $receivedDates = $document->movements()->where('action', DocumentMovement::ACTION_RECEIVED)
            ->when($historyOffices !== null, fn (Builder $query): Builder => $query->whereIn('to_office', $historyOffices))
            ->reorder()->select('to_office')->selectRaw('MAX(created_at) as received_at')->groupBy('to_office')->get()
            ->mapWithKeys(fn (DocumentMovement $movement): array => [$movement->to_office => Carbon::parse($movement->received_at, 'UTC')]);
        $workflowStates = $routing->workflowStates($user, $document);
        $workflowActionOptions = $workflowStates->mapWithKeys(fn (array $state): array => [
            $state['office'] => $routing->availableActionOptions($user, $document, $state),
        ])->all();

        return view('orchid.document-workspace', [
            'document' => $document,
            ...$this->historyPage($document, $user, 1, null),
            'sentDates' => $sentDates,
            'receivedDates' => $receivedDates,
            'historyOfficeNames' => $historyOffices ?? $user->assignedOfficeNames(),
            'canViewAllHistory' => $user->canViewFullDocumentHistory(),
            'workflowStates' => $workflowStates,
            'workflowActionOptions' => $workflowActionOptions,
            'canRoute' => collect($workflowActionOptions)->contains(fn (array $actions): bool => $actions !== []),
            'destinationOffices' => $workflowStates->isEmpty() ? [] : Document::officeOptions(),
        ]);
    }

    /**
     * @return array{document: Document, history: Collection<int, mixed>, historyHasMore: bool, historyPage: int, historyOffice: ?string, historyShown: int, historyTotal: int}
     */
    private function historyPage(Document $document, User $user, int $page, ?string $office): array
    {
        $visibleOffices = $user->canViewFullDocumentHistory() ? null : $user->assignedOfficeNames();
        abort_if($office !== null && $visibleOffices !== null && ! $visibleOffices->contains($office), 403);
        abort_if($page > intdiv(PHP_INT_MAX, self::HISTORY_PAGE_SIZE), 422);
        $offices = $office !== null ? collect([$office]) : $visibleOffices;
        $movements = $document->movements()
            ->when($offices !== null, fn (Builder $query): Builder => $query->where(fn (Builder $query): Builder => $query->whereIn('from_office', $offices)->orWhereIn('to_office', $offices)))
            ->reorder();
        $activities = $document->activities()
            ->whereIn('description', Document::AUDIT_DESCRIPTIONS)
            ->when($offices !== null, fn (Builder $query): Builder => $query->where(fn (Builder $query): Builder => $query->whereNotIn('description', ['Document routes updated', 'Document route corrected'])->orWhereIn('properties->office', $offices)->orWhereIn('properties->from_office', $offices)->orWhereIn('properties->to_office', $offices)))
            ->reorder();
        $remarks = $document->activities()
            ->where('description', 'Remark added')
            ->when($offices !== null, fn (Builder $query): Builder => $query->where(fn (Builder $query): Builder => $query->whereIn('properties->office', $offices)->orWhereIn('properties->from_office', $offices)->orWhereIn('properties->to_office', $offices)))
            ->reorder();

        $history = $movements->selectRaw('id, created_at, 0 as source_rank')
            ->unionAll($activities->selectRaw('id, created_at, 1 as source_rank'))
            ->unionAll($remarks->selectRaw('id, created_at, 2 as source_rank'));
        $rows = DB::query()->fromSub($history, 'history')->orderByDesc('created_at')->orderBy('source_rank')->orderByDesc('id')
            ->offset(($page - 1) * self::HISTORY_PAGE_SIZE)
            ->limit(self::HISTORY_PAGE_SIZE + 1)
            ->get(['id', 'source_rank']);

        $pageRows = $rows->take(self::HISTORY_PAGE_SIZE);
        $movementEvents = $document->movements()->with('actor')
            ->whereKey($pageRows->where('source_rank', 0)->pluck('id'))
            ->get()->keyBy('id');
        $activityEvents = $document->activities()->with('causer')
            ->whereKey($pageRows->where('source_rank', '!=', 0)->pluck('id'))
            ->get()->keyBy('id');
        $events = $pageRows->map(fn (object $row): mixed => (int) $row->source_rank === 0
            ? $movementEvents->get($row->id)
            : $activityEvents->get($row->id));

        return [
            'document' => $document,
            'history' => $events,
            'historyHasMore' => $rows->count() > self::HISTORY_PAGE_SIZE,
            'historyPage' => $page,
            'historyOffice' => $office,
            // Shown as "Showing 40 of 120 entries" so staff can tell how much older activity there is.
            'historyShown' => ($page - 1) * self::HISTORY_PAGE_SIZE + $pageRows->count(),
            'historyTotal' => DB::query()->fromSub($history, 'history')->count(),
        ];
    }
}
