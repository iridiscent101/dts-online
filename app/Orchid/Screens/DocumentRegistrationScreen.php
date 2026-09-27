<?php

declare(strict_types=1);

namespace App\Orchid\Screens;

use App\Http\Requests\StoreDocumentRequest;
use App\Models\Document;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
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
        return [];
    }

    /**
     * The name of the screen displayed in the header.
     */
    public function name(): ?string
    {
        return 'New Document';
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

    public function save(StoreDocumentRequest $request): RedirectResponse
    {
        $data = $request->validated('document');
        $uploadedAttachments = [];

        try {
            $document = DB::transaction(function () use ($data, $request, &$uploadedAttachments): Document {
                $document = Document::create([
                    'tracking_number' => 'PENDING-'.Str::upper(Str::random(12)),
                    'subject' => $data['title'],
                    'document_type' => $data['type'],
                    'external_reference' => $data['reference'] ?? null,
                    'description' => $data['description'] ?? null,
                    'origin' => $data['origin'],
                    'sender' => $data['sender'],
                    'current_office' => $data['destination'],
                    'status' => 'Awaiting receipt',
                    'received_at' => $data['received'],
                    'due_at' => $data['due'] ?? null,
                    'priority' => $data['priority'],
                    'remarks' => $data['remarks'] ?? null,
                    'registered_by' => $request->user()?->getAuthIdentifier(),
                ]);

                $document->update([
                    'tracking_number' => sprintf('DTS-%s-%06d', $document->received_at->format('Y'), $document->id),
                ]);

                foreach ($request->file('attachments', []) as $uploadedFile) {
                    $attachment = (new File($uploadedFile, 'local', 'documents'))->load();
                    $uploadedAttachments[] = $attachment;
                    $document->attachments()->attach($attachment->getKey());
                }

                $document->recordActivity('Document registered', $request->user(), [
                    'action' => 'register',
                    'office' => $document->current_office,
                    'status' => $document->status,
                    'attachment_count' => count($uploadedAttachments),
                ]);

                return $document;
            });
        } catch (Throwable $exception) {
            foreach ($uploadedAttachments as $attachment) {
                if ($attachment instanceof Attachment) {
                    $attachment->delete();
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
