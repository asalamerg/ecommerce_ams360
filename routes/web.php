<?php

use App\Http\Controllers\AccountController;
use App\Http\Controllers\AccountHomeController;
use App\Http\Controllers\Admin\AdminLoginController;
use App\Http\Controllers\Admin\BrandLogoController;
use App\Http\Controllers\Admin\CatalogController;
use App\Http\Controllers\Admin\DashboardController as StoreDashboardController;
use App\Http\Controllers\Admin\InventoryController;
use App\Http\Controllers\Admin\NotificationController;
use App\Http\Controllers\Admin\OrderController;
use App\Http\Controllers\Admin\ReportController;
use App\Http\Controllers\Admin\StoreModuleController;
use App\Http\Controllers\Admin\ThemeController;
use App\Http\Controllers\CartController;
use App\Http\Controllers\CheckoutController;
use App\Http\Controllers\CspReportController;
use App\Http\Controllers\CustomerFeaturesController;
use App\Http\Controllers\CustomerServiceController;
use App\Http\Controllers\ManifestController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\SitemapController;
use App\Http\Controllers\StorefrontController;
use App\Http\Controllers\TrackOrderController;
use App\Http\Middleware\ConfirmRecentPassword;
use App\Http\Middleware\EnsureCatalogManager;
use App\Http\Middleware\EnsureInventoryManager;
use App\Http\Middleware\EnsureStoreAdminAccess;
use App\Http\Middleware\EnsureStoreAdminFeature;
use App\Http\Middleware\RequireStaffTwoFactor;
use App\Support\StoreAdminModules;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Route;
use Laravel\Fortify\Http\Controllers\AuthenticatedSessionController;
use Laravel\Fortify\Http\Controllers\PasswordResetLinkController;
use Laravel\Fortify\Http\Controllers\RegisteredUserController;

Route::get('/', fn () => to_route('storefront.home', ['locale' => App::getLocale()]))->name('home');

Route::get('sitemap.xml', [SitemapController::class, 'sitemap'])->name('sitemap');
Route::get('robots.txt', [SitemapController::class, 'robots'])->name('robots');
Route::get('manifest.webmanifest', ManifestController::class)->name('manifest');
Route::get('icons/app-{size}.png', [ManifestController::class, 'icon'])->whereIn('size', ['192', '512'])->name('app-icon');
Route::get('icons/app-{size}-maskable.png', [ManifestController::class, 'maskableIcon'])->whereIn('size', ['192', '512'])->name('app-icon.maskable');

Route::get('dashboard', AccountHomeController::class)->middleware('auth')->name('dashboard');

Route::pattern('locale', 'ar|en|he');

