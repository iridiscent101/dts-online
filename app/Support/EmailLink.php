<?php

namespace App\Support;

/**
 * Links inside emails always start with the configured site address (APP_URL), never with the host of the request
 * that triggered the email. Otherwise anyone could send a request with a forged Host header (for example to Forgot
 * password) and get a real email from the Records Unit that points the reader to a look-alike site.
 */
class EmailLink
{
    /**
     * @param  array<string, mixed>|string|int  $parameters
     */
    public static function route(string $name, array|string|int $parameters = []): string
    {
        return self::to(route($name, $parameters, false));
    }

    /**
     * @param  string  $path  a path from the site root, such as the result of route(..., absolute: false)
     */
    public static function to(string $path): string
    {
        return rtrim((string) config('app.url'), '/').'/'.ltrim($path, '/');
    }
}
