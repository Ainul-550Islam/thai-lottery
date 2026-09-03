<?php

namespace App\Http\Middleware;

use App\Models\Draw;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureDrawIsOpen
{
    public function handle(Request $request, Closure $next): Response
    {
        $draw = $request->route('draw');

        if (! $draw instanceof Draw) {
            $drawId = $draw ?? $request->input('draw_id');
            $draw = $drawId ? Draw::find($drawId) : null;
        }

        if ($draw === null) {
            abort(404, 'Draw not found.');
        }

        if (! $draw->canAcceptBets()) {
            abort(422, 'This draw is closed and can no longer accept bets.');
        }

        return $next($request);
    }
}
