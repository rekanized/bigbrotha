<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureAdminUser
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        abort_unless($user instanceof User, Response::HTTP_FORBIDDEN);

        if (!$user->isAdmin() && !User::query()->where('is_admin', true)->exists()) {
            $user->forceFill(['is_admin' => true])->save();
            $user->refresh();
        }

        abort_unless($user->isAdmin(), Response::HTTP_FORBIDDEN);

        return $next($request);
    }
}