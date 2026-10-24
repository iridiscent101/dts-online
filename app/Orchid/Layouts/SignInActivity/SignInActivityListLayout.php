<?php

declare(strict_types=1);

namespace App\Orchid\Layouts\SignInActivity;

use App\Auth\AccountActivity;
use App\Models\User;
use App\Orchid\Layouts\Concerns\HasTableSelectionColumn;
use Orchid\Screen\Layouts\Table;
use Orchid\Screen\TD;
use Spatie\Activitylog\Models\Activity;

/**
 * Sign-in activity log. Entries cannot be edited or deleted here (they expire after 3 months), so there is no
 * checkbox or actions column.
 */
class SignInActivityListLayout extends Table
{
    use HasTableSelectionColumn;

    /**
     * @var string
     */
    public $target = 'activities';

    /**
     * @return TD[]
     */
    public function columns(): array
    {
        return [
            $this->numberColumn(),

            // Every column renders itself: Activity is not an Orchid model, so the table cannot read fields by name.
            TD::make('created_at', __('When'))
                ->width('130px')
                ->cantHide()
                ->render(fn (Activity $activity): string => e($activity->created_at?->copy()->timezone('Asia/Manila')->format('M j, Y g:i A') ?? '')),

            TD::make('description', __('What happened'))
                ->cantHide()
                ->render(fn (Activity $activity): string => in_array($activity->event, [AccountActivity::SIGN_IN_FAILED, AccountActivity::LOCKED_OUT, AccountActivity::DEACTIVATED_SIGN_IN], true)
                    ? '<strong>'.e($activity->description).'</strong>'
                    : e($activity->description)),

            TD::make('account', __('Account'))
                ->render(fn (Activity $activity): string => e($this->accountLabel($activity))),

            TD::make('by', __('Done by'))
                ->width('120px')
                ->render(fn (Activity $activity): string => e($this->doneBy($activity))),

            TD::make('details', __('Details'))
                ->render(fn (Activity $activity): string => e($this->details($activity))),

            TD::make('ip', __('IP address'))
                ->width('130px')
                ->render(fn (Activity $activity): string => e((string) data_get($activity->properties, 'ip', ''))),

            TD::make('browser', __('Browser'))
                ->defaultHidden()
                ->render(fn (Activity $activity): string => e((string) data_get($activity->properties, 'browser', ''))),
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
            data_get($properties, 'transferred_to') ? 'Data transferred to '.data_get($properties, 'transferred_to') : null,
            data_get($properties, 'minutes') ? 'Locked for '.data_get($properties, 'minutes').' min (lockout '.data_get($properties, 'lockout').' today)' : null,
        ])->filter()->implode('; ');
    }
}
