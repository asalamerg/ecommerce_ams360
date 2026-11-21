<?php

namespace App\Http\Middleware;

use App\Services\ThemeManager;
use App\Support\Theme\ThemeCatalog;
use App\Support\Theme\ThemeStylesheet;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\View;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

class ShareStoreTheme
{
    public function __construct(private ThemeManager $themes)
    {
        //
    }

    public function handle(Request $request, Closure $next): Response
    {
        $previewing = $request->session()->get('theme_preview') === true
            && ($request->user()?->can('manage-store') ?? false);

        $theme = $previewing ? $this->themes->draft() : $this->themes->liveTheme();

        View::share('storeTheme', [
            'css' => ThemeStylesheet::css($theme),
            'allowsDark' => $theme['darkMode'],
            'themeColor' => $theme['colors']['topBar'],
            'favicon' => $this->favicon($theme),
        ]);

        Inertia::share([
            'themeAllowsDark' => $theme['darkMode'],
            'themePreview' => $previewing,
        ]);

        return $next($request);
    }

    /**
     * @param  array{preset: string, colors: array<string, string>, font: string, radius: string, darkMode: bool, logoFavicon: bool}  $theme
     */
    private function favicon(array $theme): string
    {
        $logo = $theme['logoFavicon']
            ? Cache::remember('store.logo-url', 60, fn () => DB::table('site_settings')->where('id', 1)->value('logo_url'))
            : null;

        if (is_string($logo) && $logo !== '') {
            return $logo;
        }

        $background = ThemeCatalog::isHexColor($theme['colors']['primary']) ? $theme['colors']['primary'] : '#000000';
        $foreground = ThemeCatalog::isHexColor($theme['colors']['onPrimary']) ? $theme['colors']['onPrimary'] : '#FFFFFF';
        $svg = "<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'><rect width='32' height='32' rx='8' fill='{$background}'/><path d='M18 3 7 18h7l-2 11 11-16h-7z' fill='{$foreground}'/></svg>";

        return 'data:image/svg+xml,'.rawurlencode($svg);
    }
}
