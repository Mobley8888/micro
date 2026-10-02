<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserHasRole
{
    /** @param array<int, string> $roles */
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();
        abort_unless($user instanceof User && collect($roles)->contains(fn (string $role): bool => $user->hasRole($role)), 403);

        return $next($request);
    }
}
