<?php

namespace App\Http\Controllers;

use App\Models\Document;
use App\Models\User;
use App\Services\DocumentTrackingLabel;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

class DocumentLabelsController extends Controller
{
    public function __invoke(Request $request, DocumentTrackingLabel $trackingLabel): View
    {
        $user = $request->user();

        abort_unless($user instanceof User && $user->hasAccess('platform.index'), 403);

        $validated = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:100'],
            'ids.*' => ['required', 'integer', 'min:1', 'distinct'],
        ]);

        $documents = Document::query()
            ->visibleTo($user)
            ->whereKey($validated['ids'])
            ->get()
            ->keyBy('id');

        abort_unless($documents->count() === count($validated['ids']), 404);

        $labels = collect($validated['ids'])->map(function (int|string $id) use ($documents, $trackingLabel): array {
            $document = $documents->get((int) $id);

            return [
                'document' => $document,
                'qrCodeDataUri' => $trackingLabel->dataUri($document),
            ];
        });

        return view('orchid.document-label', [
            'labels' => $labels,
            'backUrl' => route('platform.documents'),
        ]);
    }
}
