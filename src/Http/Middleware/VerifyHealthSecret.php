<?php

namespace Shelfwood\Health\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * Shared-secret gate for the health endpoint, compatible with the
 * monitor.shelfwood.co ops path (`x-cron-secret` header, timing-safe
 * comparison). Configured via health.route.secret; when that is null the
 * middleware refuses every request rather than silently opening up — an
 * app that adds this middleware has decided the document is not public.
 */
class VerifyHealthSecret
{
    public function handle(Request $request, Closure $next): mixed
    {
        $expected = config('health.route.secret');
        if (! is_string($expected) || $expected === '') {
            return response()->json(['error' => 'health secret not configured'], 503);
        }

        $provided = $request->header('x-cron-secret');
        $ok = is_string($provided)
            && strlen($provided) === strlen($expected)
            && hash_equals($expected, $provided);

        if (! $ok) {
            return response()->json(['error' => 'unauthorized'], 401);
        }

        return $next($request);
    }
}
