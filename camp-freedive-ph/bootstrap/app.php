<?php

use App\Http\Middleware\EnsureAccountActive;
use App\Http\Middleware\EnsurePasswordChanged;
use App\Http\Middleware\EnsureUserRole;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->trustProxies(
            at: '*',
            headers: Request::HEADER_X_FORWARDED_ALL,
        );

        $middleware->alias([
            'role' => EnsureUserRole::class,
            'active' => EnsureAccountActive::class,
            'must_change_password' => EnsurePasswordChanged::class,
            'auth.ml_token' => \App\Http\Middleware\VerifyMLToken::class,
        ]);


        $middleware->validateCsrfTokens(except: [
            'api/webhooks/paymongo',
            'webhooks/paymongo',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        $exceptions->render(function (\Illuminate\Http\Exceptions\ThrottleRequestsException $e, Request $request) {
            $retryAfter = $e->getHeaders()['Retry-After'] ?? 60;
            $headers = [
                'Retry-After' => $retryAfter,
                'X-RateLimit-Reset' => now()->addSeconds((int) $retryAfter)->getTimestamp(),
            ];

            if ($request->is('api/*') || $request->expectsJson() || $request->wantsJson()) {
                return response()->json([
                    'error' => 'Too many requests. Please try again later.',
                ], 429, $headers);
            }

            return back()
                ->withInput()
                ->with('error', "Too many requests. Please try again in {$retryAfter} seconds.")
                ->withHeaders($headers);
        });
    })->create();
