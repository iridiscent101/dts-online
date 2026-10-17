<?php

namespace App\Http\Controllers;

use App\Models\Document;
use App\Models\User;
use App\Notifications\DocumentNotificationDispatcher;
use App\Services\DocumentRoutingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Orchid\Support\Facades\Toast;

class DocumentRouteCorrectionController extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(Request $request, Document $document, DocumentRoutingService $routing, DocumentNotificationDispatcher $notifications): RedirectResponse|JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User && $document->isVisibleTo($user), 404);

        $data = $request->validate([
            'source_office' => ['required', 'string', 'max:180'],
            'destination_office' => ['required', 'string', 'max:180'],
            'replacement_office' => ['required', 'string', Rule::in(Document::officeOptions())],
            'correction_reason' => ['required', 'string', 'max:500'],
        ]);

        $sourceOffice = trim($data['source_office']);
        $replacementOffice = $data['replacement_office'];
        DB::transaction(function () use ($routing, $document, $user, $sourceOffice, $data, $replacementOffice, $notifications): void {
            $action = $routing->correctPendingRoute($document, $user, $sourceOffice, trim($data['destination_office']), $replacementOffice, trim($data['correction_reason']));
            $notifications->assigned($document, $action, $user, [$replacementOffice]);
        });

        $message = "Route corrected to {$replacementOffice}.";
        if ($request->expectsJson()) {
            return response()->json(['message' => $message]);
        }

        Toast::success($message);

        return redirect()->route('platform.documents.view', $document);
    }
}
