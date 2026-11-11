<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        RateLimiter::for('auth-login', function (Request $request) {
            $email = mb_strtolower(trim((string) $request->input('email')));

            return [
                Limit::perMinute(5)->by('auth-login:ip:'.$request->ip()),
                Limit::perMinute(5)->by('auth-login:email:'.hash('sha256', $email)),
            ];
        });

        RateLimiter::for('mobile-auth-login', function (Request $request) {
            $email = mb_strtolower(trim((string) $request->input('email')));

            return [
                Limit::perMinute(5)->by('mobile-auth-login:ip:'.$request->ip()),
                Limit::perMinute(5)->by('mobile-auth-login:email:'.hash('sha256', $email)),
            ];
        });

        RateLimiter::for('workspace-create', function (Request $request) {
            return [
                Limit::perMinute(3)->by('workspace-create:ip:'.$request->ip()),
                Limit::perMinute(3)->by('workspace-create:user:'.($request->user()?->getAuthIdentifier() ?? 'guest')),
            ];
        });

        RateLimiter::for('workspace-invitation-create', function (Request $request) {
            return [
                Limit::perMinute(10)->by('workspace-invitation-create:ip:'.$request->ip()),
                Limit::perMinute(10)->by(
                    'workspace-invitation-create:user:'.($request->user()?->getAuthIdentifier() ?? 'guest')
                ),
            ];
        });

        RateLimiter::for('workspace-invitation-response', function (Request $request) {
            $token = (string) $request->route('token');

            return [
                Limit::perMinute(10)->by('workspace-invitation-response:ip:'.$request->ip()),
                Limit::perMinute(10)->by('workspace-invitation-response:token:'.hash('sha256', $token)),
            ];
        });

        RateLimiter::for('auth-forgot-password', function (Request $request) {
            $email = mb_strtolower(trim((string) $request->input('email')));

            return [
                Limit::perMinute(5)->by('auth-forgot-password:ip:'.$request->ip()),
                Limit::perMinute(5)->by('auth-forgot-password:email:'.hash('sha256', $email)),
            ];
        });

        RateLimiter::for('auth-reset-password', function (Request $request) {
            $email = mb_strtolower(trim((string) $request->input('email')));

            return [
                Limit::perMinute(10)->by('auth-reset-password:ip:'.$request->ip()),
                Limit::perMinute(10)->by('auth-reset-password:email:'.hash('sha256', $email)),
            ];
        });
    }
}
