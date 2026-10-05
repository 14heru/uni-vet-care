<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserHasRole
{
    /**
     * @var list<string>
     */
    private const CANONICAL_ROLES = ['owner', 'admin', 'doctor', 'customer'];

    /**
     * Ensure the authenticated user's persisted role is one of the allowed roles.
     *
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $allowedRoles = array_intersect($roles, self::CANONICAL_ROLES);
        $actualRole = $request->user()?->role?->name;

        abort_unless($actualRole !== null && in_array($actualRole, $allowedRoles, true), Response::HTTP_FORBIDDEN);

        return $next($request);
    }
}
