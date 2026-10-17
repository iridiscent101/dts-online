<?php

namespace App\Http\Controllers;

use App\Models\Document;
use App\Models\DocumentMovement;
use App\Services\DocumentTrackingLabel;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Str;

/**
 * Printable receipt for a tracking number (Fast Track "Print receipt"): the tracking number, its QR code and what was
 * sent, on one page the client can print or save as PDF. It shows no more than the public tracking page does, so a
 * client submission's title and sender stay private.
 */
class DocumentReceiptController extends Controller
{
    public function __invoke(string $tracking, DocumentTrackingLabel $trackingLabel): View
    {
        $trackingNumber = Str::upper(trim($tracking));
        abort_unless((bool) preg_match('/^(?:SDONV[1-9]{11}|DTS-[A-Z0-9-]+)$/', $trackingNumber), 404);

        $document = Document::query()
            ->select(['id', 'tracking_number', 'subject', 'document_type', 'delivery_method', 'client_submission_key', 'status', 'created_at'])
            ->where('tracking_number', $trackingNumber)
            ->firstOrFail();

        return view('document-receipt', [
            'document' => $document,
            'qrCodeDataUri' => $trackingLabel->dataUri($document),
            'trackingUrl' => route('tracking.index', ['tracking' => $document->tracking_number]),
            'awaitsDocuments' => $document->status === DocumentMovement::STATUS_AWAITING_PHYSICAL_DOCUMENT,
            'contact' => config('dts.contact'),
        ]);
    }
}
