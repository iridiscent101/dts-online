<?php

namespace App\Http\Controllers;

use App\Models\Document;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Orchid\Support\Facades\Toast;

class DocumentDeletionController extends Controller
{
    public function __invoke(Request $request, Document $document): RedirectResponse
    {
        $user = $request->user();

        abort_unless($user instanceof User, 403);
        abort_unless($document->isVisibleTo($user), 404);
        abort_unless($user->isAdministrator(), 403);

        $trackingNumber = $document->tracking_number;
        $this->removeDocuments(collect([$document]), $user);

        Toast::success(__('Document :tracking was removed.', ['tracking' => $trackingNumber]));

        return redirect()->route('platform.documents');
    }

    public function bulk(Request $request): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User && $user->isAdministrator(), 403);

        $validated = $request->validate([
            'selected' => ['required', 'array', 'min:1', 'max:100'],
            'selected.*' => ['required', 'integer', 'min:1', 'distinct'],
        ]);

        $documents = Document::query()
            ->whereKey($validated['selected'])
            ->get();

        abort_unless($documents->count() === count($validated['selected']), 404);

        foreach ($documents as $document) {
            abort_unless($document->isVisibleTo($user), 404);
        }

        $this->removeDocuments($documents, $user);

        Toast::success(__('Removed :count selected documents.', ['count' => $documents->count()]));

        return redirect()->route('platform.documents');
    }

    /**
     * @param  Collection<int, Document>  $documents
     */
    private function removeDocuments(Collection $documents, User $actor): void
    {
        DB::transaction(function () use ($documents, $actor): void {
            foreach ($documents as $document) {
                $document->recordActivity('Document removed', $actor, [
                    'action' => 'delete',
                    'tracking_number' => $document->tracking_number,
                ]);
                $document->delete();
            }
        });
    }
}
