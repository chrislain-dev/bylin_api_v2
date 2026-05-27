<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ensure the authenticated user can access the admin back-office.
 */
class EnsureUserIsAdmin
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated',
            ], 401);
        }

        if ($user->hasRole('super_admin') || $user->can('admin.access')) {
            return $next($request);
        }

        return response()->json([
            'success' => false,
            'message' => 'Forbidden. Admin access required.',
        ], 403);
    }
}
