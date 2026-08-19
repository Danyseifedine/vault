<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;
use Symfony\Component\HttpFoundation\Response;

class HandleAppearance
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Dark, not the operating system's preference: this tool is read in
        // terminals-and-editors company, and the first paint should match.
        View::share('appearance', $request->cookie('appearance') ?? 'dark');

        return $next($request);
    }
}
