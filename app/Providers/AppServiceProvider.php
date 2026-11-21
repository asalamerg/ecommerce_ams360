<?php

namespace App\Providers;

use App\Jobs\SendBackInStockAlerts;
use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use App\Policies\CategoryPolicy;
use App\Policies\ProductPolicy;
use App\Support\StorefrontCache;
use App\Support\TrustedProxies;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Events\DiagnosingHealth;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

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
        TrustProxies::at(TrustedProxies::addresses(config('store.trusted_proxies')));
        StorefrontCache::watchModels();

        // Back in stock: tell everyone who asked, once the change is committed.
        Product::updated(function (Product $product): void {
            if ($product->wasChanged('stock_quantity') && (int) $product->getOriginal('stock_quantity') <= 0 && $product->stock_quantity > 0) {
                SendBackInStockAlerts::dispatch($product->id)->afterCommit();
            }
        });

        Gate::policy(Product::class, ProductPolicy::class);
        Gate::policy(Category::class, CategoryPolicy::class);
        Gate::define('manage-store', fn (User $user): bool => $user->role->canManageAdministration());
        Gate::define('manage-orders', fn (User $user): bool => $user->role->canManageOrders());

        RateLimiter::for('cart', fn (Request $request): Limit => Limit::perMinute(60)->by($request->user()?->id ?: $request->ip()));
        RateLimiter::for('checkout', fn (Request $request): array => [
            Limit::perMinute(5)->by('minute:'.($request->user()?->id ?: $request->ip())),
            Limit::perHour(20)->by('hour:'.($request->user()?->id ?: $request->ip())),
        ]);
        RateLimiter::for('search', fn (Request $request): Limit => Limit::perMinute(120)->by($request->ip()));

        // Each limit has its own name, and so its own counter. Laravel's unnamed
        // "throttle:6,1" shares one counter per visitor across every route using
        // one, so a customer coming back from the payment page or a browser
        // sending CSP reports used up the allowance of unrelated forms.
        $perVisitor = fn (string $name, int $perMinute): Limit => Limit::perMinute($perMinute)->by($name.':'.request()->ip());
        RateLimiter::for('stock-alerts', fn (): Limit => $perVisitor('stock-alerts', 5));
        RateLimiter::for('track', fn (): Limit => $perVisitor('track', 6));
        // Messages reach staff, so a visitor sends a few at most.
        RateLimiter::for('contact', fn (): array => [$perVisitor('contact', 3), Limit::perDay(20)->by('contact-day:'.request()->ip())]);
        RateLimiter::for('repairs', fn (): array => [$perVisitor('repairs', 3), Limit::perDay(10)->by('repairs-day:'.request()->ip())]);
        RateLimiter::for('tickets', fn (Request $request): Limit => Limit::perMinutes(10, 5)->by('tickets:'.($request->user()?->id ?: $request->ip())));
        RateLimiter::for('payment-return', fn (): Limit => $perVisitor('payment-return', 30));
        RateLimiter::for('payment-webhook', fn (): Limit => $perVisitor('payment-webhook', 120));
        RateLimiter::for('csp-report', fn (): Limit => $perVisitor('csp-report', 20));
        RateLimiter::for('reviews', fn (Request $request): Limit => Limit::perMinutes(10, 5)->by('reviews:'.($request->user()?->id ?: $request->ip())));
        RateLimiter::for('favorites', fn (Request $request): Limit => Limit::perMinute(30)->by('favorites:'.($request->user()?->id ?: $request->ip())));
        RateLimiter::for('password-change', fn (Request $request): Limit => Limit::perMinute(6)->by('password-change:'.($request->user()?->id ?: $request->ip())));
        RateLimiter::for('imports', fn (Request $request): Limit => Limit::perMinute(10)->by('imports:'.($request->user()?->id ?: $request->ip())));

        // /up (the container health check and any uptime monitor) fails when the
        // database or the cache store cannot be reached, not only when PHP is down.
        Event::listen(DiagnosingHealth::class, function (): void {
            DB::select('select 1');
            Cache::get('store.health');
        });

        $this->configureDefaults();
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        // Links in e-mails (password reset, verification) always use APP_URL.
        if ($this->app->isProduction()) {
            URL::forceRootUrl((string) config('app.url'));
            URL::forceScheme('https');
        }

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
