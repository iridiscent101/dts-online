<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class PauseDocumentUploads
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! config('app.document_uploads_enabled')
            && ($request->routeIs('platform.documents.attachments.*')
                || $request->routeIs('platform.systems.files.*'))) {
            abort(404);
        }

        return $next($request);
    }
}
