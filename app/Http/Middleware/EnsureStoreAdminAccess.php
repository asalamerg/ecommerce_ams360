<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureStoreAdminAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        $role = $request->user()?->role;
        abort_unless(
            $role?->canManageAdministration()
                || $role?->canManageCatalog()
                || $role?->canManageInventory()
                || $role?->canManageOrders()
                || $role?->canManageSupport(),
            403,
        );

        return $next($request);
    }
}
