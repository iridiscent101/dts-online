<?php

namespace App\Http\Controllers;

use App\Models\Document;
use App\Models\DocumentMovement;
use App\Services\DocumentTrackingLabel;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class PublicDocumentTrackingController extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(Request $request, DocumentTrackingLabel $trackingLabel): View
    {
        $trackingInput = $request->query('tracking', '');
        $trackingNumber = is_string($trackingInput) ? Str::upper(trim($trackingInput)) : '';
        $document = null;
        $trackingError = is_string($trackingInput) ? null : 'Enter a valid tracking number.';
        $searched = $trackingInput !== '';

        if ($searched && $trackingError === null) {
            $validator = Validator::make(
                ['tracking' => $trackingNumber],
                ['tracking' => ['required', 'string', 'max:32', 'regex:/^(?:SDONV[1-9]{11}|DTS-[A-Z0-9-]+)$/']],
                ['tracking.regex' => 'Enter a valid tracking number.'],
                ['tracking' => 'tracking number'],
            );

            if ($validator->fails()) {
                $trackingError = $validator->errors()->first('tracking');
            } else {
                $document = Document::query()
                    ->select([
                        'id',
                        'tracking_number',
                        'subject',
                        'document_type',
                        'delivery_method',
                        'sender',
                        'client_submission_key',
                        'current_office',
                        'status',
                        'received_at',
                        'created_at',
                    ])
                    ->withCount('attachments')
                    ->with([
                        'officeAssignments:id,document_id,office,status',
                        'movements' => function (HasMany $query): void {
                            $query
                                ->select([
                                    'id',
                                    'document_id',
                                    'action',
                                    'from_office',
                                    'to_office',
                                    'from_status',
                                    'to_status',
                                    'remarks',
                                    'released_to',
                                    'acted_by',
                                    'created_at',
                                ])
                                ->with('actor:id,name')
                                ->reorder()
                                ->oldest('created_at')
                                ->oldest('id');
                        },
                    ])
                    ->where('tracking_number', $trackingNumber)
                    ->first();
            }
        }

        $registrationOffices = $document?->movements
            ->takeWhile(fn (DocumentMovement $movement): bool => $movement->action === DocumentMovement::ACTION_SENT && $movement->from_office === null)
            ->pluck('to_office')
            ->unique();
        $initialOffice = $registrationOffices?->isNotEmpty()
            ? $registrationOffices->join(', ', ' and ')
            : ($document?->movements->first()?->from_office ?: $document?->current_office);
        if ($registrationOffices?->isEmpty()) {
            $registrationOffices = collect([$initialOffice])->filter();
        }
        // Remarks staff add for an office ("Add remark") are activity entries, not movements; the public history shows them
        // as their own steps. Only the office, text, staff name and time leave the server.
        $publicRemarks = $document
            ? $document->activities()
                ->reorder()
                ->where('description', 'Remark added')
                ->with('causer:id,name')
                ->oldest('created_at')
                ->oldest('id')
                ->get()
                ->map(fn ($activity): array => [
                    'office' => (string) data_get($activity->properties, 'office', ''),
                    'remark' => (string) data_get($activity->properties, 'remark', ''),
                    'by' => $activity->causer?->name,
                    'at' => $activity->created_at,
                ])
                ->filter(fn (array $remark): bool => $remark['remark'] !== '')
                ->values()
            : collect();
        $assignedOffices = $document?->assignedOfficeStatuses() ?? collect();
        // The office cards also list a paper submission's offices that are still waiting for the paper copy.
        $routeOffices = $document?->officeRouteStatuses() ?? collect();
        $timelineOffices = $document?->movements
            ->flatMap(fn (DocumentMovement $movement): array => [$movement->from_office, $movement->to_office])
            ->merge($routeOffices->pluck('office'))
            ->filter()
            ->unique()
            ->sort()
            ->values() ?? collect();
        $officeInput = $request->query('office');
        $selectedOffice = is_string($officeInput) ? trim($officeInput) : '';
        $selectedOffice = $timelineOffices->contains($selectedOffice) ? $selectedOffice : '';
        $showRegistrationEvent = $selectedOffice === '' || $registrationOffices?->contains($selectedOffice);
        $showEmptyTimeline = $document && ! $showRegistrationEvent && ! $document->movements->contains(
            fn (DocumentMovement $movement): bool => $movement->from_office === $selectedOffice || $movement->to_office === $selectedOffice,
        ) && ! $publicRemarks->contains('office', $selectedOffice);
        $qrCodeDataUri = $document ? $trackingLabel->dataUri($document) : null;
        $submissionKey = (string) Str::uuid();
        $documentTypes = Document::typeOptions();
        $officeOptions = Document::officeOptions();
        $originOptions = Document::originOptions();

        return view('document-tracking', compact(
            'document',
            'registrationOffices',
            'initialOffice',
            'assignedOffices',
            'routeOffices',
            'publicRemarks',
            'timelineOffices',
            'selectedOffice',
            'showRegistrationEvent',
            'showEmptyTimeline',
            'searched',
            'trackingError',
            'trackingNumber',
            'qrCodeDataUri',
            'submissionKey',
            'documentTypes',
            'officeOptions',
            'originOptions',
        ));
    }
}
