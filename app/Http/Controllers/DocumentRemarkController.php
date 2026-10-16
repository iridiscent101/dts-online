<?php

namespace App\Http\Controllers;

use App\Models\Document;
use App\Models\DocumentMovement;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Orchid\Support\Facades\Toast;

class DocumentRemarkController extends Controller
{
    public function __invoke(Request $request, Document $document): RedirectResponse|JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User && $document->isVisibleTo($user), 404);
        abort_unless($user->hasAccess('platform.index') && ! $user->isReadOnlySupervisor(), 403);

        $data = $request->validate([
            'office' => ['required', 'string', 'max:180'],
            'remark' => ['required', 'string', 'max:1000'],
        ]);
        $office = trim($data['office']);
        $remark = trim($data['remark']);
        // Same rule as routing (DocumentRoutingService::workflowStates): administrators act for any office on the document.
        abort_unless($user->isAdministrator() || $user->isAssignedToOffice($office), 403);
        abort_if($remark === '', 422, 'Enter a remark.');

        DB::transaction(function () use ($document, $user, $office, $remark): void {
            $currentDocument = Document::query()->lockForUpdate()->findOrFail($document->id);
            $assignment = $currentDocument->officeAssignments()->where('office', $office)->first();
            abort_unless($assignment !== null
                && ! $currentDocument->isClosedRecord()
                && in_array(DocumentMovement::ACTION_FORWARDED, DocumentMovement::availableActionsForStatus($assignment->status), true), 403);

            $currentDocument->recordActivity('Remark added', $user, [
                'office' => $office,
                'remark' => $remark,
            ]);
        });

        if ($request->expectsJson()) {
            return response()->json(['message' => 'Remark added.']);
        }

        Toast::success('Remark added.');

        return redirect()->route('platform.documents.view', $document);
    }
}
