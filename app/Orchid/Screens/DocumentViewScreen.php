<?php

namespace App\Orchid\Screens;

use App\Models\Document;
use App\Models\DocumentMovement;
use App\Services\DocumentRoutingService;
use App\Services\DocumentTrackingLabel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Orchid\Screen\Action;
use Orchid\Screen\Screen;
use Orchid\Support\Facades\Layout;

class DocumentViewScreen extends Screen
{
    private ?Document $document = null;

    /**
     * Fetch data to be displayed on the screen.
     *
     * @return array
     */
    public function query(Document $document): iterable
    {
        $user = request()->user();
        abort_unless($user !== null && $document->isVisibleTo($user), 404);

        $this->document = $document->load(['registeredBy', 'officeAssignments']);
        $visibleOffices = $user->canViewFullDocumentHistory() ? null : $user->assignedOfficeNames();
        $movements = $document->movements()
            ->with('actor')
            ->when($visibleOffices !== null, fn (Builder $query): Builder => $query->where(fn (Builder $query): Builder => $query->whereIn('from_office', $visibleOffices)->orWhereIn('to_office', $visibleOffices)))
            ->reorder()
            ->oldest()
            ->oldest('id')
            ->get();
        $this->document->setRelation('movements', $movements);
        $activities = $document->activities()
            ->whereIn('description', Document::AUDIT_DESCRIPTIONS)
            ->with('causer')
            ->when($visibleOffices !== null, fn (Builder $query): Builder => $query->where(fn (Builder $query): Builder => $query->where('description', '!=', 'Document routes updated')->orWhereIn('properties->office', $visibleOffices)->orWhereIn('properties->from_office', $visibleOffices)->orWhereIn('properties->to_office', $visibleOffices)))
            ->reorder()->latest('created_at')->latest('id')
            ->limit(Document::AUDIT_DISPLAY_LIMIT)
            ->get();
        $this->document->setRelation(
            'activities',
            $activities,
        );

        $lastEdit = $activities->first();
        $lastMovement = $this->document->movements->last();
        $lastChange = $lastMovement !== null
            && ($lastEdit === null || $lastMovement->created_at->greaterThan($lastEdit->created_at))
                ? $lastMovement
                : $lastEdit;
        $lastChangedBy = $lastChange instanceof DocumentMovement
            ? $lastChange->actor?->name
            : $lastChange?->causer?->name;
        $lastDownload = $document->activities()
            ->where('description', 'Attachment downloaded')
            ->when($visibleOffices !== null, function (Builder $query) use ($visibleOffices): Builder {
                if ($visibleOffices->isEmpty()) {
                    return $query->whereIn('id', []);
                }

                return $query->where(function (Builder $query) use ($visibleOffices): void {
                    foreach ($visibleOffices as $office) {
                        $query->orWhereJsonContains('properties->offices', $office);
                    }
                });
            })
            ->with('causer')
            ->reorder()->latest('created_at')->latest('id')
            ->first();

        $routing = app(DocumentRoutingService::class);
        $workflowStates = $user === null ? collect() : $routing->workflowStates($user, $this->document);
        $requestedWorkflowOffice = request()->old('workflow.source_office', request()->query('source_office'));
        $workflowState = filled($requestedWorkflowOffice)
            ? $workflowStates->first(fn (array $state): bool => $state['office'] === $requestedWorkflowOffice)
            : null;

        $workflowState ??= $workflowStates->first(
            fn (array $state): bool => DocumentMovement::requiresAcknowledgement($state['status']),
        ) ?? $workflowStates->first();
        $workflowActionOptions = $workflowStates->mapWithKeys(fn (array $state): array => [
            $state['office'] => $routing->availableActionOptions($user, $this->document, $state),
        ])->all();

        return [
            'document' => $this->document,
            'pendingRoutes' => $this->document->status === DocumentMovement::STATUS_AWAITING_PHYSICAL_DOCUMENT
                ? DB::table('document_route_steps')->where('document_id', $document->id)->orderBy('sequence')->get()
                : collect(),
            'canViewAllHistory' => $visibleOffices === null,
            'qrCodeDataUri' => app(DocumentTrackingLabel::class)->dataUri($this->document),
            'labelDetails' => app(DocumentTrackingLabel::class)->details($this->document),
            'lastChangedBy' => $lastChangedBy
                ?? $this->document->registeredBy?->name
                ?? 'System',
            'lastChangedAt' => $lastChange?->created_at ?? $this->document->updated_at,
            'lastDownloadedBy' => $lastDownload?->causer?->name,
            'lastDownloadedAt' => $lastDownload?->created_at,
            'lastDownloadedFile' => data_get($lastDownload?->properties, 'file_name'),
            'downloadsScopedToOffice' => $visibleOffices !== null,
            'canManageWorkflow' => $workflowStates->isNotEmpty(),
            'workflowStates' => $workflowStates,
            'workflowOffice' => $workflowState['office'] ?? $this->document->current_office,
            'workflowStatus' => $workflowState['status'] ?? $this->document->status,
            'workflowActionOptionsByOffice' => $workflowActionOptions,
            'canEditDocument' => $user !== null && $this->document->canBeEditedBy($user),
        ];
    }

    /**
     * The name of the screen displayed in the header.
     */
    public function name(): ?string
    {
        return $this->document?->tracking_number ?? 'Document details';
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
     * @return \Orchid\Screen\Layout[]|string[]
     */
    public function layout(): iterable
    {
        return [Layout::view('orchid.document-view')];
    }

    public function permission(): ?iterable
    {
        return ['platform.index'];
    }
}
