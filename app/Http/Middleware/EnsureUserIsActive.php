<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Runs after `auth:customer`. Suspending a trader from the dashboard revokes
 * their tokens there and then; this catches anything that slips through and
 * makes the suspension take effect on the very next request.
 */
class EnsureUserIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || ! $user->is_active) {
            $user?->tokens()->delete();

            return response()->json([
                'message' => 'This account has been suspended. Contact support for help.',
            ], 403);
        }

        return $next($request);
    }
}
