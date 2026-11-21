<?php

use App\Http\Middleware\ContentSecurityPolicy;
use App\Http\Middleware\EndIdleStaffSession;
use App\Http\Middleware\EnsureAccountIsActive;
use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\SetStoreLocale;
use App\Http\Middleware\ShareStoreTheme;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Sentry\Laravel\Integration;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->encryptCookies(except: ['appearance', 'sidebar_state']);

        // A forged Host header could otherwise put another domain into
        // password-reset links. Only the APP_URL host and its subdomains (www)
        // are accepted; Laravel skips the check in local development and tests.
        // 127.0.0.1 is the container's own health check (docker/Caddyfile), which
        // Caddy serves only on the loopback listener, so no outside request can
        // reach PHP with that name. Without it every health check got a 400.
        $middleware->trustHosts(at: ['^127\.0\.0\.1$']);

        // Behind Cloudflare every request arrives from a Cloudflare address. Only
        // those addresses may say who the visitor is (X-Forwarded-For), so rate
        // limits keep counting real visitors and a direct hit cannot fake an IP.
        // The addresses come from config('store.trusted_proxies'), set in AppServiceProvider.
        $middleware->trustProxies(headers: Request::HEADER_X_FORWARDED_FOR | Request::HEADER_X_FORWARDED_PROTO);

        $middleware->redirectGuestsTo(fn (Request $request): string => $request->is('admin', 'admin/*')
            ? route('admin.login')
            : route('login'));

        $middleware->web(append: [
            ContentSecurityPolicy::class,
            SetStoreLocale::class,
            EnsureAccountIsActive::class,
            EndIdleStaffSession::class,
            ShareStoreTheme::class,
            HandleAppearance::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Reports to Sentry only when SENTRY_LARAVEL_DSN is set.
        Integration::handles($exceptions);

        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
