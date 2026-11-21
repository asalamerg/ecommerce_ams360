<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\CartManager;
use App\Support\AdminNavigation;
use App\Support\StorefrontFooter;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Server-side rendering is for search engines and the first paint of public
     * pages. Signed-in staff and account screens gain nothing from it.
     *
     * @var array<int, string>
     */
    protected $withoutSsr = ['admin', 'admin/*', 'settings', 'settings/*', 'dashboard'];

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $user = $request->user();
        $locale = app()->getLocale();

        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'auth' => [
                'user' => $user ? [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'email_verified_at' => $user->email_verified_at,
                    'role' => $user->role->value,
                    'is_staff' => $user->role->isStaff(),
                ] : null,
            ],
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
                // The row just saved, highlighted when the admin lands back on its list.
                'highlight' => fn () => $request->session()->get('highlight'),
            ],
            'locale' => $locale,
            'direction' => in_array($locale, SetStoreLocale::RTL, true) ? 'rtl' : 'ltr',
            // The interface text is the same on every page, so the browser keeps
            // it after the first visit instead of downloading it on every click.
            // Each language sits under its own key (ui.ar, ui.en...), so switching
            // language never shows the text kept for another one. The once-key
            // changes when the file is edited, so open tabs fetch the new text.
            'ui' => [
                $locale => Inertia::once(fn () => trans('ui'))
                    ->as("ui.{$locale}.".$this->textStamp(lang_path("{$locale}/ui.php"))),
            ],
            'admin' => fn () => $this->isAdminPanel($request, $user) ? AdminNavigation::for($user) : null,
            // Also the account settings screens (profile, password, two-factor).
            'adminText' => $this->isAdminPanel($request, $user) || ($user instanceof User && $request->is('settings', 'settings/*', 'user/*'))
                ? [
                    $locale => Inertia::once(fn () => $this->adminText($locale))
                        ->as("adminText.{$locale}.".$this->textStamp(lang_path("{$locale}.json"))),
                ]
                : [],
            'cart' => fn () => $this->isStorefront($request) ? app(CartManager::class)->summary($locale) : null,
            'footer' => fn () => $this->isStorefront($request) ? StorefrontFooter::for($locale) : null,
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
        ];
    }

    private function isStorefront(Request $request): bool
    {
        return $request->route('locale') !== null;
    }

    private function isAdminPanel(Request $request, mixed $user): bool
    {
        return $user instanceof User && $user->role->isStaff() && $request->is('admin', 'admin/*');
    }

    /**
     * Changes when a translation file is edited, so browsers fetch the new text.
     */
    private function textStamp(string $path): string
    {
        return is_file($path) ? (string) filemtime($path) : '0';
    }

    /**
     * The same lang/{locale}.json strings that __() reads on the server.
     *
     * @return array<string, string>
     */
    private function adminText(string $locale): array
    {
        return app('translator')->getLoader()->load($locale, '*', '*');
    }
}