Route::prefix('{locale}')
    ->group(function () {
        Route::get('/', [StorefrontController::class, 'index'])->name('storefront.home');
        Route::get('products', [StorefrontController::class, 'products'])->name('storefront.products.index');
        Route::get('products/{slug}', [StorefrontController::class, 'show'])->name('storefront.products.show');
        Route::get('categories', [StorefrontController::class, 'categories'])->name('storefront.categories.index');
        Route::get('categories/{slug}', [StorefrontController::class, 'category'])->name('storefront.categories.show');
        Route::get('brands', [StorefrontController::class, 'brands'])->name('storefront.brands.index');
        // The topbar's brand menu loads this when first opened, so pages stay light.
        Route::get('brands/menu', [StorefrontController::class, 'brandMenu'])->middleware('throttle:search')->name('storefront.brands.menu');
        Route::get('brands/{slug}', [StorefrontController::class, 'brand'])->where('slug', '[A-Za-z0-9_-]+')->name('storefront.brands.show');
        Route::get('pages/{slug}', [StorefrontController::class, 'page'])->name('storefront.pages.show');
        Route::get('offers/{offer}', [StorefrontController::class, 'offer'])->whereNumber('offer')->name('storefront.offers.show');
        Route::get('contact', [CustomerServiceController::class, 'contact'])->name('storefront.contact');
        Route::post('contact', [CustomerServiceController::class, 'sendContact'])->middleware('throttle:contact')->name('storefront.contact.send');
        Route::get('repairs', [CustomerServiceController::class, 'repairs'])->name('storefront.repairs');
        Route::post('repairs', [CustomerServiceController::class, 'storeRepair'])->middleware('throttle:repairs')->name('storefront.repairs.store');
        Route::get('sim-plans', [CustomerServiceController::class, 'simPlans'])->name('storefront.sim-plans');
        Route::get('cart', [CartController::class, 'show'])->name('storefront.cart');
        Route::get('orders/{reference}', [CheckoutController::class, 'show'])->name('storefront.orders.show');
        Route::post('orders/{reference}/pay', [PaymentController::class, 'pay'])->middleware('throttle:checkout')->name('storefront.orders.pay');
        Route::post('orders/{reference}/reorder', [CustomerFeaturesController::class, 'reorder'])->middleware('throttle:cart')->name('storefront.orders.reorder');
        Route::post('products/{product}/notify', [CustomerFeaturesController::class, 'notifyWhenAvailable'])
            ->whereNumber('product')->middleware('throttle:stock-alerts')->name('storefront.products.notify');
        Route::post('products/{product}/reviews', [CustomerFeaturesController::class, 'review'])
            ->whereNumber('product')->middleware(['auth', 'throttle:reviews'])->name('storefront.products.reviews');
        Route::get('track', [TrackOrderController::class, 'show'])->name('storefront.track');
        Route::post('track', [TrackOrderController::class, 'find'])->middleware('throttle:track')->name('storefront.track.find');
        Route::get('search/suggest', [StorefrontController::class, 'suggest'])->middleware('throttle:search')->name('storefront.search.suggest');
        Route::get('favorites', [StorefrontController::class, 'favorites'])->name('storefront.favorites');
        Route::get('compare', [StorefrontController::class, 'compare'])->name('storefront.compare');
        Route::get('compare/products', [StorefrontController::class, 'compareProducts'])->middleware('throttle:search')->name('storefront.compare.products');
        Route::get('favorites/products', [StorefrontController::class, 'favoriteProducts'])->middleware('throttle:search')->name('storefront.favorites.products');

        Route::middleware('auth')->group(function () {
            Route::get('account', [AccountController::class, 'show'])->name('storefront.account');
            Route::post('account/tickets', [CustomerServiceController::class, 'storeTicket'])->middleware('throttle:tickets')->name('storefront.account.tickets');
            Route::put('account', [AccountController::class, 'update'])->name('storefront.account.update');
            Route::put('account/password', [AccountController::class, 'updatePassword'])
                ->middleware('throttle:password-change')
                ->name('storefront.account.password');
        });
    });

Route::middleware('throttle:cart')->prefix('cart/items')->name('cart.items.')->group(function () {
    Route::post('/', [CartController::class, 'store'])->name('store');
    Route::patch('{product}', [CartController::class, 'update'])->whereNumber('product')->name('update');
    Route::delete('{product}', [CartController::class, 'destroy'])->whereNumber('product')->name('destroy');
});

Route::middleware('throttle:cart')->group(function () {
    Route::post('cart/coupon', [CheckoutController::class, 'applyCoupon'])->name('cart.coupon.store');
    Route::delete('cart/coupon', [CheckoutController::class, 'removeCoupon'])->name('cart.coupon.destroy');
});

Route::post('account/favorites/sync', [CustomerFeaturesController::class, 'syncFavorites'])
    ->middleware(['auth', 'throttle:favorites'])->name('account.favorites.sync');

Route::post('checkout', [CheckoutController::class, 'store'])->middleware('throttle:checkout')->name('checkout.store');

// Online payments. The customer's return carries a secret token; notifications
// from the provider are signed instead of carrying a CSRF token.
Route::get('payments/return/{payment}/{token}', [PaymentController::class, 'return'])
    ->whereNumber('payment')
    ->middleware('throttle:payment-return')
    ->name('payments.return');
Route::post('payments/webhook/{gateway}', [PaymentController::class, 'webhook'])
    ->whereAlphaNumeric('gateway')
    ->withoutMiddleware(PreventRequestForgery::class)
    ->middleware('throttle:payment-webhook')
    ->name('payments.webhook');
