<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

class AddSecurityHeaders
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // A fresh nonce per response: Vite's tags carry it, and so must any inline <script> (nonce="{{ Vite::cspNonce() }}").
        $nonce = Vite::useCspNonce();

        $response = $next($request);

        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'SAMEORIGIN');
        $response->headers->set('Referrer-Policy', 'same-origin');
        $response->headers->set('Permissions-Policy', 'camera=(), geolocation=(), microphone=()');

        // Browsers remember to use HTTPS for a year once they have reached the live site over HTTPS.
        if ($request->isSecure() && app()->isProduction()) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        $mode = config('app.csp_mode');
        if ($mode !== 'off') {
            $header = $mode === 'report-only' ? 'Content-Security-Policy-Report-Only' : 'Content-Security-Policy';
            $response->headers->set($header, $this->contentSecurityPolicy($nonce, $request->routeIs('platform.documents.attachments.preview')));
        }

        return $response;
    }

    /**
     * Scripts only from this site or inline with this response's nonce (no 'unsafe-inline', no 'unsafe-eval').
     * Styles allow inline because Orchid's tables and the progress bars set style="" attributes.
     *
     * The attachment preview page is the file viewer package's own HTML: it loads its libraries from CDNs, runs inline
     * scripts without a nonce and embeds Google's document viewer, so that one page gets those sources instead.
     */
    private function contentSecurityPolicy(string $nonce, bool $filePreview = false): string
    {
        // While `npm run dev` runs, assets and hot reload come from the Vite dev server.
        $dev = Vite::isRunningHot() ? rtrim(trim((string) file_get_contents(Vite::hotFile())), '/') : null;
        $devSocket = $dev !== null ? preg_replace('/^http/', 'ws', $dev) : null;

        // CSP cannot name an IPv6 host such as http://[::1]:5173 (browsers ignore it and block the dev assets),
        // so on a development machine fall back to allowing http:/ws: sources.
        if ($dev !== null && str_contains($dev, '[')) {
            [$dev, $devSocket] = ['http:', 'ws:'];
        }

        $directives = [
            'default-src' => ["'self'"],
            'script-src' => ["'self'", "'nonce-{$nonce}'", $dev],
            'style-src' => ["'self'", "'unsafe-inline'", $dev],
            'img-src' => ["'self'", 'data:', 'blob:', 'https://www.gravatar.com', $dev],
            'font-src' => ["'self'", 'data:', $dev],
            'connect-src' => ["'self'", $dev, $devSocket],
            'frame-src' => ["'self'"],
            'frame-ancestors' => ["'self'"],
            'form-action' => ["'self'"],
            'base-uri' => ["'self'"],
            'object-src' => ["'none'"],
        ];

        if ($filePreview) {
            $cdns = ['https://cdn.jsdelivr.net', 'https://cdnjs.cloudflare.com', 'https://cdn.tailwindcss.com', 'https://vjs.zencdn.net'];
            // A nonce would make browsers ignore 'unsafe-inline', so this policy has none.
            $directives['script-src'] = ["'self'", "'unsafe-inline'", ...$cdns, $dev];
            $directives['style-src'] = [...$directives['style-src'], ...$cdns];
            $directives['font-src'] = [...$directives['font-src'], ...$cdns];
            $directives['connect-src'] = [...$directives['connect-src'], ...$cdns];
            $directives['frame-src'] = ["'self'", 'https://docs.google.com'];
            $directives['media-src'] = ["'self'", 'blob:'];
        }

        return collect($directives)
            ->map(fn (array $sources, string $directive): string => $directive.' '.implode(' ', array_filter($sources)))
            ->implode('; ');
    }
}
