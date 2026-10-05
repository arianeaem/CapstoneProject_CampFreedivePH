<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Load the settings once per request/job, not once per setting
        $this->app->scoped(\App\Services\SystemSettingService::class);

        if (file_exists(app_path('Helpers/portal_helpers.php'))) {
            require_once app_path('Helpers/portal_helpers.php');
        }

        $this->app->bind(
            \App\Contracts\PaymentGatewayInterface::class,
            \App\Services\Gateways\PayMongoGateway::class
        );

        $this->app->singleton('url', function ($app) {
            $routes = $app['router']->getRoutes();
            $app->instance('routes', $routes);
            return new \App\Routing\RoleAwareUrlGenerator(
                $routes,
                $app->rebinding('request', function ($app, $request) {
                    $app['url']->setRequest($request);
                }),
                $app['config']['app.asset_url']
            );
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        \Illuminate\Pagination\Paginator::defaultView('vendor.pagination.custom');
        \Illuminate\Pagination\Paginator::defaultSimpleView('vendor.pagination.custom');

        $this->configureRateLimiting();
    }

    /**
     * Rate limits for the app.
     */
    protected function configureRateLimiting(): void
    {
        $formatResponse = function (\Illuminate\Http\Request $request, array $headers) {
            $retryAfter = $headers['Retry-After'] ?? 60;
            $responseHeaders = array_merge($headers, [
                'Retry-After' => $retryAfter,
            ]);

            if ($request->expectsJson() || $request->is('api/*') || $request->wantsJson()) {
                return response()->json([
                    'error' => 'Too many requests. Please try again later.',
                ], 429, $responseHeaders);
            }

            return back()
                ->withInput()
                ->with('error', "Too many requests. Please try again in {$retryAfter} seconds.")
                ->withHeaders($responseHeaders);
        };

        // 1. General API and public pages
        \Illuminate\Support\Facades\RateLimiter::for('api', function (\Illuminate\Http\Request $request) use ($formatResponse) {
            $decay = (int) config('rate_limits.general_api.decay_minutes', 15);
            $max = (int) config('rate_limits.general_api.max_attempts', 100);
            $key = (string) ($request->user()?->id ?: $request->ip());

            return \Illuminate\Cache\RateLimiting\Limit::perMinutes($decay, $max)->by($key)->response($formatResponse);
        });

        // 2. Login (per email + IP, or per IP)
        \Illuminate\Support\Facades\RateLimiter::for('login', function (\Illuminate\Http\Request $request) use ($formatResponse) {
            $decay = (int) config('rate_limits.login.decay_minutes', 15);
            $max = (int) config('rate_limits.login.max_attempts', 10);
            $email = (string) $request->input('email');
            $key = $email ? \Illuminate\Support\Str::transliterate(\Illuminate\Support\Str::lower($email) . '|' . $request->ip()) : $request->ip();

            return \Illuminate\Cache\RateLimiting\Limit::perMinutes($decay, $max)->by($key)->response($formatResponse);
        });

        // 3. Password reset
        \Illuminate\Support\Facades\RateLimiter::for('password_reset', function (\Illuminate\Http\Request $request) use ($formatResponse) {
            $decay = (int) config('rate_limits.password_reset.decay_minutes', 15);
            $max = (int) config('rate_limits.password_reset.max_attempts', 5);

            return \Illuminate\Cache\RateLimiting\Limit::perMinutes($decay, $max)->by($request->ip())->response($formatResponse);
        });

        // 4. Creating bookings / holding slots
        \Illuminate\Support\Facades\RateLimiter::for('booking_create', function (\Illuminate\Http\Request $request) use ($formatResponse) {
            $decay = (int) config('rate_limits.booking_create.decay_minutes', 15);
            $max = (int) config('rate_limits.booking_create.max_attempts', 10);

            return \Illuminate\Cache\RateLimiting\Limit::perMinutes($decay, $max)->by($request->ip())->response($formatResponse);
        });

        // 5. Price quotes and weather checks
        \Illuminate\Support\Facades\RateLimiter::for('booking_quote_weather', function (\Illuminate\Http\Request $request) use ($formatResponse) {
            $decay = (int) config('rate_limits.booking_quote_weather.decay_minutes', 1);
            $max = (int) config('rate_limits.booking_quote_weather.max_attempts', 60);

            return \Illuminate\Cache\RateLimiting\Limit::perMinutes($decay, $max)->by($request->ip())->response($formatResponse);
        });

        // 6. Manage Booking PIN lookup
        \Illuminate\Support\Facades\RateLimiter::for('manage_lookup', function (\Illuminate\Http\Request $request) use ($formatResponse) {
            $decay = (int) config('rate_limits.manage_lookup.decay_minutes', 15);
            $max = (int) config('rate_limits.manage_lookup.max_attempts', 10);

            return \Illuminate\Cache\RateLimiting\Limit::perMinutes($decay, $max)->by($request->ip())->response($formatResponse);
        });

        // 7. Reschedule / cancel requests
        \Illuminate\Support\Facades\RateLimiter::for('manage_requests', function (\Illuminate\Http\Request $request) use ($formatResponse) {
            $decay = (int) config('rate_limits.manage_requests.decay_minutes', 15);
            $max = (int) config('rate_limits.manage_requests.max_attempts', 5);

            return \Illuminate\Cache\RateLimiting\Limit::perMinutes($decay, $max)->by($request->ip())->response($formatResponse);
        });

        // 8. PayMongo checkout
        \Illuminate\Support\Facades\RateLimiter::for('paymongo_checkout', function (\Illuminate\Http\Request $request) use ($formatResponse) {
            $decay = (int) config('rate_limits.paymongo_checkout.decay_minutes', 15);
            $max = (int) config('rate_limits.paymongo_checkout.max_attempts', 10);

            return \Illuminate\Cache\RateLimiting\Limit::perMinutes($decay, $max)->by($request->ip())->response($formatResponse);
        });

        // 9. PayMongo webhook
        \Illuminate\Support\Facades\RateLimiter::for('paymongo_webhook', function (\Illuminate\Http\Request $request) use ($formatResponse) {
            $decay = (int) config('rate_limits.paymongo_webhook.decay_minutes', 1);
            $max = (int) config('rate_limits.paymongo_webhook.max_attempts', 120);

            return \Illuminate\Cache\RateLimiting\Limit::perMinutes($decay, $max)->by($request->ip())->response($formatResponse);
        });

        // 10. Weather forecast sync
        \Illuminate\Support\Facades\RateLimiter::for('weather_sync', function (\Illuminate\Http\Request $request) use ($formatResponse) {
            $decay = (int) config('rate_limits.weather_sync.decay_minutes', 15);
            $max = (int) config('rate_limits.weather_sync.max_attempts', 10);
            $key = (string) ($request->user()?->id ?: $request->ip());

            return \Illuminate\Cache\RateLimiting\Limit::perMinutes($decay, $max)->by($key)->response($formatResponse);
        });

        // 11. ML API
        \Illuminate\Support\Facades\RateLimiter::for('ml_api', function (\Illuminate\Http\Request $request) use ($formatResponse) {
            $decay = (int) config('rate_limits.ml_api.decay_minutes', 1);
            $max = (int) config('rate_limits.ml_api.max_attempts', 60);
            $token = $request->bearerToken() ?: $request->header('X-ML-Secret-Key');
            $key = $token ? hash('sha256', (string) $token) : $request->ip();

            return \Illuminate\Cache\RateLimiting\Limit::perMinutes($decay, $max)->by($key)->response($formatResponse);
        });
    }

}
