<?php

namespace App\Http\Controllers;

use App\Models\Document;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * A document's address (links, emails, notifications, redirects after saving) opens the registry with that document's
 * workspace dialog on top. There is no separate full document page: it used to be built only to sit behind the dialog.
 */
class DocumentOpenController extends Controller
{
    public function __invoke(Request $request, Document $document): RedirectResponse
    {
        $user = $request->user();

        abort_unless($user instanceof User && $user->hasAccess('platform.index'), 403);
        abort_unless($document->isVisibleTo($user), 404);

        // Saving in the dialog redirects here with a message or errors; keep them for the registry page.
        $request->session()->reflash();

        $sourceOffice = $request->query('source_office');

        return redirect()->route('platform.documents', array_filter([
            'open_document' => $document->id,
            'source_office' => is_string($sourceOffice) && $sourceOffice !== '' ? $sourceOffice : null,
        ]));
    }
}
