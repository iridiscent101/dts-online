<?php

namespace App\Http\Controllers;

use App\Models\Document;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Orchid\Support\Facades\Toast;

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

        if (($databaseNotification->data['route'] ?? null) === 'platform.systems.health'
            && $request->user()->hasAccess('platform.systems.health')) {
            return redirect()->route('platform.systems.health');
        }

        $documentId = $databaseNotification->data['document_id'] ?? null;
        $document = is_numeric($documentId)
            ? Document::query()->visibleTo($request->user())->find((int) $documentId)
            : null;

        return $document instanceof Document
            ? redirect()->route('platform.documents.view', $document)
            : redirect()->route('platform.main');
    }

    public function markAllRead(Request $request): RedirectResponse
    {
        $request->user()->unreadNotifications()->update(['read_at' => now()]);
        Toast::success('All notifications were marked as read.');

        return back();
    }
}
