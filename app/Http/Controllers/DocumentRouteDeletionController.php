<?php

namespace App\Http\Controllers;

use App\Models\Document;
use App\Models\User;
use App\Services\DocumentRoutingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Orchid\Support\Facades\Toast;

/**
 * "Delete this route" in the Correct route dialog: removes a route sent by mistake, before that office receives it
 * (DocumentRoutingService::deletePendingRoute). The other routes on the document stay as they are.
 */
class DocumentRouteDeletionController extends Controller
{
    public function __invoke(Request $request, Document $document, DocumentRoutingService $routing): RedirectResponse|JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User && $document->isVisibleTo($user), 404);

        $data = $request->validate([
            'source_office' => ['required', 'string', 'max:180'],
            'destination_office' => ['required', 'string', 'max:180'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $destinationOffice = trim($data['destination_office']);
        $reason = trim((string) ($data['reason'] ?? ''));
        $routing->deletePendingRoute($document, $user, trim($data['source_office']), $destinationOffice, $reason !== '' ? $reason : null);

        $message = "The destination {$destinationOffice} was removed.";
        if ($request->expectsJson()) {
            return response()->json(['message' => $message]);
        }

        Toast::success($message);

        return redirect()->route('platform.documents.view', $document);
    }
}
