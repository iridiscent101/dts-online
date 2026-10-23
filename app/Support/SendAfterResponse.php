<?php

namespace App\Support;

use Closure;
use Illuminate\Support\Facades\DB;

use function Illuminate\Support\defer;

/**
 * Sends an email without a queue worker, once the surrounding save commits. A mail error is logged, never shown, and
 * never undoes the save.
 *
 * - On a normal server the email goes out after the page has answered, so nobody waits for the mail server.
 * - On Vercel (QUEUE_CONNECTION=sync) it goes out before the page answers: a serverless function can be frozen as
 *   soon as its response is delivered, so work left for after the response might never run.
 * - In console commands (no response to wait for) it goes out right away.
 */
class SendAfterResponse
{
    public static function mail(Closure $send): void
    {
        DB::afterCommit(function () use ($send): void {
            $sendBeforeResponse = config('queue.default') === 'sync'
                || (app()->runningInConsole() && ! app()->runningUnitTests());

            if ($sendBeforeResponse) {
                rescue($send);

                return;
            }

            defer(fn () => rescue($send));
        });
    }
}
