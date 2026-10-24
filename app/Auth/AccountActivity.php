<?php

namespace App\Auth;

use App\Models\User;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Str;
use Orchid\Access\Impersonation;

/**
 * The account security log (activity log "accounts"): sign-ins, failed sign-ins, sign-outs, lockouts, password changes
 * and account deactivation. Each entry keeps the computer's IP address and browser. Administrators read it on the
 * Sign-in activity screen. Passwords are never recorded.
 */
class AccountActivity
{
    public const LOG_NAME = 'accounts';

    public const SIGNED_IN = 'signed_in';

    public const SIGN_IN_FAILED = 'sign_in_failed';

    public const SIGNED_OUT = 'signed_out';

    public const LOCKED_OUT = 'locked_out';

    public const PASSWORD_CHANGED = 'password_changed';

    public const DEACTIVATED = 'deactivated';

    public const REACTIVATED = 'reactivated';

    public const DELETED = 'deleted';

    public const DEACTIVATED_SIGN_IN = 'deactivated_sign_in';

    public const PASSWORD_CHANGE_REQUIRED = 'password_change_required';

    public const TEMPORARY_PASSWORD_SENT = 'temporary_password_sent';

    public const TEMPORARY_PASSWORD_USED = 'temporary_password_used';

    public const LABELS = [
        self::SIGNED_IN => 'Signed in',
        self::SIGN_IN_FAILED => 'Sign-in failed',
        self::SIGNED_OUT => 'Signed out',
        self::LOCKED_OUT => 'Sign-in locked',
        self::PASSWORD_CHANGED => 'Password changed',
        self::DEACTIVATED => 'Account deactivated',
        self::REACTIVATED => 'Account reactivated',
        self::DELETED => 'Account deleted',
        self::DEACTIVATED_SIGN_IN => 'Sign-in refused: account deactivated',
        self::PASSWORD_CHANGE_REQUIRED => 'Asked to set a stronger password',
        self::TEMPORARY_PASSWORD_SENT => 'Temporary password emailed (forgot password)',
        self::TEMPORARY_PASSWORD_USED => 'Signed in with a temporary password',
    ];

    // Set on the request by whoever signs a person out for a reason other than the Sign out button.
    public const SIGN_OUT_REASON = 'dts_sign_out_reason';

    public function subscribe(Dispatcher $events): void
    {
        $events->listen(Login::class, function (Login $event): void {
            if (! $event->user instanceof User) {
                return;
            }

            self::record(self::SIGNED_IN, $event->user, $event->user, array_filter([
                'remembered' => $event->remember && ! request()->routeIs('platform.login.auth'),
                'switched_by_admin' => Impersonation::isSwitch(),
            ]));
        });

        $events->listen(Failed::class, function (Failed $event): void {
            $user = $event->user instanceof User ? $event->user : null;

            self::record(self::SIGN_IN_FAILED, $user, null, [
                'email' => Str::limit(Str::lower(trim((string) ($event->credentials['email'] ?? ''))), 255, ''),
                'reason' => $user === null ? 'unknown_email' : 'wrong_password',
            ]);
        });

        $events->listen(Logout::class, function (Logout $event): void {
            if (! $event->user instanceof User) {
                return;
            }

            self::record(self::SIGNED_OUT, $event->user, $event->user, array_filter([
                'reason' => request()->attributes->get(self::SIGN_OUT_REASON),
            ]));
        });
    }

    /**
     * @param  User|null  $subject  the account the entry is about
     * @param  User|null  $causer  who did it (null when nobody is signed in, such as a failed sign-in)
     * @param  array<string, mixed>  $properties
     */
    public static function record(string $event, ?User $subject, ?User $causer = null, array $properties = []): void
    {
        $request = request();

        $logger = activity(self::LOG_NAME)
            ->event($event)
            ->withProperties([
                ...$properties,
                'ip' => $request->ip(),
                'browser' => Str::limit((string) $request->userAgent(), 255, ''),
            ]);

        if ($subject !== null) {
            $logger->performedOn($subject);
        }

        if ($causer !== null) {
            $logger->causedBy($causer);
        } else {
            // activity() falls back to the signed-in user; a failed or locked sign-in has nobody behind it.
            $logger->causedByAnonymous();
        }

        $logger->log(self::LABELS[$event] ?? $event);
    }
}
