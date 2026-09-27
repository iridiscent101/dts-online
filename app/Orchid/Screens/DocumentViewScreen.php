<?php

namespace App\Orchid\Screens;

use App\Models\Document;
use Orchid\Screen\Action;
use Orchid\Screen\Screen;
use Orchid\Support\Facades\Layout;

class DocumentViewScreen extends Screen
{
    private ?Document $document = null;

    /**
     * Fetch data to be displayed on the screen.
     *
     * @return array
     */
    public function query(Document $document): iterable
    {
        $user = request()->user();
        abort_unless($user !== null && $document->isVisibleTo($user), 404);

        $document->recordActivity('Document viewed', $user, [
            'action' => 'view',
            'office' => $user->office,
        ]);

        $this->document = $document->load(['attachments', 'registeredBy', 'movements.actor']);
        $this->document->setRelation(
            'activities',
            $document->activities()->with('causer')->limit(50)->get(),
        );

        return [
            'document' => $this->document,
            'canManageWorkflow' => $user?->isAdministrator() || $user?->office === $document->current_office,
        ];
    }

    /**
     * The name of the screen displayed in the header.
     */
    public function name(): ?string
    {
        return $this->document?->tracking_number ?? 'Document details';
    }

    /**
     * The screen's action buttons.
     *
     * @return Action[]
     */
    public function commandBar(): iterable
    {
        return [];
    }

    /**
     * The screen's layout elements.
     *
     * @return \Orchid\Screen\Layout[]|string[]
     */
    public function layout(): iterable
    {
        return [Layout::view('orchid.document-view')];
    }

    public function permission(): ?iterable
    {
        return ['platform.index'];
    }
}
