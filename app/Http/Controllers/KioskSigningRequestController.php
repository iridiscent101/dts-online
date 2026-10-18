<?php

namespace App\Http\Controllers;

use App\Models\Document;
use App\Models\DocumentMovement;
use App\Models\KioskSigningRequest;
use App\Models\User;
use App\Services\DocumentRoutingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The Records clerk's side of kiosk signing: send a Release to the kiosk laptop outside, follow it until the recipient
 * signs, or cancel it. Only someone who may release the document from that office can send it, and only the clerk who
 * sent a request can read its signature. The Release itself is still saved through the normal movement form.
 */
class KioskSigningRequestController extends Controller
{
    public function store(Request $request, Document $document, DocumentRoutingService $routing): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User && $document->isVisibleTo($user), 404);
        abort_unless($user->hasAccess('platform.index') && ! $user->isReadOnlySupervisor(), 403);

        $data = $request->validate([
            'source_office' => ['required', 'string', 'max:180'],
            'recipient_name' => ['nullable', 'string', 'max:255'],
        ]);

        $document->loadMissing('officeAssignments.movement');
        $state = $routing->workflowState($user, $document, $data['source_office']);
        abort_unless($state !== null
            && array_key_exists(DocumentMovement::ACTION_RELEASED, $routing->availableActionOptions($user, $document, $state)), 403, 'This document cannot be released from this office.');

        // A new request replaces any still waiting for this document, so the kiosk never shows it twice.
        KioskSigningRequest::query()
            ->where('document_id', $document->id)
            ->where('status', KioskSigningRequest::STATUS_PENDING)
            ->update(['status' => KioskSigningRequest::STATUS_CANCELLED]);

        $recipientName = trim((string) ($data['recipient_name'] ?? ''));
        $signing = KioskSigningRequest::create([
            'document_id' => $document->id,
            'requested_by' => $user->id,
            'office' => $state['office'],
            'status' => KioskSigningRequest::STATUS_PENDING,
            'recipient_name' => $recipientName !== '' ? $recipientName : null,
            'expires_at' => now()->addMinutes(KioskSigningRequest::LIFETIME_MINUTES),
        ]);

        return response()->json([
            'status' => $signing->displayStatus(),
            'status_url' => route('platform.kiosk-signing.show', $signing),
            'cancel_url' => route('platform.kiosk-signing.cancel', $signing),
            'expires_at' => $signing->expires_at->toIso8601String(),
        ], 201);
    }

    public function show(Request $request, KioskSigningRequest $signing): JsonResponse
    {
        abort_unless($request->user()?->id === $signing->requested_by, 404);

        $signed = $signing->displayStatus() === KioskSigningRequest::STATUS_SIGNED;

        return response()->json([
            'status' => $signing->displayStatus(),
            'recipient_name' => $signed ? $signing->recipient_name : null,
            'recipient_signature' => $signed ? $signing->recipient_signature : null,
        ]);
    }

    public function cancel(Request $request, KioskSigningRequest $signing): JsonResponse
    {
        abort_unless($request->user()?->id === $signing->requested_by, 404);

        if ($signing->status === KioskSigningRequest::STATUS_PENDING) {
            $signing->update(['status' => KioskSigningRequest::STATUS_CANCELLED]);
        }

        return response()->json(['status' => $signing->displayStatus()]);
    }
}
