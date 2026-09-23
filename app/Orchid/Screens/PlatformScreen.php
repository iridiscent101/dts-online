<?php

declare(strict_types=1);

namespace App\Orchid\Screens;

use App\Models\Document;
use App\Orchid\Layouts\Document\DocumentListLayout;
use DateTimeImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use Orchid\Screen\Action;
use Orchid\Screen\Screen;
use Orchid\Support\Facades\Layout;

class PlatformScreen extends Screen
{
    /**
     * Fetch data to be displayed on the screen.
     *
     * @return array
     */
    public function query(): iterable
    {
        $section = $this->section();

        if ($section === 'dashboard') {
            return [
                'section' => $section,
                'documentCounts' => [
                    'documents' => Document::query()->count(),
                    'incoming' => Document::query()->where('status', 'Awaiting receipt')->count(),
                    'outgoing' => Document::query()->where('status', 'Forwarded')->count(),
                    'archived' => Document::query()->where('status', 'Archived')->count(),
                ],
                'recentDocuments' => Document::query()->latest('updated_at')->limit(5)->get(),
            ];
        }

        $filters = $this->filterValues($section);
        $filterError = $filters['from'] !== '' && $filters['to'] !== '' && $filters['to'] < $filters['from']
            ? 'The end date must be on or after the start date.'
            : '';

        $documents = Document::query()
            ->forSection($section)
            ->registryFilters($filters)
            ->when($filterError !== '', fn (Builder $query): Builder => $query->whereKey(-1))
            ->filters()
            ->defaultSort('received_at', 'desc')
            ->paginate(10);

        return [
            'section' => $section,
            'documents' => $documents,
            'documentCount' => $documents->total(),
            'filterValues' => $filters,
            'filterError' => $filterError,
        ];
    }

    /**
     * The name of the screen displayed in the header.
     */
    public function name(): ?string
    {
        return 'DepEd Document Tracking System';
    }

    /**
     * Display header description.
     */
    public function description(): ?string
    {
        return 'Department of Education | Records Management';
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
     * @return \Orchid\Screen\Layout[]
     */
    public function layout(): iterable
    {
        if ($this->section() !== 'dashboard') {
            return [
                Layout::wrapper('orchid.registry-page', [
                    'controls' => Layout::view('orchid.registry'),
                    'table' => DocumentListLayout::class,
                ]),
            ];
        }

        return [
            Layout::view('orchid.dashboard'),
        ];
    }

    private function section(): string
    {
        return request()->route()->defaults['section'] ?? 'dashboard';
    }

    /**
     * @return array{search: string, office: string, type: string, status: string, from: string, to: string}
     */
    private function filterValues(string $section): array
    {
        $office = request()->string('office')->trim()->toString();
        $type = request()->string('type')->trim()->toString();
        $status = request()->string('status')->trim()->toString();

        return [
            'search' => Str::limit(request()->string('search')->trim()->toString(), 150, ''),
            'office' => in_array($office, Document::OFFICES, true) ? $office : '',
            'type' => in_array($type, Document::TYPES, true) ? $type : '',
            'status' => $section === 'documents' && in_array($status, Document::STATUSES, true) ? $status : '',
            'from' => $this->dateFilter('from'),
            'to' => $this->dateFilter('to'),
        ];
    }

    private function dateFilter(string $key): string
    {
        $value = request()->string($key)->toString();
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $date !== false && $date->format('Y-m-d') === $value ? $value : '';
    }
}
