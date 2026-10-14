<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreClientDocumentSubmissionRequest;
use App\Models\Document;
use App\Models\DocumentMovement;
use App\Notifications\DocumentNotificationDispatcher;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Orchid\Attachment\File;
use Orchid\Attachment\Models\Attachment;
use Throwable;

class ClientDocumentSubmissionController extends Controller
{
    private const RECORDS_OFFICE = 'SDO-Records Unit';

    public function create(): View
    {
        return view('client-document-submission', [
            'submissionKey' => (string) Str::uuid(),
            'documentTypes' => Document::typeOptions(),
            'officeOptions' => Document::officeOptions(),
            'originOptions' => Document::originOptions(),
        ]);
    }

    public function store(StoreClientDocumentSubmissionRequest $request, DocumentNotificationDispatcher $notifications): RedirectResponse
    {
        $data = $request->validated();
        $existingDocument = Document::query()->where('client_submission_key', $data['submission_key'])->first();

        if ($existingDocument !== null) {
            return redirect()->route('tracking.index', ['tracking' => $existingDocument->tracking_number])
                ->with('submitted', true);
        }

        $attachment = null;
        try {
            $document = DB::transaction(function () use ($data, $request, $notifications, &$attachment): Document {
                $requestedDestinations = $data['destinations'];
                $routeRemarks = $data['route_remarks'] ?? [];
                $physicalSubmission = ! $request->hasFile('attachment');
                $destinations = $physicalSubmission ? [self::RECORDS_OFFICE] : $requestedDestinations;
                $recordsIndex = array_search(self::RECORDS_OFFICE, $requestedDestinations, true);
                $intakeRemark = $recordsIndex !== false && filled($routeRemarks[$recordsIndex] ?? null)
                    ? trim($routeRemarks[$recordsIndex])
                    : null;
                $document = Document::create([
                    'tracking_number' => 'DTS-'.today('Asia/Manila')->format('Y').'-'.Str::upper(bin2hex(random_bytes(10))),
                    'subject' => $data['title'],
                    'document_type' => $data['type'],
                    'sender' => $data['name'],
                    'origin' => $data['office'],
                    'current_office' => $destinations[0],
                    'status' => $physicalSubmission
                        ? DocumentMovement::STATUS_AWAITING_PHYSICAL_DOCUMENT
                        : DocumentMovement::STATUS_AWAITING_RECEIPT,
                    'received_at' => $physicalSubmission ? null : today('Asia/Manila')->toDateString(),
                    'due_at' => $physicalSubmission ? null : Document::targetDueDate(today('Asia/Manila'), 'Standard')->toDateString(),
                    'priority' => 'Standard',
                    'remarks' => filled($routeRemarks[count($requestedDestinations) - 1] ?? null)
                        ? trim($routeRemarks[count($requestedDestinations) - 1])
                        : null,
                    'client_submission_key' => $data['submission_key'],
                ]);

                if ($physicalSubmission) {
                    foreach ($requestedDestinations as $index => $office) {
                        DB::table('document_route_steps')->insert([
                            'document_id' => $document->id,
                            'sequence' => $index + 1,
                            'office' => $office,
                            'remarks' => filled($routeRemarks[$index] ?? null) ? trim($routeRemarks[$index]) : null,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
                    }
                }
                foreach ($destinations as $index => $office) {
                    $movement = $document->movements()->create([
                        'action' => DocumentMovement::ACTION_SENT,
                        'from_office' => null,
                        'to_office' => $office,
                        'from_status' => null,
                        'to_status' => $document->status,
                        'remarks' => $physicalSubmission ? $intakeRemark : (filled($routeRemarks[$index] ?? null) ? trim($routeRemarks[$index]) : null),
                    ]);

                    $document->officeAssignments()->create([
                        'office' => $office,
                        'status' => $document->status,
                        'movement_id' => $movement->getKey(),
                    ]);
                }

                if ($request->hasFile('attachment')) {
                    $attachment = (new File($request->file('attachment'), 'local', 'documents'))->load();
                    $document->attachments()->attach($attachment->getKey());
                }

                activity('documents')
                    ->performedOn($document)
                    ->withProperties(['action' => 'client_submit', 'offices' => $requestedDestinations, 'attachment_count' => $attachment === null ? 0 : 1, 'privacy_consent' => true])
                    ->log('Document submitted');

                $notifications->submitted($document);

                return $document;
            });
        } catch (Throwable $exception) {
            if ($attachment instanceof Attachment && ! Attachment::query()
                ->where('hash', $attachment->hash)
                ->where('disk', $attachment->disk)
                ->exists()) {
                Storage::disk($attachment->disk)->delete($attachment->physicalPath());
            }

            $existingDocument = Document::query()->where('client_submission_key', $data['submission_key'])->first();
            if ($existingDocument !== null) {
                return redirect()->route('tracking.index', ['tracking' => $existingDocument->tracking_number])
                    ->with('submitted', true);
            }

            report($exception);

            return back()->withInput()->withErrors([
                'submission' => 'The document could not be sent. Please try again.',
            ]);
        }

        return redirect()->route('tracking.index', ['tracking' => $document->tracking_number])
            ->with('submitted', true);
    }
}
