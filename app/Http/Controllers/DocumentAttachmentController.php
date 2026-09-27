<?php

namespace App\Http\Controllers;

use App\Models\Document;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Orchid\Attachment\Models\Attachment;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Vish4395\LaravelFileViewer\LaravelFileViewer;

class DocumentAttachmentController extends Controller
{
    public function preview(Request $request, Document $document, int $attachment): View
    {
        $attachment = $this->authorizedAttachment($request, $document, $attachment);
        $document->recordActivity('Attachment previewed', $request->user(), [
            'action' => 'preview',
            'attachment_id' => $attachment->getKey(),
            'file_name' => $attachment->original_name,
        ]);

        return LaravelFileViewer::show(
            fileName: $attachment->original_name,
            filePath: $attachment->physicalPath(),
            fileUrl: route('platform.documents.attachments.content', [$document, $attachment]),
            disk: $attachment->disk,
            fileData: [
                ['label' => 'Tracking number', 'value' => $document->tracking_number],
                ['label' => 'Office', 'value' => $document->current_office],
            ],
        );
    }

    public function content(Request $request, Document $document, int $attachment): StreamedResponse
    {
        $attachment = $this->authorizedAttachment($request, $document, $attachment);

        return Storage::disk($attachment->disk)->response(
            $attachment->physicalPath(),
            $attachment->original_name,
            [
                'Content-Type' => $attachment->mime,
                'X-Content-Type-Options' => 'nosniff',
            ],
        );
    }

    public function download(Request $request, Document $document, int $attachment): StreamedResponse
    {
        $attachment = $this->authorizedAttachment($request, $document, $attachment);
        $document->recordActivity('Attachment downloaded', $request->user(), [
            'action' => 'download',
            'attachment_id' => $attachment->getKey(),
            'file_name' => $attachment->original_name,
        ]);

        return $attachment->download(['X-Content-Type-Options' => 'nosniff']);
    }

    private function authorizedAttachment(Request $request, Document $document, int $attachment): Attachment
    {
        abort_unless($request->user()?->hasAccess('platform.index'), 403);
        abort_unless($document->isVisibleTo($request->user()), 404);

        return $document->attachments()->whereKey($attachment)->firstOrFail();
    }
}
