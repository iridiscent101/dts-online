<?php

namespace App\Http\Controllers;

use App\Models\Document;
use App\Models\DocumentPushSubscription;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * "Get updates on this phone" on the tracking and receipt pages: saves the phone's Firebase token for that tracking
 * number, so the client gets a push when the document is sent to offices and when it is released or completed
 * (DocumentNotificationDispatcher). Anyone with the tracking number can already see these updates on the tracking page,
 * so the push carries nothing more than that page shows.
 */
class DocumentPushSubscriptionController extends Controller
{
    public function store(Request $request, string $tracking): JsonResponse
    {
        $document = $this->document($tracking);
        $token = $this->token($request);

        $subscription = $document->pushSubscriptions()->where('token', $token)->first();
        if ($subscription === null && $document->pushSubscriptions()->count() >= DocumentPushSubscription::MAX_PER_DOCUMENT) {
            throw ValidationException::withMessages([
                'token' => 'Updates are already on for the most phones this document allows.',
            ]);
        }

        $document->pushSubscriptions()->updateOrCreate(['token' => $token], ['last_used_at' => now()]);

        return response()->json(['message' => "You will get updates on {$document->tracking_number} on this phone."]);
    }

    public function destroy(Request $request, string $tracking): JsonResponse
    {
        $document = $this->document($tracking);
        $document->pushSubscriptions()->where('token', $this->token($request))->delete();

        return response()->json(['message' => "Updates on {$document->tracking_number} are off for this phone."]);
    }

    private function document(string $tracking): Document
    {
        $trackingNumber = Str::upper(trim($tracking));
        abort_unless((bool) preg_match('/^(?:SDONV[1-9]{11}|DTS-[A-Z0-9-]+)$/', $trackingNumber), 404);

        return Document::query()->where('tracking_number', $trackingNumber)->firstOrFail();
    }

    private function token(Request $request): string
    {
        return $request->validate(['token' => ['required', 'string', 'min:20', 'max:512']])['token'];
    }
}
