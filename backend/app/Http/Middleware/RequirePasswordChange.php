<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequirePasswordChange
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()?->must_change_password) {
            return response()->json([
                'code' => 'PASSWORD_CHANGE_REQUIRED',
                'message' => 'Ganti password awal sebelum menggunakan fitur lainnya.',
            ], 428);
        }

        return $next($request);
    }
}
