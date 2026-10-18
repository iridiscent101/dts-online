<?php

namespace App\Http\Controllers;

use App\Models\KioskSigningRequest;
use App\Models\User;
use App\Rules\RecipientSignature;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The signing kiosk: a laptop outside the Records Unit, signed in with an account that has the "Signing kiosk"
 * permission. It waits for a clerk's request, then the recipient types their name and signs. The kiosk sees only the
 * tracking number and type of the one document being signed for.
 */
class KioskController extends Controller
{
    public function show(Request $request): View
    {
        $this->authorizeKiosk($request);

        return view('kiosk');
    }

    public function current(Request $request): JsonResponse
    {
        $this->authorizeKiosk($request);

        $signing = KioskSigningRequest::query()
            ->waiting()
            ->whereHas('document')
            ->with(['document', 'requester.offices'])
            ->lazyById(100)
            ->first(fn (KioskSigningRequest $candidate): bool => $candidate->isWaiting() && $candidate->requesterCanHandle());

        return response()->json(['request' => $signing ? [
            'id' => $signing->id,
            'tracking_number' => $signing->document->tracking_number,
            'document_type' => $signing->document->document_type,
            'recipient_name' => $signing->recipient_name,
            'sign_url' => route('kiosk.sign', $signing),
        ] : null]);
    }

    public function sign(Request $request, KioskSigningRequest $signing): JsonResponse
    {
        $this->authorizeKiosk($request);

        $name = $request->input('recipient_name');
        $request->merge(['recipient_name' => is_string($name) && trim($name) !== '' ? trim($name) : null]);
        // The name is optional; the signature is required.
        $data = $request->validate([
            'recipient_name' => ['nullable', 'string', 'max:255'],
            'recipient_signature' => ['required', 'string', 'max:140000', new RecipientSignature],
        ], [
            'recipient_signature.required' => 'Sign inside the box first.',
        ]);

        $signed = DB::transaction(function () use ($signing, $data): bool {
            $current = KioskSigningRequest::query()
                ->with(['document', 'requester.offices'])
                ->lockForUpdate()
                ->find($signing->id);
            if (! $current?->isWaiting() || ! $current->requesterCanHandle()) {
                return false;
            }

            $current->update([
                'status' => KioskSigningRequest::STATUS_SIGNED,
                'recipient_name' => $data['recipient_name'],
                'recipient_signature' => $data['recipient_signature'],
                'signed_at' => now(),
            ]);

            return true;
        });

        abort_unless($signed, 409, 'This signing request was cancelled or has expired. Please ask the Records Unit staff.');

        return response()->json(['message' => 'Thank you. Your signature was sent to the Records Unit.']);
    }

    private function authorizeKiosk(Request $request): void
    {
        $user = $request->user();
        abort_unless($user instanceof User && $user->hasAccess('platform.kiosk'), 403);
    }
}
