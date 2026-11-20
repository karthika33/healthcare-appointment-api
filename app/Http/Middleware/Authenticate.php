<?php

namespace App\Http\Middleware;

use Illuminate\Auth\Middleware\Authenticate as Middleware;
use Illuminate\Http\Request;

class Authenticate extends Middleware
{
    /**
     * API requests get a 401 JSON response instead of a redirect to a login page.
     */
    protected function redirectTo(Request $request): ?string
    {
        return ($request->expectsJson() || $request->is('api/*')) ? null : route('login');
    }
}
