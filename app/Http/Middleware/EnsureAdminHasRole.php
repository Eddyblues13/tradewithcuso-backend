<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Route-level role gate, used as `admin.role:owner`.
 */
class EnsureAdminHasRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $admin = $request->user();

        if (! $admin || ! in_array($admin->role, $roles, true)) {
            return response()->json([
                'message' => 'You do not have permission to do that.',
            ], 403);
        }

        return $next($request);
    }
}
