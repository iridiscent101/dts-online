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

        $rules = [
            'source_office' => ['required', 'string', 'max:180'],
            'remarks' => ['nullable', 'string', 'max:1000'],
            'destination_remarks' => ['nullable', 'array', 'max:5'],
            'destination_remarks.*' => ['nullable', 'string', 'max:1000'],
            // Why another office is being added; kept in the history like a route correction's reason, and
            // optional in the same way, so the history records it when it is given and nothing when it is not.
            'reason' => ['nullable', 'string', 'max:500'],
        ];
        if ($request->has('destinations')) {
            $rules['destinations'] = ['required', 'array', 'min:1', 'max:5'];
            $rules['destinations.*'] = ['required', 'string', 'distinct', Rule::in(Document::officeOptions())];
        } else {
            $rules['destination_office'] = ['required', 'string', Rule::in(Document::officeOptions())];
        }
        $data = $request->validate($rules);
        $sourceOffice = trim($data['source_office']);
        $destinations = array_values($data['destinations'] ?? [$data['destination_office']]);
        $remarks = filled($data['remarks'] ?? null) ? trim($data['remarks']) : null;
        $reason = trim((string) ($data['reason'] ?? ''));
        $reason = $reason !== '' ? $reason : null;

        DB::transaction(function () use ($routing, $document, $user, $sourceOffice, $destinations, $remarks, $data, $reason, $notifications): void {
            foreach ($destinations as $index => $destinationOffice) {
                $destinationRemarks = $data['destination_remarks'][$index] ?? $remarks;
                $routing->addAdditionalRoute($document, $user, $sourceOffice, $destinationOffice, filled($destinationRemarks) ? trim($destinationRemarks) : null, $reason);
            }
            $notifications->assigned($document, DocumentMovement::ACTION_FORWARDED, $user, $destinations);
        });

        $message = count($destinations) === 1
            ? "{$document->tracking_number} was also forwarded to {$destinations[0]}."
            : "{$document->tracking_number} was also forwarded to ".count($destinations).' destinations.';
        if ($request->expectsJson()) {
            return response()->json(['message' => $message]);
        }

        Toast::success($message);

        return redirect()->route('platform.documents.view', $document);
    }
}
