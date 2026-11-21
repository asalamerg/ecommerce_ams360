<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

class SetStoreLocale
{
    public const array SUPPORTED = ['ar', 'en', 'he'];

    public const array RTL = ['ar', 'he'];

    private const string DEFAULT = 'ar';

    public function handle(Request $request, Closure $next): Response
    {
        $routeLocale = $request->route('locale');

        abort_if(is_string($routeLocale) && ! $this->isSupported($routeLocale), 404);

        // The admin keeps its own language: staff who look at the English store
        // come back to the admin in the language they chose for it.
        $key = $request->is('admin', 'admin/*') ? 'admin_locale' : 'locale';

        $locale = collect([$routeLocale, $request->query('lang'), $request->session()->get($key)])
            ->first(fn (mixed $candidate): bool => $this->isSupported($candidate), self::DEFAULT);

        App::setLocale($locale);

        if ($request->session()->get($key) !== $locale) {
            $request->session()->put($key, $locale);
        }

        return $next($request);
    }

    private function isSupported(mixed $locale): bool
    {
        return is_string($locale) && in_array($locale, self::SUPPORTED, true);
    }
}
