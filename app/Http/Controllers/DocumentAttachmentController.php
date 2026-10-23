<?php

namespace App\Http\Controllers;

use App\Http\Requests\DeleteDocumentAttachmentRequest;
use App\Http\Requests\ReplaceDocumentAttachmentRequest;
use App\Http\Requests\StoreDocumentAttachmentRequest;
use App\Models\Document;
use App\Notifications\DocumentNotificationDispatcher;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Orchid\Attachment\File;
use Orchid\Attachment\Models\Attachment;
use Orchid\Support\Facades\Toast;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;
use Vish4395\LaravelFileViewer\LaravelFileViewer;

class DocumentAttachmentController extends Controller
{
    private const MAX_ATTACHMENTS = 5;

    public function preview(Request $request, Document $document, int $attachment): View
    {
        $attachment = $this->authorizedAttachment($request, $document, $attachment);

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
        $document->recordDownload(
            $request->user(),
            $attachment->getKey(),
            $attachment->original_name,
        );

        return $attachment->download(['X-Content-Type-Options' => 'nosniff']);
    }

    public function store(StoreDocumentAttachmentRequest $request, Document $document, DocumentNotificationDispatcher $notifications): JsonResponse|RedirectResponse
    {
        $uploadedAttachments = [];

        try {
            DB::transaction(function () use ($request, $document, $notifications, &$uploadedAttachments): void {
                $lockedDocument = Document::query()->lockForUpdate()->findOrFail($document->getKey());
                $files = $request->file('attachments', []);

                if ($lockedDocument->attachments()->count() + count($files) > self::MAX_ATTACHMENTS) {
                    throw ValidationException::withMessages([
                        'attachments' => 'A document may have no more than five attachments.',
                    ]);
                }

                foreach ($files as $uploadedFile) {
                    $attachment = $this->storeUploadedFile($uploadedFile);
                    $uploadedAttachments[] = $attachment;
                    $lockedDocument->attachments()->attach($attachment->getKey());
                }

                $lockedDocument->recordActivity('Attachments added', $request->user(), [
                    'action' => 'attachment_add',
                    'attachment_ids' => array_map(fn (Attachment $attachment): int => $attachment->getKey(), $uploadedAttachments),
                    'file_names' => array_map(fn (Attachment $attachment): string => $attachment->original_name, $uploadedAttachments),
                ]);
                $notifications->changed($lockedDocument, 'attachments_added', $request->user());
            });
        } catch (ValidationException $exception) {
            $this->deleteAttachments($uploadedAttachments);

            throw $exception;
        } catch (Throwable $exception) {
            $this->deleteAttachments($uploadedAttachments);

            return $this->failureResponse($request, $exception, 'The files could not be uploaded. Please try again.');
        }

        return $this->successResponse($request, $document, 'Attachments uploaded.');
    }

    public function replace(ReplaceDocumentAttachmentRequest $request, Document $document, int $attachment, DocumentNotificationDispatcher $notifications): JsonResponse|RedirectResponse
    {
        $existingAttachment = $this->documentAttachment($document, $attachment);
        $replacement = null;

        try {
            $replacement = $this->storeUploadedFile($request->file('replacement'));

            DB::transaction(function () use ($request, $document, $existingAttachment, $replacement, $notifications): void {
                $lockedDocument = Document::query()->lockForUpdate()->findOrFail($document->getKey());
                abort_unless($lockedDocument->attachments()->whereKey($existingAttachment->getKey())->exists(), 404);

                $lockedDocument->attachments()->attach($replacement->getKey());
                $lockedDocument->attachments()->detach($existingAttachment->getKey());
                $lockedDocument->recordActivity('Attachment replaced', $request->user(), [
                    'action' => 'attachment_replace',
                    'attachment_id' => $replacement->getKey(),
                    'file_name' => $replacement->original_name,
                    'replaced_attachment_id' => $existingAttachment->getKey(),
                    'replaced_file_name' => $existingAttachment->original_name,
                ]);
                $notifications->changed($lockedDocument, 'attachment_replaced', $request->user());
            });
        } catch (Throwable $exception) {
            if ($replacement instanceof Attachment) {
                $this->deleteAttachments([$replacement]);
            }

            return $this->failureResponse($request, $exception, 'The attachment could not be replaced. Please try again.');
        }

        $this->deleteAttachments([$existingAttachment]);

        return $this->successResponse($request, $document, 'Attachment replaced.');
    }

    public function destroy(DeleteDocumentAttachmentRequest $request, Document $document, int $attachment, DocumentNotificationDispatcher $notifications): JsonResponse|RedirectResponse
    {
        $existingAttachment = $this->documentAttachment($document, $attachment);

        try {
            DB::transaction(function () use ($request, $document, $existingAttachment, $notifications): void {
                $lockedDocument = Document::query()->lockForUpdate()->findOrFail($document->getKey());

                if ($lockedDocument->attachments()->count() <= 1) {
                    throw ValidationException::withMessages([
                        'attachment' => 'The final attachment cannot be removed. Replace it instead.',
                    ]);
                }

                abort_unless($lockedDocument->attachments()->whereKey($existingAttachment->getKey())->exists(), 404);
                $lockedDocument->attachments()->detach($existingAttachment->getKey());
                $lockedDocument->recordActivity('Attachment removed', $request->user(), [
                    'action' => 'attachment_delete',
                    'attachment_id' => $existingAttachment->getKey(),
                    'file_name' => $existingAttachment->original_name,
                ]);
                $notifications->changed($lockedDocument, 'attachment_removed', $request->user());
            });
        } catch (ValidationException|HttpExceptionInterface $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            return $this->failureResponse($request, $exception, 'The attachment could not be removed. Please try again.');
        }

        $this->deleteAttachments([$existingAttachment]);

        return $this->successResponse($request, $document, 'Attachment removed.');
    }

    private function authorizedAttachment(Request $request, Document $document, int $attachment): Attachment
    {
        abort_unless($request->user()?->hasAccess('platform.index'), 403);
        abort_unless($document->isVisibleTo($request->user()), 404);

        return $this->documentAttachment($document, $attachment);
    }

    private function documentAttachment(Document $document, int $attachment): Attachment
    {
        return $document->attachments()->whereKey($attachment)->firstOrFail();
    }

    private function storeUploadedFile(UploadedFile $uploadedFile): Attachment
    {
        /** @var Attachment $attachment */
        $attachment = (new File($uploadedFile, 'local', 'documents'))->load();

        return $attachment;
    }

    /**
     * @param  array<int, Attachment>  $attachments
     */
    private function deleteAttachments(array $attachments): void
    {
        foreach ($attachments as $attachment) {
            try {
                $attachment->delete();
            } catch (Throwable $exception) {
                report($exception);
            }
        }
    }

    private function successResponse(Request $request, Document $document, string $message): JsonResponse|RedirectResponse
    {
        $document->load('attachments');

        if ($request->expectsJson()) {
            return response()->json([
                'message' => $message,
                'html' => view('orchid.partials.document-attachments', [
                    'document' => $document,
                    // An office adding a file after finishing (short grace period) gets no Replace or Delete buttons.
                    'canManageAttachments' => $document->canHaveAttachmentsManagedBy($request->user()),
                ])->render(),
            ]);
        }

        Toast::success($message);

        return redirect()->route('platform.documents.view', $document);
    }

    private function failureResponse(Request $request, Throwable $exception, string $message): JsonResponse|RedirectResponse
    {
        report($exception);

        if ($request->expectsJson()) {
            return response()->json(['message' => $message], 500);
        }

        Toast::error($message);

        return back();
    }
}
