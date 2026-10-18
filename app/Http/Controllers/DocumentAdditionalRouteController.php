<?php

namespace App\Http\Controllers;

use App\Models\Document;
use App\Models\DocumentMovement;
use App\Models\User;
use App\Notifications\DocumentNotificationDispatcher;
use App\Services\DocumentRoutingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Orchid\Support\Facades\Toast;

class DocumentAdditionalRouteController extends Controller
{
    public function __invoke(Request $request, Document $document, DocumentRoutingService $routing, DocumentNotificationDispatcher $notifications): RedirectResponse|JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User && $document->isVisibleTo($user), 404);

        $data = $request->validate([
            'source_office' => ['required', 'string', 'max:180'],
            'destination_office' => ['required', 'string', Rule::in(Document::officeOptions())],
            'remarks' => ['nullable', 'string', 'max:1000'],
            // Why another office is being added; kept in the history like a route correction's reason.
            'reason' => ['required', 'string', 'max:500'],
        ], [
            'reason.required' => 'Say why you are adding this office.',
        ]);
        $sourceOffice = trim($data['source_office']);
        $destinationOffice = $data['destination_office'];
        $remarks = filled($data['remarks'] ?? null) ? trim($data['remarks']) : null;
        $reason = trim($data['reason']);

        DB::transaction(function () use ($routing, $document, $user, $sourceOffice, $destinationOffice, $remarks, $reason, $notifications): void {
            $routing->addAdditionalRoute($document, $user, $sourceOffice, $destinationOffice, $remarks, $reason);
            $notifications->assigned($document, DocumentMovement::ACTION_FORWARDED, $user, [$destinationOffice]);
        });

        $message = "{$document->tracking_number} was also forwarded to {$destinationOffice}.";
        if ($request->expectsJson()) {
            return response()->json(['message' => $message]);
        }

        Toast::success($message);

        return redirect()->route('platform.documents.view', $document);
    }
}
