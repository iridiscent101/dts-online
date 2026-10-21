<?php

declare(strict_types=1);

namespace App\Orchid\Screens;

use App\Auth\AccountActivity;
use App\Models\User;
use App\Orchid\Filters\AccountActivityFilter;
use Orchid\Screen\Screen;
use Orchid\Screen\TD;
use Orchid\Support\Facades\Layout;
use Spatie\Activitylog\Models\Activity;

/**
 * Who signed in, failed to sign in, was locked out or deactivated, and from which computer (AccountActivity).
 */
class SignInActivityScreen extends Screen
{
    public function query(): iterable
    {
        $query = Activity::query()
            ->where('log_name', AccountActivity::LOG_NAME)
            ->with(['causer', 'subject'])
            ->latest('id');

        return [
            'activities' => (new AccountActivityFilter)->filter($query)->paginate(25)->withQueryString(),
        ];
    }

    public function name(): ?string
    {
        return 'Sign-in activity';
    }

    public function description(): ?string
    {
        return 'Sign-ins, failed attempts, lockouts, password changes and deactivated accounts, newest first.';
    }

    public function permission(): ?iterable
    {
        return ['platform.systems.sign-in-activity'];
    }

    public function layout(): iterable
    {
        return [
            Layout::selection([AccountActivityFilter::class]),

            Layout::table('activities', [
                // Every column renders itself: Activity is not an Orchid model, so the table cannot read fields by name.
                TD::make('created_at', __('When'))
                    ->render(fn (Activity $activity): string => e($activity->created_at?->copy()->timezone('Asia/Manila')->format('M j, Y g:i A') ?? '')),

                TD::make('description', __('What happened'))
                    ->render(fn (Activity $activity): string => in_array($activity->event, [AccountActivity::SIGN_IN_FAILED, AccountActivity::LOCKED_OUT, AccountActivity::DEACTIVATED_SIGN_IN], true)
                        ? '<strong>'.e($activity->description).'</strong>'
                        : e($activity->description)),

                TD::make('account', __('Account'))
                    ->render(fn (Activity $activity): string => e($this->accountLabel($activity))),

                TD::make('by', __('Done by'))
                    ->render(fn (Activity $activity): string => e($this->doneBy($activity))),

                TD::make('details', __('Details'))
                    ->render(fn (Activity $activity): string => e($this->details($activity))),

                TD::make('ip', __('IP address'))
                    ->render(fn (Activity $activity): string => e((string) data_get($activity->properties, 'ip', ''))),

                TD::make('browser', __('Browser'))
                    ->defaultHidden()
                    ->render(fn (Activity $activity): string => e((string) data_get($activity->properties, 'browser', ''))),
            ]),
        ];
    }

    private function accountLabel(Activity $activity): string
    {
        $subject = $activity->subject;

        if ($subject instanceof User) {
            return $subject->name.' ('.$subject->email.')';
        }

        $email = (string) data_get($activity->properties, 'email', '');

        return $email !== '' ? $email.' (no such account)' : '—';
    }

    private function doneBy(Activity $activity): string
    {
        $causer = $activity->causer;

        if (! $causer instanceof User) {
            return '—';
        }

        return $activity->subject instanceof User && $activity->subject->is($causer) ? 'Themselves' : $causer->name;
    }

    private function details(Activity $activity): string
    {
        $properties = $activity->properties;

        return collect([
            data_get($properties, 'reason') === 'wrong_password' ? 'Wrong password' : null,
            data_get($properties, 'reason') === 'inactivity' ? 'No activity for a while' : null,
            data_get($properties, 'reason') === 'deactivated' ? 'Account was deactivated' : null,
            data_get($properties, 'remembered') ? 'Remembered on this computer' : null,
            data_get($properties, 'switched_by_admin') ? 'Administrator viewing as this user' : null,
            data_get($properties, 'set_by_admin') ? 'Set by an administrator' : null,
            data_get($properties, 'minutes') ? 'Locked for '.data_get($properties, 'minutes').' min (lockout '.data_get($properties, 'lockout').' today)' : null,
        ])->filter()->implode('; ');
    }
}
