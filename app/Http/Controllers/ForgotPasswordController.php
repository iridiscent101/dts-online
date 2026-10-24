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
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Support\Timebox;
use Throwable;

use function Illuminate\Support\defer;

/**
 * Forgot password for staff: a temporary password is emailed to the account's recovery email, and the person
 * must set a new one right after signing in with it (RequirePasswordChange). The page answers the same way whether or
 * not the account exists, so it cannot be used to find out which emails have accounts.
 */
class ForgotPasswordController extends Controller
{
    public const SENT_MESSAGE = 'If that account has a recovery email, a temporary password was sent to it. Check your Spam folder if it is not in your Inbox, and mark it "Report not spam".';

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
        self::trace('request received (queue: '.config('queue.default').')');
        $work = function () use ($email): void {
            self::trace('started');
            $user = User::query()->where('email', $email)->first();

            // One server-log line per request saying where it stopped, so a missing email can be traced. The page itself
            // still answers the same way every time, and the log holds no email address or password.
            $outcome = match (true) {
                ! $user instanceof User => 'no matching account',
                ! $this->canReceiveTemporaryPassword($user) => 'account cannot receive one ('.$this->ineligibleReason($user).')',
                ! RateLimiter::attempt('temporary-password:'.$user->id, 1, fn () => true, self::ACCOUNT_COOLDOWN_SECONDS) => 'cooldown: one was sent in the last 10 minutes',
                default => $this->sendTemporaryPassword($user),
            };

            self::trace($outcome.($user ? ' (user #'.$user->id.')' : ''));
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
            && filled($user->notification_email);
    }

    /**
     * Goes to the app log and straight to stderr, which is the only log Vercel keeps whatever LOG_CHANNEL says.
     */
    private static function trace(string $message): void
    {
        Log::info('Forgot password: '.$message);

        if (! app()->runningUnitTests()) {
            error_log('FORGOT_PASSWORD '.$message);
        }
    }

    private function ineligibleReason(User $user): string
    {
        return match (true) {
            $user->isDeactivated() => 'deactivated',
            ! $user->canChangeOwnPassword() => 'kiosk-only account',
            default => 'no recovery email saved',
        };
    }

    /**
     * @return string What happened, for the server log.
     */
    private function sendTemporaryPassword(User $user): string
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

            return 'sending failed ('.$exception::class.': '.Str::limit($exception->getMessage(), 300).')';
        }

        return 'temporary password sent';
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
