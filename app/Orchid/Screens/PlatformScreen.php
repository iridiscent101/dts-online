<?php

declare(strict_types=1);

namespace App\Orchid\Screens;

use App\Models\Document;
use App\Models\DocumentMovement;
use App\Models\User;
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
        $user = request()->user();
        abort_unless($user instanceof User, 403);

        if ($section === 'dashboard') {
            $office = $user->office;
            $isAdministrator = $user->isAdministrator();
            $visibleDocuments = Document::query()->visibleTo($user);
            $officeQueueQuery = (clone $visibleDocuments)
                ->whereIn('status', ['Awaiting receipt', 'Forwarded', 'Received']);

            return [
                'section' => $section,
                'workspaceOffice' => $office ?? ($isAdministrator ? 'All offices' : 'No office assigned'),
                'officeQueue' => (clone $officeQueueQuery)->orderByRaw('due_at IS NULL, due_at')->latest('updated_at')->limit(5)->get(),
                'officeQueueCount' => (clone $officeQueueQuery)->count(),
                'officeOverdueCount' => (clone $officeQueueQuery)->whereDate('due_at', '<', today())->count(),
                'unreadNotifications' => $user->unreadNotifications()->limit(4)->get(),
                'unreadNotificationCount' => $user->unreadNotifications()->count(),
                'documentCounts' => [
                    'documents' => (clone $visibleDocuments)->count(),
                    'incoming' => (clone $visibleDocuments)->where('status', 'Awaiting receipt')->count(),
                    'outgoing' => (clone $visibleDocuments)->where('status', 'Forwarded')->count(),
                    'archived' => (clone $visibleDocuments)->where('status', 'Archived')->count(),
                ],
                'recentDocuments' => (clone $visibleDocuments)->latest('updated_at')->limit(5)->get(),
                'recentActivity' => DocumentMovement::query()
                    ->with(['document', 'actor'])
                    ->whereHas('document', fn (Builder $query): Builder => $query->visibleTo($user))
                    ->latest()
                    ->limit(4)
                    ->get(),
            ];
        }

        $filters = $this->filterValues($section);
        $filterError = $filters['from'] !== '' && $filters['to'] !== '' && $filters['to'] < $filters['from']
            ? 'The end date must be on or after the start date.'
            : '';

        $documents = Document::query()
            ->visibleTo($user)
            ->forSection($section)
            ->registryFilters($filters)
            ->when($filterError !== '', fn (Builder $query): Builder => $query->whereKey(-1))
            ->filters()
            ->defaultSort('received_at', 'desc')
            ->paginate($this->perPage());

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
                    'pageSize' => Layout::view('orchid.table-page-size'),
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

    private function perPage(): int
    {
        $perPage = request()->integer('per_page', 10);

        return in_array($perPage, [10, 25, 50, 100], true) ? $perPage : 10;
    }
}
