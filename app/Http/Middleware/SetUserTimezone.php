<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class SetUserTimezone
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure(\Illuminate\Http\Request): (\Illuminate\Http\Response|\Illuminate\Http\RedirectResponse)  $next
     * @return \Illuminate\Http\Response|\Illuminate\Http\RedirectResponse
     */
    // app/Http/Middleware/SetUserTimezone.php
    public function handle($request, Closure $next) {
        if ($request->hasHeader('X-User-Timezone') && Auth::check()) {
            $timezone = $request->header('X-User-Timezone');
            Auth::user()->update(['timezone' => $timezone]);
        }

        return $next($request);
    }
}
