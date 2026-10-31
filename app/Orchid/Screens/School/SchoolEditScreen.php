<?php

declare(strict_types=1);

namespace App\Orchid\Screens\School;

use App\Models\School;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Orchid\Screen\Action;
use Orchid\Screen\Actions\Button;
use Orchid\Screen\Fields\Input;
use Orchid\Screen\Screen;
use Orchid\Support\Facades\Layout;
use Orchid\Support\Facades\Toast;

/**
 * Adds a school that is missing from the DepEd catalog, or renames one, so the document origin suggestions stay right.
 * Same full-page form as Users, Offices and Roles.
 */
class SchoolEditScreen extends Screen
{
    /**
     * @var School
     */
    public $school;

    /**
     * @return array<string, School>
     */
    public function query(School $school): iterable
    {
        return [
            'school' => $school,
        ];
    }

    public function name(): ?string
    {
        return $this->school->exists ? 'Edit school' : 'Create school';
    }

    public function description(): ?string
    {
        return $this->school->exists
            ? 'Update the school name. Existing document origins remain unchanged.'
            : 'Add a school to the document origin list.';
    }

    public function permission(): ?iterable
    {
        return [
            'platform.systems.schools',
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
            Layout::block(Layout::rows([
                Input::make('school.name')
                    ->title(__('School name'))
                    ->required()
                    ->maxlength(180)
                    ->placeholder(__('e.g. Bayombong Central School'))
                    ->help($this->school->exists
                        ? __('Documents already registered keep the origin name they were saved with.')
                        : __('Use the official name, the way it should appear on documents.')),
            ]))
                ->title(__('School'))
                ->description(__('Schools appear in the origin suggestions when registering documents.')),
        ];
    }

    public function save(Request $request, School $school): RedirectResponse
    {
        abort_unless($request->user()?->hasAccess('platform.systems.schools'), 403);

        $request->merge(['school' => ['name' => trim((string) $request->input('school.name'))]]);

        $name = $request->validate([
            'school.name' => [
                'required',
                'string',
                'max:180',
                // Removed schools do not count, so a school removed by mistake can be added again.
                Rule::unique(School::class, 'name')->whereNull('deleted_at')->ignore($school),
            ],
        ], [
            'school.name.unique' => 'This school is already on the list.',
        ])['school']['name'];

        if ($school->exists) {
            $school->update(['name' => $name]);
            Toast::success(__(':school was saved.', ['school' => $name]));
        } else {
            School::query()->create(['name' => $name, 'source_unit_id' => null]);
            Toast::success(__(':school was added.', ['school' => $name]));
        }

        return redirect()->route('platform.systems.schools');
    }
}