// The test gateway's own pages, never available in production (see PaymentManager).
Route::middleware('signed')->group(function () {
    Route::get('payments/fake/{reference}', [PaymentController::class, 'fakeShow'])->name('payments.fake.show');
    Route::post('payments/fake/{reference}', [PaymentController::class, 'fakeDecide'])->name('payments.fake.decide');
});

// Browsers post here without a CSRF token when the Content Security Policy blocks something.
Route::post('csp-report', CspReportController::class)
    ->withoutMiddleware(PreventRequestForgery::class)
    ->middleware('throttle:csp-report')
    ->name('csp.report');

// Registered after Fortify, so these replace its unthrottled POST routes of the same URI.
Route::middleware(['guest:'.config('fortify.guard'), 'throttle:auth-forms'])->group(function () {
    Route::post('register', [RegisteredUserController::class, 'store'])->name('register.store');
    Route::post('forgot-password', [PasswordResetLinkController::class, 'store'])->name('password.email');
});

Route::middleware('guest:'.config('fortify.guard'))->prefix('admin')->name('admin.')->group(function () {
    Route::get('login', AdminLoginController::class)->name('login');
    Route::post('login', [AuthenticatedSessionController::class, 'store'])
        ->middleware('throttle:login')
        ->name('login.store');
});

Route::prefix('admin')
    ->name('admin.')
    ->middleware(['auth', 'verified', RequireStaffTwoFactor::class, EnsureCatalogManager::class])
    ->group(function () {
        Route::get('activity', [CatalogController::class, 'activity'])->name('activity.index');
        Route::get('products', [CatalogController::class, 'products'])->name('products.index');
        Route::get('products/create', [CatalogController::class, 'createProduct'])->name('products.create');
        Route::post('products/bulk', [CatalogController::class, 'bulkProducts'])->name('products.bulk');
        Route::get('products/export', [CatalogController::class, 'exportProducts'])->name('products.export');
        Route::post('products/import', [CatalogController::class, 'importProducts'])->middleware('throttle:imports')->name('products.import');
        Route::post('products', [CatalogController::class, 'storeProduct'])->name('products.store');
        Route::get('products/{product}/edit', [CatalogController::class, 'editProduct'])->name('products.edit');
        Route::put('products/{product}', [CatalogController::class, 'updateProduct'])->name('products.update');
        Route::patch('products/{product}/visibility', [CatalogController::class, 'toggleProductVisibility'])->name('products.visibility');
        Route::delete('products/{product}', [CatalogController::class, 'deleteProduct'])->middleware(ConfirmRecentPassword::class)->name('products.destroy');

        Route::get('brands/logos', [BrandLogoController::class, 'index'])->name('brands.logos');
        Route::post('brands/{brand}/logo', [BrandLogoController::class, 'store'])->whereNumber('brand')->name('brands.logo.store');
        Route::delete('brands/{brand}/logo', [BrandLogoController::class, 'destroy'])->whereNumber('brand')->name('brands.logo.destroy');

        Route::get('categories', [CatalogController::class, 'categories'])->name('categories.index');
        Route::get('categories/create', [CatalogController::class, 'createCategory'])->name('categories.create');
        Route::post('categories', [CatalogController::class, 'storeCategory'])->name('categories.store');
        Route::get('categories/{category}/edit', [CatalogController::class, 'editCategory'])->name('categories.edit');
        Route::put('categories/{category}', [CatalogController::class, 'updateCategory'])->name('categories.update');
        Route::patch('categories/{category}/visibility', [CatalogController::class, 'toggleCategoryVisibility'])->name('categories.visibility');
        Route::delete('categories/{category}', [CatalogController::class, 'deleteCategory'])->middleware(ConfirmRecentPassword::class)->name('categories.destroy');
    });

Route::prefix('admin')
    ->name('admin.')
    ->middleware(['auth', 'verified', RequireStaffTwoFactor::class, EnsureInventoryManager::class])
    ->group(function () {
        Route::get('inventory', [InventoryController::class, 'index'])->name('inventory.index');
        Route::post('inventory/{product}/adjust', [InventoryController::class, 'adjust'])->name('inventory.adjust');
    });

