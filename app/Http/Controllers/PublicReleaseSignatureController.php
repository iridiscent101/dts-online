<?php

namespace App\Http\Controllers;

use App\Models\Document;
use App\Models\DocumentMovement;
use App\Rules\RecipientSignature;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * The client signs for a released document on the tracking page when Records released it without a signature at the
 * counter. Only the latest Release can be signed, and only once: a saved signature is never replaced from here.
 */
class PublicReleaseSignatureController extends Controller
{
    public function __invoke(Request $request, string $tracking): RedirectResponse
    {
        $trackingNumber = Str::upper(trim($tracking));
        $back = redirect()->route('tracking.index', ['tracking' => $trackingNumber]);

        $validated = $request->validateWithBag('releaseSignature', [
            'recipient_signature' => ['required', 'string', 'max:140000', new RecipientSignature],
        ], [
            'recipient_signature.required' => 'Sign inside the box before saving.',
        ]);

        $document = Document::query()
            ->where('tracking_number', $trackingNumber)
            ->where('status', DocumentMovement::STATUS_RELEASED)
            ->first();
        $release = $document?->latestMovement;

        if (! $release || $release->action !== DocumentMovement::ACTION_RELEASED || filled($release->recipient_signature)) {
            return $back->withErrors(['recipient_signature' => 'This document has no release waiting for a signature.'], 'releaseSignature');
        }

        $release->forceFill(['recipient_signature' => $validated['recipient_signature']])->save();
        // A step of its own on both timelines; no staff member did it, so the activity has no causer.
        activity('documents')
            ->performedOn($document)
            ->withProperties([
                'office' => $release->from_office ?: $release->to_office,
                'signed_by' => $release->released_to,
                'movement_id' => $release->id,
            ])
            ->log('Release signed');

        return $back->with('release_signature_saved', true);
    }
}
