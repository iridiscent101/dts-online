<?php

namespace App\Http\Controllers;

use App\Models\Document;
use App\Models\DocumentMovement;
use App\Models\Office;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class DocumentIntakeController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User && $user->canConfirmPaperIntake(), 403);

        $validated = $request->validate([
            'tracking' => ['required', 'string', 'max:32'],
        ]);
        $trackingNumber = Str::upper(trim($validated['tracking']));
        $document = Document::query()
            ->whereRaw('UPPER(tracking_number) = ?', [$trackingNumber])
            ->first();

        if ($document === null) {
            return response()->json(['message' => 'No document found for this tracking number.'], 404);
        }

        $receipt = $document->movements()
            ->with('actor')
            ->where('action', DocumentMovement::ACTION_RECEIVED)
            ->where('from_status', DocumentMovement::STATUS_AWAITING_PHYSICAL_DOCUMENT)
            ->where('to_office', Office::RECORDS_UNIT_NAME)
            ->first();
        $state = $document->status === DocumentMovement::STATUS_AWAITING_PHYSICAL_DOCUMENT
            ? 'awaiting'
            : ($receipt === null ? 'not_awaiting' : 'received');
        $routes = $state === 'awaiting'
            ? DB::table('document_route_steps')
                ->where('document_id', $document->id)
                ->orderBy('sequence')
                ->get(['office', 'remarks'])
                ->map(fn (object $route): array => ['office' => $route->office, 'remarks' => $route->remarks ?? ''])
                ->all()
            : [];
        $offices = $state === 'received'
            ? $document->movements()
                ->whereIn('action', [DocumentMovement::ACTION_FORWARDED, DocumentMovement::ACTION_CANCELLED])
                ->where('from_office', Office::RECORDS_UNIT_NAME)
                ->where('id', '>', $receipt->id)
                ->oldest('id')
                ->get(['action', 'to_office'])
                ->reduce(function (array $offices, DocumentMovement $movement): array {
                    if ($movement->action === DocumentMovement::ACTION_CANCELLED) {
                        unset($offices[$movement->to_office]);
                    } else {
                        $offices[$movement->to_office] = $movement->to_office;
                    }

                    return $offices;
                }, [])
            : [];
        $offices = array_values($offices);

        return response()->json([
            'state' => $state,
            'source_office' => Office::RECORDS_UNIT_NAME,
            'document' => [
                'tracking_number' => $document->tracking_number,
                'subject' => $document->subject,
                'document_type' => $document->document_type,
                'priority' => $document->priority,
                'sender' => $document->sender,
                'origin' => $document->origin,
                'delivery_method' => $document->delivery_method,
                'submitted_on' => $document->created_at->copy()->timezone('Asia/Manila')->format('M j, Y'),
                'status' => $document->status,
                'routes' => $routes,
                'client_remarks' => $document->movements()
                    ->where('action', DocumentMovement::ACTION_SENT)
                    ->where('to_office', Office::RECORDS_UNIT_NAME)
                    ->oldest('id')
                    ->value('remarks'),
                'confirm_url' => route('platform.documents.movements.store', $document),
                'view_url' => route('platform.documents.view', $document),
                'label_url' => route('platform.documents.label', $document),
            ],
            'receipt' => $state === 'received' ? [
                'received_on' => $receipt->created_at->copy()->timezone('Asia/Manila')->format('M j, Y \a\t g:i A'),
                'received_by' => $receipt->actor?->name ?? '',
                'offices' => $offices,
            ] : null,
        ]);
    }
}
