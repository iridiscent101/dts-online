<?php

namespace App\Http\Controllers;

use App\Auth\AccountActivity;
use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Support\Timebox;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Throwable;

use function Illuminate\Support\defer;

/**
 * Forgot password for staff: a one-time reset link is emailed to the account's confirmed recovery email. The page
 * answers the same way whether or not the account exists, so it cannot be used to find account emails.
 */
class ForgotPasswordController extends Controller
{
    public const SENT_MESSAGE = 'If that account has a confirmed recovery email, a password reset link was sent to it. Check your Spam folder if it is not in your Inbox, and mark it "Report not spam".';

    // One reset email per account in this many seconds, so nobody can flood a colleague's recovery inbox.
    public const ACCOUNT_COOLDOWN_SECONDS = 600;

    public function show(): View
    {
        return view('platform::auth.forgot-password');
    }

    public function send(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'email' => ['required', 'string', 'email', 'max:255'],
        ], [
            'email.required' => 'Enter the email address you sign in with.',
            'email.email' => 'Enter a valid email address.',
        ]);

        $email = Str::lower(trim($data['email']));
        $work = function () use ($email): void {
            $user = User::query()->where('email', $email)->first();

            if ($user instanceof User && $this->canReceiveResetLink($user)
                && RateLimiter::attempt('password-reset-link:'.$user->id, 1, fn () => true, self::ACCOUNT_COOLDOWN_SECONDS)) {
                $this->sendResetLink($user);
            }
        };

        // Sending the email takes seconds, so answering only after it would show who has a recovery email. Every answer
        // must take the same time: on a normal server the work runs after the page has answered; on Vercel (sync queue),
        // where work after the answer may never run, every answer waits the same minimum time instead.
        if (config('queue.default') === 'sync') {
            (new Timebox)->call(fn () => $work(), (int) round((float) config('dts.forgot_password_answer_seconds') * 1_000_000));
        } else {
            defer($work);
        }

        return redirect()->route('password.forgot')->with('status', self::SENT_MESSAGE);
    }

    public function showReset(Request $request, string $token): View
    {
        return view('platform::auth.reset-password', [
            'email' => is_string($request->query('email')) ? $request->query('email') : '',
            'token' => $token,
        ]);
    }

    public function reset(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'email' => ['required', 'string', 'email', 'max:255'],
            'token' => ['required', 'string'],
            'password' => ['required', 'confirmed', PasswordRule::defaults()],
        ]);

        $status = Password::reset($data, function (User $user, string $password): void {
            abort_unless($this->canReceiveResetLink($user), 403);

            $user->forceFill([
                'password' => Hash::make($password),
                'must_change_password' => false,
                'remember_token' => Str::random(60),
            ])->save();
            AccountActivity::record(AccountActivity::PASSWORD_CHANGED, $user, null, ['recovered' => true]);
            event(new PasswordReset($user));
        });

        if ($status !== Password::PasswordReset) {
            return back()->withInput($request->only('email'))->withErrors(['email' => __($status)]);
        }

        return redirect()->route('platform.login')->with('status', 'Your password was reset. You can sign in now.');
    }

    private function canReceiveResetLink(User $user): bool
    {
        return ! $user->isDeactivated()
            && $user->canChangeOwnPassword()
            && filled($user->notification_email)
            && $user->notification_email_verified_at !== null;
    }

    private function sendResetLink(User $user): void
    {
        try {
            Password::sendResetLink(['email' => $user->email]);
        } catch (Throwable $exception) {
            report($exception);
            RateLimiter::clear('password-reset-link:'.$user->id);
        }
    }
}