Route::prefix('admin')
    ->name('admin.')
    ->middleware(['auth', 'verified', RequireStaffTwoFactor::class, EnsureStoreAdminAccess::class])
    ->group(function () {
        Route::get('/', fn () => to_route('admin.dashboard'))->name('home');
        Route::get('dashboard', [StoreDashboardController::class, 'index'])->name('dashboard');
        Route::get('reports', [ReportController::class, 'index'])->middleware('can:manage-store')->name('reports.index');
        Route::get('reports/orders.csv', [ReportController::class, 'exportOrders'])
            ->middleware(['can:manage-store', ConfirmRecentPassword::class])->name('reports.orders');
        Route::get('guide', [StoreDashboardController::class, 'guide'])->name('guide');
        Route::get('notifications/{notification}', [NotificationController::class, 'open'])->whereUuid('notification')->name('notifications.open');
        Route::post('notifications/read-all', [NotificationController::class, 'readAll'])->name('notifications.read-all');
    });

Route::prefix('admin/orders')
    ->name('admin.orders.')
    ->middleware(['auth', 'verified', RequireStaffTwoFactor::class, 'can:manage-orders'])
    ->controller(OrderController::class)
    ->group(function () {
        Route::get('/', 'index')->name('index');
        Route::post('bulk-status', 'bulk')->name('bulk');
        Route::get('{order}', 'show')->whereNumber('order')->name('show');
        Route::put('{order}', 'update')->whereNumber('order')->name('update');
        Route::post('{order}/notes', 'note')->whereNumber('order')->name('notes.store');
        Route::get('{order}/whatsapp', 'whatsapp')->whereNumber('order')->name('whatsapp');
        Route::get('{order}/invoice', 'invoice')->whereNumber('order')->name('invoice');
        Route::post('{order}/refund', 'refund')->whereNumber('order')
            ->middleware(['can:manage-store', ConfirmRecentPassword::class])->name('refund');
    });

// Orders had a generic module page before; old bookmarks and alerts still work.
Route::redirect('admin/modules/orders', '/admin/orders');
Route::get('admin/modules/orders/{order}/edit', fn (int $order) => redirect("/admin/orders/{$order}"))->whereNumber('order');

Route::prefix('admin/theme')
    ->name('admin.theme.')
    ->middleware(['auth', 'verified', RequireStaffTwoFactor::class, 'can:manage-store'])
    ->controller(ThemeController::class)
    ->group(function () {
        Route::get('/', 'index')->name('index');
        Route::put('draft', 'saveDraft')->name('draft');
        Route::post('discard', 'discard')->name('discard');
        Route::post('publish', 'publish')->name('publish');
        Route::post('saved', 'saveNamed')->name('saved.store');
        Route::post('schedules', 'schedule')->name('schedules.store');
        Route::post('snapshots/{snapshot}/apply', 'apply')->name('snapshots.apply');
        Route::delete('snapshots/{snapshot}', 'destroy')->name('snapshots.destroy');
        Route::get('preview', 'startPreview')->name('preview');
        Route::get('preview/exit', 'stopPreview')->name('preview.exit');
    });

Route::pattern('module', implode('|', array_keys(StoreAdminModules::all())));

Route::prefix('admin/modules/{module}')
    ->name('admin.modules.')
    ->middleware(['auth', 'verified', RequireStaffTwoFactor::class, EnsureStoreAdminFeature::class])
    ->group(function () {
        Route::get('/', [StoreModuleController::class, 'index'])->name('index');
        Route::get('create', [StoreModuleController::class, 'create'])->name('create');
        Route::post('/', [StoreModuleController::class, 'store'])->name('store');
        Route::get('{record}/edit', [StoreModuleController::class, 'edit'])->whereNumber('record')
            ->middleware(ConfirmRecentPassword::class.':'.implode(',', StoreAdminModules::SENSITIVE))->name('edit');
        Route::put('{record}', [StoreModuleController::class, 'update'])->whereNumber('record')
            ->middleware(ConfirmRecentPassword::class.':'.implode(',', StoreAdminModules::SENSITIVE))->name('update');
        Route::delete('{record}', [StoreModuleController::class, 'destroy'])->whereNumber('record')
            ->middleware(ConfirmRecentPassword::class)->name('destroy');
    });

require __DIR__.'/settings.php';
