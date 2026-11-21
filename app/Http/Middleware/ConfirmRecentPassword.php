<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Asks staff for their password again before an action that would do lasting
 * harm in the hands of someone who found a signed-in admin screen: changing
 * roles or points, store settings, payment settings, and permanent deletes.
 * One confirmation lasts auth.password_timeout seconds.
 *
 * Laravel's own password.confirm middleware sends the browser back to the
 * request it stopped, which only works for page visits. A stopped form post
 * returns to the page it was sent from instead, where the click is repeated.
 *
 * With module names as parameters it guards only those admin modules.
 */
class ConfirmRecentPassword
{
    public function handle(Request $request, Closure $next, string ...$modules): Response
    {
        if ($modules !== [] && ! in_array($request->route('module'), $modules, true)) {
            return $next($request);
        }

        $confirmedAt = (int) $request->session()->get('auth.password_confirmed_at', 0);

        if (time() - $confirmedAt < (int) config('auth.password_timeout', 10800)) {
            return $next($request);
        }

        if ($request->expectsJson() && ! $request->header('X-Inertia')) {
            return response()->json(['message' => __('Password confirmation required.')], 423);
        }

        $back = url()->previous();

        // Only ever back into this site, so the confirmation cannot be used as a redirect elsewhere.
        if (parse_url($back, PHP_URL_HOST) !== $request->getHost()) {
            $back = url('/admin');
        }

        $request->session()->put('url.intended', $request->isMethod('GET') ? $request->fullUrl() : $back);

        return to_route('password.confirm');
    }
}
