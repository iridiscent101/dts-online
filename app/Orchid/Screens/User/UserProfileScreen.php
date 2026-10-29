<?php

declare(strict_types=1);

namespace App\Orchid\Screens\User;

use App\Auth\AccountActivity;
use App\Http\Controllers\RecoveryEmailController;
use App\Models\User;
use App\Orchid\Layouts\User\ProfilePasswordLayout;
use App\Orchid\Layouts\User\UserEditLayout;
use App\Rules\EmailDomainReceivesMail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Orchid\Access\Impersonation;
use Orchid\Screen\Action;
use Orchid\Screen\Actions\Button;
use Orchid\Screen\Screen;
use Orchid\Support\Facades\Layout;
use Orchid\Support\Facades\Toast;

class UserProfileScreen extends Screen
{
    /**
     * Fetch data to be displayed on the screen.
     *
     *
     * @return array
     */
    public function query(Request $request): iterable
    {
        return [
            'user' => $request->user(),
        ];
    }

    /**
     * The name of the screen displayed in the header.
     */
    public function name(): ?string
    {
        return 'My account';
    }

    /**
     * Display header description.
     */
    public function description(): ?string
    {
        return 'Update your account details such as name, email address and password';
    }

    /**
     * The screen's action buttons.
     *
     * @return Action[]
     */
    public function commandBar(): iterable
    {
        return [
            Button::make('Back to my account')
                ->novalidate()
                ->canSee(Impersonation::isSwitch())
                ->icon('bs.people')
                ->route('platform.switch.logout'),

            Button::make('Sign out')
                ->novalidate()
                ->icon('bs.box-arrow-left')
                ->route('platform.logout'),
        ];
    }

    /**
     * @return \Orchid\Screen\Layout[]
     */
    public function layout(): iterable
    {
        $passwordBlock = Layout::block(ProfilePasswordLayout::class)
            ->title(__('Update Password'))
            ->description(__('Ensure your account is using a long, random password to stay secure.'))
            ->commands(
                Button::make(__('Update password'))
                    ->class('btn dts-button dts-button-primary')
                    ->icon('bs.check-circle')
                    ->method('changePassword')
            );

        // Sent here by RequirePasswordChange: the notice and the password form come first, nothing else is shown.
        if (request()->user()?->must_change_password) {
            return [
                Layout::view('orchid.password-change-required'),
                $passwordBlock,
            ];
        }

        return [
            Layout::block(UserEditLayout::class)
                ->title(__('Profile Information'))
                ->description(__("Update your account's profile information and email address."))
                ->commands([
                    Button::make(__('Save'))
                        ->class('btn dts-button dts-button-primary')
                        ->icon('bs.check-circle')
                        ->method('save'),
                    Button::make(__('Send recovery email verification'))
                        ->novalidate()
                        ->method('verifyRecoveryEmail')
                        ->canSee(filled(request()->user()?->notification_email) && ! request()->user()?->notification_email_verified_at),
                ]),

            Layout::view('orchid.recovery-email-status'),

            Layout::block(Layout::view('orchid.profile-account'))
                ->title(__('Account access'))
                ->description(__('Your offices and roles decide which documents you see and what you can do. An administrator sets them.')),

            $passwordBlock,
        ];
    }

    public function save(Request $request): void
    {
        $request->validate([
            'user.name' => 'required|string',
            'user.email' => [
                'required',
                Rule::unique(User::class, 'email')->ignore($request->user()),
            ],
            'user.notification_email' => ['bail', 'nullable', 'email:rfc,filter', 'max:255', new EmailDomainReceivesMail($request->user()?->notification_email)],
        ]);

        $user = $request->user();
        if ($request->has('user.notification_email')) {
            $user->setNotificationEmail($request->input('user.notification_email'));
        }

        $changed = $user->isDirty('notification_email');
        $user->fill($request->collect('user')->only(['name', 'email'])->toArray())->save();
        if ($changed && filled($user->notification_email)) {
            RecoveryEmailController::sendVerification($user);
        }

        Toast::success(__('Profile updated.'));
    }

    public function verifyRecoveryEmail(Request $request): void
    {
        $user = $request->user();
        if (filled($user->notification_email) && ! $user->notification_email_verified_at) {
            RecoveryEmailController::sendVerification($user);
            Toast::info(__('If your recovery email can receive messages, a verification link has been sent.'));
        }
    }

    public function changePassword(Request $request): ?RedirectResponse
    {
        $guard = config('platform.guard', 'web');
        $request->validate([
            'old_password' => 'required|current_password:'.$guard,
            'password' => ['required', 'confirmed', 'different:old_password', Password::defaults()],
        ]);

        // Sessions on other computers end on their next request: 'auth.session' compares the stored password hash.
        $user = $request->user();
        $wasRequired = $user->must_change_password;
        $user->forceFill([
            'password' => Hash::make($request->get('password')),
            'must_change_password' => false,
        ])->save();
        AccountActivity::record(AccountActivity::PASSWORD_CHANGED, $user, $user);

        Toast::success(__('Password changed.'));

        // Someone who had to change it goes on to the dashboard they were kept from.
        return $wasRequired ? redirect()->route(config('platform.index')) : null;
    }
}
