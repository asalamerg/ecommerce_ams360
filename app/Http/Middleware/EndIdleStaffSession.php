<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Signs staff out after store.staff_idle_minutes without a request, so an admin
 * screen left open on a shared or lost device does not stay usable. Customers
 * keep the normal session lifetime.
 */
class EndIdleStaffSession
{
    private const string KEY = 'staff_last_seen_at';

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $limit = (int) config('store.staff_idle_minutes') * 60;

        if (! $user instanceof User || ! $user->role->isStaff() || $limit <= 0) {
            return $next($request);
        }

        $lastSeen = (int) $request->session()->get(self::KEY, time());

        if (time() - $lastSeen > $limit) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return to_route('admin.login')->with('status', __('You were signed out after a period of inactivity.'));
        }

        $request->session()->put(self::KEY, time());

        return $next($request);
    }
}
