<?php

namespace App\Http\Controllers;

use App\Models\Document;
use App\Models\User;
use App\Services\DocumentTrackingLabel;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

class DocumentLabelController extends Controller
{
    public function __invoke(Request $request, Document $document, DocumentTrackingLabel $trackingLabel): View
    {
        $user = $request->user();

        abort_unless($user instanceof User && $user->hasAccess('platform.index'), 403);
        abort_unless($document->isVisibleTo($user), 404);

        $document->loadMissing(['latestMovement.actor', 'registeredBy']);

        return view('orchid.document-label', [
            'labels' => collect([[
                'document' => $document,
                'qrCodeDataUri' => $trackingLabel->dataUri($document),
                'labelDetails' => $trackingLabel->details($document),
            ]]),
            'backUrl' => route('platform.documents.view', $document),
        ]);
    }
}
