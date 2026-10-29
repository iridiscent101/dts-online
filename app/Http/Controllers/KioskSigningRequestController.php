<?php

namespace App\Http\Controllers;

use App\Models\Document;
use App\Models\DocumentMovement;
use App\Models\KioskSigningRequest;
use App\Models\User;
use App\Services\DocumentRoutingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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

        $signing = DB::transaction(function () use ($document, $user, $data, $routing): KioskSigningRequest {
            $currentDocument = Document::query()->lockForUpdate()->findOrFail($document->id);
            abort_unless($currentDocument->isVisibleTo($user), 404);
            $currentDocument->load('officeAssignments.movement');
            $state = $routing->workflowState($user, $currentDocument, $data['source_office']);
            abort_unless($state !== null
                && array_key_exists(DocumentMovement::ACTION_RELEASED, $routing->availableActionOptions($user, $currentDocument, $state)), 403, 'This document cannot be released from this office.');

            KioskSigningRequest::query()
                ->where('document_id', $currentDocument->id)
                ->whereIn('status', [KioskSigningRequest::STATUS_PENDING, KioskSigningRequest::STATUS_SIGNED])
                ->update(['status' => KioskSigningRequest::STATUS_CANCELLED]);

            $recipientName = trim((string) ($data['recipient_name'] ?? ''));

            return KioskSigningRequest::create([
                'document_id' => $currentDocument->id,
                'requested_by' => $user->id,
                'office' => $state['office'],
                'status' => KioskSigningRequest::STATUS_PENDING,
                'recipient_name' => $recipientName !== '' ? $recipientName : null,
                'expires_at' => now()->addMinutes(KioskSigningRequest::LIFETIME_MINUTES),
            ]);
        });

        return response()->json([
            'status' => $signing->displayStatus(),
            'status_url' => route('platform.kiosk-signing.show', $signing),
            'cancel_url' => route('platform.kiosk-signing.cancel', $signing),
            'expires_at' => $signing->expires_at->toIso8601String(),
        ], 201);
    }

    public function show(Request $request, KioskSigningRequest $signing): JsonResponse
    {
        $this->authorizeRequester($request, $signing);

        $signed = $signing->displayStatus() === KioskSigningRequest::STATUS_SIGNED;

        return response()->json([
            'status' => $signing->displayStatus(),
            'recipient_name' => $signed ? $signing->recipient_name : null,
            'recipient_signature' => $signed ? $signing->recipient_signature : null,
        ]);
    }

    public function cancel(Request $request, KioskSigningRequest $signing): JsonResponse
    {
        $this->authorizeRequester($request, $signing);

        KioskSigningRequest::query()
            ->whereKey($signing->getKey())
            ->where('status', KioskSigningRequest::STATUS_PENDING)
            ->update(['status' => KioskSigningRequest::STATUS_CANCELLED]);

        $signing->refresh();

        return response()->json(['status' => $signing->displayStatus()]);
    }

    private function authorizeRequester(Request $request, KioskSigningRequest $signing): void
    {
        $user = $request->user();
        abort_unless($user instanceof User
            && $user->id === $signing->requested_by
            && $signing->requesterCanHandle(), 404);
    }
}
