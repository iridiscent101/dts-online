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
            'originOptions' => Document::originOptions(),
            'deliveryMethods' => Document::DELIVERY_METHODS,
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
                $physicalSubmission = ! $request->hasFile('attachment');
                $document = Document::create([
                    'tracking_number' => Document::newTrackingNumber(),
                    'subject' => $data['title'],
                    'document_type' => $data['type'],
                    'sender' => $data['name'],
                    'origin' => $data['office'],
                    'delivery_method' => $data['delivery_method'],
                    'current_office' => self::RECORDS_OFFICE,
                    'status' => $physicalSubmission
                        ? DocumentMovement::STATUS_AWAITING_PHYSICAL_DOCUMENT
                        : DocumentMovement::STATUS_AWAITING_RECEIPT,
                    'received_at' => $physicalSubmission ? null : today('Asia/Manila')->toDateString(),
                    'priority' => 'Standard',
                    'client_submission_key' => $data['submission_key'],
                ]);

                $movement = $document->movements()->create([
                    'action' => DocumentMovement::ACTION_SENT,
                    'from_office' => null,
                    'to_office' => self::RECORDS_OFFICE,
                    'from_status' => null,
                    'to_status' => $document->status,
                    'remarks' => $data['client_remarks'] ?? null,
                ]);

                $document->officeAssignments()->create([
                    'office' => self::RECORDS_OFFICE,
                    'status' => $document->status,
                    'movement_id' => $movement->getKey(),
                ]);

                if ($request->hasFile('attachment')) {
                    $attachment = (new File($request->file('attachment'), 'local', 'documents'))->load();
                    $document->attachments()->attach($attachment->getKey());
                }

                activity('documents')
                    ->performedOn($document)
                    ->withProperties(['action' => 'client_submit', 'offices' => [self::RECORDS_OFFICE], 'attachment_count' => $attachment === null ? 0 : 1, 'privacy_consent' => true])
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
