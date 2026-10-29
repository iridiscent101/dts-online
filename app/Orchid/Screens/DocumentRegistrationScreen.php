<?php

declare(strict_types=1);

namespace App\Orchid\Screens;

use App\Http\Requests\StoreDocumentRequest;
use App\Models\Document;
use App\Models\DocumentMovement;
use App\Models\Office;
use App\Notifications\DocumentNotificationDispatcher;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Orchid\Attachment\File;
use Orchid\Attachment\Models\Attachment;
use Orchid\Screen\Action;
use Orchid\Screen\Screen;
use Orchid\Support\Facades\Layout;
use Orchid\Support\Facades\Toast;
use Throwable;

class DocumentRegistrationScreen extends Screen
{
    /**
     * Fetch data to be displayed on the screen.
     *
     * @return array
     */
    public function query(): iterable
    {
        abort_unless(request()->user()?->canRegisterDocument(), 403);

        return [];
    }

    /**
     * The name of the screen displayed in the header.
     */
    public function name(): ?string
    {
        return 'New document';
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

    public function save(
        StoreDocumentRequest $request,
        DocumentNotificationDispatcher $notifications,
    ): RedirectResponse {
        $validated = $request->validated();
        $data = $validated['document'];
        $destinations = $data['destinations'];
        $routeRemarks = $data['route_remarks'] ?? [];
        $uploadedAttachments = [];

        try {
            $document = DB::transaction(function () use ($data, $destinations, $routeRemarks, $request, $notifications, &$uploadedAttachments): Document {
                $document = Document::create([
                    'tracking_number' => Document::newTrackingNumber(),
                    'subject' => $data['title'],
                    'document_type' => $data['type'],
                    'external_reference' => $data['reference'] ?? null,
                    'description' => $data['description'] ?? null,
                    'origin' => $data['origin'],
                    'sender' => $data['sender'],
                    'client_email' => filled($data['client_email'] ?? null) ? Str::lower(trim($data['client_email'])) : null,
                    'current_office' => $destinations[0],
                    'status' => DocumentMovement::STATUS_AWAITING_RECEIPT,
                    'received_at' => $data['received'],
                    'priority' => 'Standard',
                    'remarks' => filled($routeRemarks[count($destinations) - 1] ?? null)
                        ? trim($routeRemarks[count($destinations) - 1])
                        : null,
                    'registered_by' => $request->user()?->getAuthIdentifier(),
                    'registered_office' => $request->user()?->assignedOfficeNames()->intersect(Office::RECORDS_UNIT_NAMES)->first()
                        ?? Office::RECORDS_UNIT_NAME,
                ]);

                foreach ($destinations as $index => $office) {
                    $movement = $document->movements()->create([
                        'action' => DocumentMovement::ACTION_SENT,
                        'from_office' => null,
                        'to_office' => $office,
                        'from_status' => null,
                        'to_status' => $document->status,
                        'remarks' => filled($routeRemarks[$index] ?? null) ? trim($routeRemarks[$index]) : null,
                        'acted_by' => $request->user()?->getAuthIdentifier(),
                    ]);

                    $document->officeAssignments()->create([
                        'office' => $office,
                        'status' => $document->status,
                        'assigned_by' => $request->user()?->getAuthIdentifier(),
                        'movement_id' => $movement->getKey(),
                    ]);
                }

                foreach ($request->file('attachments', []) as $uploadedFile) {
                    $attachment = (new File($uploadedFile, 'local', 'documents'))->load();
                    $uploadedAttachments[] = $attachment;
                    $document->attachments()->attach($attachment->getKey());
                }

                $document->recordActivity('Document registered', $request->user(), [
                    'action' => 'register',
                    'office' => $document->current_office,
                    'offices' => $destinations,
                    'status' => $document->status,
                    'attachment_count' => count($uploadedAttachments),
                ]);

                $notifications->assigned($document, 'register', $request->user(), $destinations);

                return $document;
            });
        } catch (Throwable $exception) {
            foreach ($uploadedAttachments as $attachment) {
                if ($attachment instanceof Attachment && ! Attachment::query()
                    ->where('hash', $attachment->hash)
                    ->where('disk', $attachment->disk)
                    ->exists()) {
                    Storage::disk($attachment->disk)->delete($attachment->physicalPath());
                }
            }

            report($exception);
            Toast::error('The document could not be registered. Please try again.');

            return back()->withInput();
        }

        Toast::success("Document {$document->tracking_number} was registered.");

        return redirect()->route('platform.documents');
    }

    /**
     * The screen's layout elements.
     *
     * @return \Orchid\Screen\Layout[]|string[]
     */
    public function layout(): iterable
    {
        return [Layout::view('orchid.document-registration')];
    }
}
