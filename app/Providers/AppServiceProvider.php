<?php

namespace App\Providers;

use App\Services\Structure\StructureScope;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Per-request cache of the user's factory data scope.
        $this->app->scoped(StructureScope::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Password::defaults(function () {
            $rule = Password::min(10)->letters()->mixedCase()->numbers()->symbols()->max(128);

            return $this->app->isProduction() ? $rule->uncompromised() : $rule;
        });

        // Per-IP ceiling; per email+IP lockout is enforced in AuthController.
        RateLimiter::for('login', fn (Request $request) => Limit::perMinute(20)->by($request->ip()));

        RateLimiter::for('password-reset', fn (Request $request) => [
            Limit::perMinute(5)->by('pw-ip:'.$request->ip()),
            Limit::perHour(10)->by('pw-email:'.Str::lower((string) $request->input('email'))),
        ]);
    }
}
