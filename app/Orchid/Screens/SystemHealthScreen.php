<?php

namespace App\Orchid\Screens;

use App\Console\SystemBackup;
use App\Console\SystemHealth;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Number;
use Orchid\Screen\Action;
use Orchid\Screen\Actions\Button;
use Orchid\Screen\Actions\Link;
use Orchid\Screen\Screen;
use Orchid\Support\Facades\Layout;
use Orchid\Support\Facades\Toast;
use Throwable;

class SystemHealthScreen extends Screen
{
    /**
     * Fetch data to be displayed on the screen.
     *
     * @return array
     */
    public function query(SystemHealth $health): iterable
    {
        return ['health' => $health->report()];
    }

    /**
     * The name of the screen displayed in the header.
     */
    public function name(): ?string
    {
        return 'System health';
    }

    public function description(): ?string
    {
        return 'Operational status for services that protect document availability.';
    }

    public function permission(): ?iterable
    {
        return ['platform.systems.health'];
    }

    /**
     * The screen's action buttons.
     *
     * @return Action[]
     */
    public function commandBar(): iterable
    {
        return [
            Button::make('Back up now')
                ->icon('bs.archive')
                ->class('dts-button dts-button-primary')
                ->method('createBackup'),
            Link::make('Refresh')
                ->icon('bs.arrow-clockwise')
                ->class('dts-button dts-button-primary')
                ->route('platform.systems.health'),
        ];
    }

    public function createBackup(SystemBackup $backups): RedirectResponse
    {
        try {
            $result = $backups->create();
        } catch (Throwable $exception) {
            report($exception);
            Toast::error('The system backup could not be created. Check the application log for details.');

            return redirect()->route('platform.systems.health');
        }

        Toast::success('Backup created and verified: '.Number::fileSize($result['bytes']).'.');

        return redirect()->route('platform.systems.health');
    }

    /**
     * The screen's layout elements.
     *
     * @return \Orchid\Screen\Layout[]|string[]
     */
    public function layout(): iterable
    {
        return [Layout::view('orchid.system-health')];
    }
}
