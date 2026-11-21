<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

class RequireStaffTwoFactor
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (
            config('store.require_staff_two_factor')
            && $user instanceof User
            && $user->role->isStaff()
            && ! $user->hasEnabledTwoFactorAuthentication()
        ) {
            Inertia::flash('toast', ['type' => 'warning', 'message' => __('ui.auth.two_factor_required')]);

            return to_route('security.edit');
        }

        return $next($request);
    }
}
