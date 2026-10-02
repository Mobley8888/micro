<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserHasPermission
{
    public function handle(Request $request, Closure $next, string $permission): Response
    {
        $user = $request->user();
        $hasPermission = $user instanceof User
            && collect(explode('|', $permission))->contains(
                fn (string $requiredPermission): bool => $user->hasPermission($requiredPermission),
            );

        abort_unless($hasPermission, 403);

        return $next($request);
    }
}
