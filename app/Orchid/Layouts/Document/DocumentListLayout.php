<?php

namespace App\Orchid\Layouts\Document;

use App\Models\Document;
use App\Orchid\Layouts\Concerns\HasTableSelectionColumn;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Contracts\Pagination\Paginator;
use Illuminate\Support\Collection;
use Orchid\Screen\Actions\Button;
use Orchid\Screen\Layouts\Table;
use Orchid\Screen\TD;

class DocumentListLayout extends Table
{
    use HasTableSelectionColumn;

    /**
     * Data source.
     *
     * The name of the key to fetch it from the query.
     * The results of which will be elements of the table.
     *
     * @var string
     */
    protected $target = 'documents';

    /**
     * Get the table cells to be displayed.
     *
     * @return TD[]
     */
    protected function columns(): iterable
    {
        return [
            $this->selectColumn('Select document'),

            $this->numberColumn(),

            TD::make('tracking_number', 'Tracking no. / Subject')
                ->sort()
                ->cantHide()
                ->render(fn (Document $document) => view('orchid.cells.document-subject', compact('document'))),

            TD::make('document_type', 'Document type')
                ->sort()
                ->defaultHidden(),

            TD::make('sender', 'Sender / Origin')
                ->sort()
                ->defaultHidden(),

            TD::make('current_office', 'Assigned offices')
                ->sort()
                ->render(fn (Document $document) => view('orchid.cells.document-office-statuses', compact('document'))),

            TD::make('received_at', 'Date received')
                ->sort()
                ->render(fn (Document $document): string => $document->received_at?->format('M d, Y') ?? 'Not received'),

            TD::make('created_at', 'Date registered')
                ->sort()
                ->render(fn (Document $document): string => $document->created_at->timezone('Asia/Manila')->format('M d, Y')),

            // Not sortable on its own: "Date registered" already sorts by the full registration time.
            TD::make('registered_time', 'Time registered')
                ->render(fn (Document $document): string => $document->created_at->timezone('Asia/Manila')->format('g:i A')),

            TD::make(__('Actions'))
                ->align(TD::ALIGN_CENTER)
                ->width('112px')
                ->cantHide()
                ->render(fn (Document $document) => view('orchid.cells.document-row-actions', [
                    'document' => $document,
                    'canEdit' => ($user = request()->user()) !== null && $document->canBeEditedBy($user),
                    'canDelete' => $user?->isAdministrator() ?? false,
                    'deleteAction' => Button::make(__('Delete'))
                        ->class('btn dts-row-action dts-row-action-delete')
                        ->icon('bs.trash3')
                        ->confirm(__('Remove this document from active records? Its history will be retained.'))
                        ->route('platform.documents.destroy', $document),
                    'editUrl' => route('platform.documents.edit', $document),
                    'workspaceUrl' => route('platform.documents.workspace', $document),
                ])),
        ];
    }

    protected function bordered(): bool
    {
        return true;
    }

    protected function hoverable(): bool
    {
        return true;
    }

    protected function iconNotFound(): string
    {
        return match ($this->section()) {
            'incoming' => 'bs.inbox',
            'outgoing' => 'bs.send',
            'archived' => 'bs.check2-circle',
            default => 'bs.folder2-open',
        };
    }

    protected function textNotFound(): string
    {
        if ($this->hasActiveFilters()) {
            return 'No documents match the current filters';
        }

        return match ($this->section()) {
            'incoming' => 'No incoming documents',
            'outgoing' => 'No outgoing documents',
            'archived' => 'No archived documents',
            default => 'No documents registered yet',
        };
    }

    protected function subNotFound(): string
    {
        if ($this->hasActiveFilters()) {
            return view('orchid.partials.document-empty-filters', [
                'section' => $this->section(),
            ])->render();
        }

        return match ($this->section()) {
            'incoming' => 'There are no documents awaiting receipt.',
            'outgoing' => 'There are no forwarded documents.',
            'archived' => 'Released, completed and archived records appear here.',
            default => 'Your document registry is currently empty.',
        };
    }

    protected function hasHeader(Collection $columns, Collection|Paginator|CursorPaginator $row): bool
    {
        return true;
    }

    private function section(): string
    {
        return request()->route()->defaults['section'] ?? 'documents';
    }

    private function hasActiveFilters(): bool
    {
        return request()->hasAny(['search', 'office', 'type', 'status', 'from', 'to']);
    }
}
