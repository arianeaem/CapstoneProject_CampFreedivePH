<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class VerifyMLToken
{
    /**
     * Handle an incoming request for ML API endpoints.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $expectedToken = config('services.ml.token', '');

        // Extract token from Bearer header, X-ML-Secret-Key header, or request parameter
        $token = $request->bearerToken() 
            ?: $request->header('X-ML-Secret-Key') 
            ?: $request->input('token') 
            ?: $request->input('api_key');

        if (!$token || !hash_equals((string) $expectedToken, (string) $token)) {
            return response()->json([
                'status' => 'error',
                'message' => 'Unauthorized: Invalid or missing ML API token.',
            ], 401);
        }

        return $next($request);
    }
}
