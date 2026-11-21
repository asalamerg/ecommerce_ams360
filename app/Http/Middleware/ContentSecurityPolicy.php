<?php

namespace App\Http\Middleware;

use App\Payments\PaymentManager;
use App\Rules\TurnstileToken;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sends a Content Security Policy with every HTML page, so an injected <script>
 * cannot run even if some text ever escapes React's escaping.
 *
 * Scripts must come from this site or carry the per-request nonce that Laravel's
 * Vite helper adds to every tag it prints. Styles still allow inline code: React,
 * Inertia's progress bar and toasts set styles at runtime, and the theme <style>
 * is built only from validated values. Images allow any https source because
 * brand logos and slides can be entered as links in the admin panel.
 */
class ContentSecurityPolicy
{
    public const string REPORT_PATH = '/csp-report';

    private const string TURNSTILE = 'https://challenges.cloudflare.com';

    public function handle(Request $request, Closure $next): Response
    {
        if (! config('store.csp.enabled')) {
            return $next($request);
        }

        $nonce = Vite::useCspNonce();
        $response = $next($request);

        // Laravel's debug error page runs inline scripts. It only exists with
        // APP_DEBUG on, which production never has.
        $debugErrorPage = config('app.debug') && $response->getStatusCode() >= 500;

        if ($debugErrorPage || ! str_contains((string) $response->headers->get('Content-Type'), 'text/html')) {
            return $response;
        }

        $header = config('store.csp.report_only')
            ? 'Content-Security-Policy-Report-Only'
            : 'Content-Security-Policy';

        $response->headers->set($header, $this->policy($request, $nonce));

        return $response;
    }

    private function policy(Request $request, string $nonce): string
    {
        $dev = $this->viteDevServer();
        $turnstile = TurnstileToken::enabled() ? [self::TURNSTILE] : [];
        // A hosted payment page may be shown in a frame or reached by a form post.
        $payments = app(PaymentManager::class)->browserOrigins();

        $directives = [
            'default-src' => ["'self'"],
            'script-src' => ["'self'", "'nonce-{$nonce}'", ...$turnstile, ...$dev],
            'style-src' => ["'self'", "'unsafe-inline'", ...$dev],
            'img-src' => ["'self'", 'data:', 'blob:', 'https:', ...$dev],
            'font-src' => ["'self'", 'data:', ...$dev],
            'connect-src' => ["'self'", ...$dev, ...array_map(fn (string $origin): string => preg_replace('/^http/', 'ws', $origin) ?? $origin, $dev)],
            'frame-src' => ["'self'", ...$turnstile, ...$payments],
            'worker-src' => ["'self'", 'blob:'],
            'manifest-src' => ["'self'"],
            'object-src' => ["'none'"],
            'base-uri' => ["'self'"],
            'form-action' => ["'self'", ...$payments],
            'frame-ancestors' => ["'self'"],
            'report-uri' => [self::REPORT_PATH],
        ];

        $policy = collect($directives)
            ->map(fn (array $sources, string $directive): string => $directive.' '.implode(' ', array_unique($sources)))
            ->implode('; ');

        return $request->isSecure() ? $policy.'; upgrade-insecure-requests' : $policy;
    }

    /**
     * While `npm run dev` runs, pages load scripts and styles from the Vite server
     * and keep a websocket open to it for hot reload.
     *
     * @return list<string>
     */
    private function viteDevServer(): array
    {
        if (! app()->isLocal() || ! Vite::isRunningHot()) {
            return [];
        }

        $url = trim((string) file_get_contents(Vite::hotFile()));
        $parts = parse_url($url);

        if (! isset($parts['scheme'], $parts['host'])) {
            return [];
        }

        $origin = $parts['scheme'].'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '');

        return [$origin];
    }
}
