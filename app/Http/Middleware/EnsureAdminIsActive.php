<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Runs after `auth:admin`. A deactivated account keeps whatever tokens it was
 * issued, so the check has to happen per request — and the tokens are burned
 * on the way out so the session cannot continue.
 */
class EnsureAdminIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $admin = $request->user();

        if (! $admin || ! $admin->is_active) {
            $admin?->tokens()->delete();

            return response()->json([
                'message' => 'This admin account has been deactivated.',
            ], 403);
        }

        return $next($request);
    }
}
