<?php

declare(strict_types=1);

namespace App\Orchid\Screens\Office;

use App\Models\Document;
use App\Models\DocumentMovement;
use App\Models\DocumentOfficeAssignment;
use App\Models\Office;
use App\Models\User;
use App\Orchid\Layouts\Office\OfficeEditLayout;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Orchid\Screen\Action;
use Orchid\Screen\Actions\Button;
use Orchid\Screen\Screen;
use Orchid\Support\Facades\Layout;
use Orchid\Support\Facades\Toast;

class OfficeEditScreen extends Screen
{
    /**
     * @var Office
     */
    public $office;

    /**
     * @return array<string, Office>
     */
    public function query(Office $office): iterable
    {
        if (! $office->exists) {
            $office->fill([
                'sort_order' => 0,
                'is_active' => true,
            ]);
        }

        return [
            'office' => $office,
        ];
    }

    public function name(): ?string
    {
        return $this->office->exists ? 'Edit Office' : 'Create Office';
    }

    public function description(): ?string
    {
        return 'Office details used by routing, registration, and personnel assignment.';
    }

    public function permission(): ?iterable
    {
        return [
            'platform.systems.offices',
        ];
    }

    /**
     * @return Action[]
     */
    public function commandBar(): iterable
    {
        return [
            Button::make(__('Save'))
                ->class('btn dts-button dts-button-primary')
                ->icon('bs.check-circle')
                ->method('save'),
        ];
    }

    /**
     * @return \Orchid\Screen\Layout[]
     */
    public function layout(): iterable
    {
        return [
            Layout::block(OfficeEditLayout::class)
                ->title(__('Office'))
                ->description(__('Update the office name, source list membership, ordering, and availability.')),
        ];
    }

    public function save(Office $office, Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'office.name' => [
                'required',
                'string',
                'max:180',
                Rule::unique(Office::class, 'name')->ignore($office),
            ],
            'office.sort_order' => ['required', 'integer', 'min:0', 'max:65535'],
            'office.sdo_sort_order' => ['nullable', 'integer', 'min:1', 'max:65535'],
            'office.client_sort_order' => ['nullable', 'integer', 'min:1', 'max:65535'],
            'office.is_sdo' => ['boolean'],
            'office.is_client' => ['boolean'],
            'office.is_active' => ['boolean'],
        ]);

        DB::transaction(function () use ($office, $validated, $request): void {
            if ($office->exists) {
                $office = Office::query()->lockForUpdate()->findOrFail($office->getKey());
            }

            if ($office->name === 'SDO-Records Unit' && $validated['office']['name'] !== $office->name) {
                throw ValidationException::withMessages([
                    'office.name' => 'The Records intake office cannot be renamed.',
                ]);
            }

            if ($office->name === 'SDO-Records Unit' && ! $request->boolean('office.is_active')) {
                throw ValidationException::withMessages([
                    'office.is_active' => 'The Records intake office must remain active.',
                ]);
            }

            // shortcut: history stores office names; allow renames after movements use stable office IDs.
            if ($office->exists && $validated['office']['name'] !== $office->name && DocumentMovement::query()
                ->where('from_office', $office->name)
                ->orWhere('to_office', $office->name)
                ->exists()) {
                throw ValidationException::withMessages([
                    'office.name' => 'An office with document history cannot be renamed.',
                ]);
            }

            $previousName = $office->exists ? $office->name : null;
            $office->fill($validated['office']);
            $office->forceFill([
                'is_sdo' => $request->boolean('office.is_sdo'),
                'is_client' => $request->boolean('office.is_client'),
                'is_active' => $request->boolean('office.is_active'),
            ]);
            $office->save();

            if ($previousName !== null && $previousName !== $office->name) {
                User::query()->where('office', $previousName)->update(['office' => $office->name]);
                Document::query()->where('current_office', $previousName)->update(['current_office' => $office->name]);
                DocumentOfficeAssignment::query()->where('office', $previousName)->update(['office' => $office->name]);
                DB::table('document_route_steps')->where('office', $previousName)->update(['office' => $office->name]);
            }
        });

        Toast::info(__('Office was saved.'));

        return redirect()->route('platform.systems.offices');
    }
}
