<?php

namespace App\Orchid\Layouts\Document;

use App\Models\Document;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Contracts\Pagination\Paginator;
use Illuminate\Support\Collection;
use Orchid\Screen\Layouts\Table;
use Orchid\Screen\TD;

class DocumentListLayout extends Table
{
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

            TD::make('current_office', 'Current office')
                ->sort(),

            TD::make('status', 'Status')
                ->sort()
                ->render(fn (Document $document) => view('orchid.cells.document-status', compact('document'))),

            TD::make('received_at', 'Date received')
                ->sort()
                ->render(fn (Document $document): string => $document->received_at->format('M d, Y')),

            TD::make('due_at', 'Due date')
                ->sort()
                ->defaultHidden()
                ->render(fn (Document $document): string => $document->due_at?->format('M d, Y') ?? 'Not set'),

            TD::make('updated_at', 'Last updated')
                ->sort()
                ->render(fn (Document $document): string => $document->updated_at->format('M d, Y')),

            TD::make('priority', 'Priority')
                ->sort()
                ->defaultHidden()
                ->render(fn (Document $document) => view('orchid.cells.document-priority', compact('document'))),
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
            'archived' => 'bs.archive',
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
            return 'Adjust or reset the filters to see more records.';
        }

        return match ($this->section()) {
            'incoming' => 'There are no documents awaiting receipt.',
            'outgoing' => 'There are no forwarded documents.',
            'archived' => 'There are no completed records in the archive.',
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
