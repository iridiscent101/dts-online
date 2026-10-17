<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreDocumentMovementRequest;
use App\Models\Document;
use App\Models\DocumentMovement;
use App\Notifications\DocumentNotificationDispatcher;
use App\Services\DocumentRoutingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Orchid\Support\Facades\Toast;

class DocumentMovementController extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(StoreDocumentMovementRequest $request, Document $document, DocumentNotificationDispatcher $notifications, DocumentRoutingService $routing): RedirectResponse|JsonResponse
    {
        $workflow = $request->validated('workflow');
        $user = $request->user();

        $transition = DB::transaction(function () use ($routing, $document, $user, $workflow, $notifications): array {
            $wasAwaitingPaper = $document->status === DocumentMovement::STATUS_AWAITING_PHYSICAL_DOCUMENT;
            $transition = $routing->route($document, $user, $workflow);

            $document->refresh();
            if ($wasAwaitingPaper && $transition['action'] === DocumentMovement::ACTION_RECEIVED) {
                $document->update([
                    'received_at' => today('Asia/Manila')->toDateString(),
                    'registered_by' => $user->getAuthIdentifier(),
                    'registered_office' => 'SDO-Records Unit',
                ]);
                $requestedRoutes = DB::table('document_route_steps')
                    ->where('document_id', $document->id)
                    ->orderBy('sequence')
                    ->get();
                $forwardRoutes = $requestedRoutes->where('office', '!=', 'SDO-Records Unit')->values();

                if ($forwardRoutes->isNotEmpty()) {
                    $dispatch = $routing->route($document, $user, [
                        'action' => DocumentMovement::ACTION_FORWARDED,
                        'source_office' => 'SDO-Records Unit',
                        'destinations' => $forwardRoutes->pluck('office')->all(),
                        'destination_remarks' => $forwardRoutes->pluck('remarks')->all(),
                    ]);
                    $document->refresh();
                    if ($requestedRoutes->contains('office', 'SDO-Records Unit')) {
                        $receipt = $document->movements()
                            ->where('action', DocumentMovement::ACTION_RECEIVED)
                            ->where('to_office', 'SDO-Records Unit')
                            ->latest('id')
                            ->firstOrFail();
                        $document->officeAssignments()->create([
                            'office' => 'SDO-Records Unit',
                            'status' => DocumentMovement::STATUS_RECEIVED,
                            'assigned_by' => $user->getAuthIdentifier(),
                            'movement_id' => $receipt->id,
                        ]);
                    }
                    $notifications->assigned($document, $dispatch['action'], $user, $dispatch['offices']);
                    $transition['message'] = 'Document received by Records Unit and sent to '.collect($dispatch['offices'])->join(', ', ' and ').'.';
                } else {
                    $transition['message'] = 'Document received by Records Unit.';
                }

                DB::table('document_route_steps')->where('document_id', $document->id)->delete();
            }
            if (in_array($transition['action'], [
                DocumentMovement::ACTION_SENT,
                DocumentMovement::ACTION_FORWARDED,
                DocumentMovement::ACTION_RETURNED,
                DocumentMovement::ACTION_RESUBMITTED,
                DocumentMovement::ACTION_RESTORED,
            ], true)) {
                $notifications->assigned($document, $transition['action'], $user, $transition['offices']);
            }

            return $transition;
        });

        $message = $transition['message'] ?? $routing->successMessage($document, $transition['action'], $transition['offices']);

        // Offices keep read access to documents they sent on (their Outgoing tab); that access alone should not
        // keep them on the document after sending it, so it is left out here.
        $canStillAct = $document->refresh()->isVisibleTo($user, includeSentOn: false);

        if ($request->expectsJson()) {
            return response()->json(['message' => $message, 'visible' => $canStillAct]);
        }

        Toast::success($message);

        if (! $canStillAct) {
            return redirect()->route('platform.main');
        }

        return redirect()->route('platform.documents.view', $document);
    }
}
