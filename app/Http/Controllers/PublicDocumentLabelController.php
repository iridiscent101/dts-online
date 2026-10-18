<?php

namespace App\Http\Controllers;

use App\Models\Document;
use App\Services\DocumentTrackingLabel;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Str;

/**
 * The 60 x 30 mm tracking label for a tracking number (Fast Track "Print label"), the same label staff print. Like the
 * staff label it never carries the recipient's signature.
 */
class PublicDocumentLabelController extends Controller
{
    public function __invoke(string $tracking, DocumentTrackingLabel $trackingLabel): View
    {
        $trackingNumber = Str::upper(trim($tracking));
        abort_unless((bool) preg_match('/^(?:SDONV[1-9]{11}|DTS-[A-Z0-9-]+)$/', $trackingNumber), 404);

        $document = Document::query()
            ->with(['latestMovement.actor', 'recordsReceipt.actor', 'registeredBy'])
            ->where('tracking_number', $trackingNumber)
            ->firstOrFail();

        return view('orchid.document-label', [
            'labels' => collect([[
                'document' => $document,
                'qrCodeDataUri' => $trackingLabel->dataUri($document),
                'labelDetails' => $trackingLabel->details($document),
            ]]),
            'backUrl' => route('tracking.index', ['tracking' => $document->tracking_number]),
            'backLabel' => 'Back to tracking',
        ]);
    }
}
