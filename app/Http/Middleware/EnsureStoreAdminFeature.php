<?php

namespace App\Http\Middleware;

use App\Enums\UserRole;
use App\Support\StoreAdminModules;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureStoreAdminFeature
{
    public function handle(Request $request, Closure $next): Response
    {
        $definition = StoreAdminModules::find((string) $request->route('module'));
        abort_unless($definition !== null, 404);

        $role = $request->user()?->role;
        $allowed = match ($definition['permission']) {
            'content' => $role?->canManageCatalog() ?? false,
            'orders' => $role?->canManageOrders() ?? false,
            'support' => $role?->canManageSupport() ?? false,
            'owner' => $role === UserRole::Owner,
            'admin' => $role?->canManageAdministration() ?? false,
            default => false,
        };
        abort_unless($allowed, 403);

        return $next($request);
    }
}
