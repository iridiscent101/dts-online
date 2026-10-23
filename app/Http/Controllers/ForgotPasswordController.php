<?php

namespace App\Http\Controllers;

use App\Auth\AccountActivity;
use App\Models\User;
use App\Notifications\TemporaryPasswordNotification;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Support\Timebox;
use Throwable;

use function Illuminate\Support\defer;

/**
 * Forgot password for staff: a temporary password is emailed to the account's confirmed recovery email, and the person
 * must set a new one right after signing in with it (RequirePasswordChange). The page answers the same way whether or
 * not the account exists, so it cannot be used to find out which emails have accounts.
 */
class ForgotPasswordController extends Controller
{
    public const SENT_MESSAGE = 'If that account has a confirmed recovery email, a temporary password was sent to it. Check your Spam folder if it is not in your Inbox, and mark it "Report not spam".';

    // One temporary password per account in this many seconds, so nobody can keep resetting a colleague's password.
    public const ACCOUNT_COOLDOWN_SECONDS = 600;

    // Readable characters only (no 0/O, 1/l/I), so the password is easy to type from the email.
    private const UPPER = 'ABCDEFGHJKLMNPQRSTUVWXYZ';

    private const LOWER = 'abcdefghijkmnpqrstuvwxyz';

    private const DIGITS = '23456789';

    private const SYMBOLS = '!@#$%*?';

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

            if ($user instanceof User && $this->canReceiveTemporaryPassword($user)
                && RateLimiter::attempt('temporary-password:'.$user->id, 1, fn () => true, self::ACCOUNT_COOLDOWN_SECONDS)) {
                $this->sendTemporaryPassword($user);
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

    private function canReceiveTemporaryPassword(User $user): bool
    {
        return ! $user->isDeactivated()
            && $user->canChangeOwnPassword()
            && filled($user->notification_email)
            && $user->notification_email_verified_at !== null;
    }

    private function sendTemporaryPassword(User $user): void
    {
        $temporaryPassword = self::generate();

        try {
            // The new password is saved only if the email goes out, so a mail failure never locks the person out.
            DB::transaction(function () use ($user, $temporaryPassword): void {
                $user->forceFill([
                    'password' => Hash::make($temporaryPassword),
                    'must_change_password' => true,
                    'remember_token' => Str::random(60),
                ])->save();
                AccountActivity::record(AccountActivity::TEMPORARY_PASSWORD_SENT, $user, null);
                $user->notifyNow(new TemporaryPasswordNotification($temporaryPassword));
            });
        } catch (Throwable $exception) {
            report($exception);
            RateLimiter::clear('temporary-password:'.$user->id);
        }
    }

    /**
     * 14 characters with capitals, small letters, numbers and symbols, so it meets the password rule.
     */
    public static function generate(): string
    {
        $pick = fn (string $set, int $count): array => array_map(
            fn (): string => $set[random_int(0, strlen($set) - 1)],
            range(1, $count),
        );
        $characters = [
            ...$pick(self::UPPER, 3),
            ...$pick(self::LOWER, 6),
            ...$pick(self::DIGITS, 3),
            ...$pick(self::SYMBOLS, 2),
        ];

        // Fisher-Yates with random_int, so the order is as unpredictable as the characters.
        for ($i = count($characters) - 1; $i > 0; $i--) {
            $j = random_int(0, $i);
            [$characters[$i], $characters[$j]] = [$characters[$j], $characters[$i]];
        }

        return implode('', $characters);
    }
}
