<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureWalletIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null) {
            abort(401, 'Authentication required.');
        }

        $wallet = $user->wallet;

        if ($wallet === null) {
            abort(403, 'No wallet is available for this account.');
        }

        if (! $wallet->canTransact()) {
            abort(403, 'Your wallet is currently unavailable for transactions.');
        }

        return $next($request);
    }
}
