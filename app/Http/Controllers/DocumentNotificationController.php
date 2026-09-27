<?php

namespace App\Http\Controllers;

use App\Models\Document;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class DocumentNotificationController extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(Request $request, string $notification): RedirectResponse
    {
        $databaseNotification = $request->user()
            ->notifications()
            ->whereKey($notification)
            ->firstOrFail();

        $databaseNotification->markAsRead();

        $documentId = $databaseNotification->data['document_id'] ?? null;
        $document = is_numeric($documentId)
            ? Document::query()->visibleTo($request->user())->find((int) $documentId)
            : null;

        return $document instanceof Document
            ? redirect()->route('platform.documents.view', $document)
            : redirect()->route('platform.main');
    }
}
