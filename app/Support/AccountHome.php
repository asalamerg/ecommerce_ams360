<?php

namespace App\Support;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Support\Facades\App;

/**
 * The single answer to "where does this user land after signing in?".
 */
class AccountHome
{
    public static function urlFor(?User $user): string
    {
        return match ($user?->role) {
            UserRole::Owner, UserRole::Administrator => route('admin.dashboard'),
            UserRole::ProductManager => route('admin.products.index'),
            UserRole::InventoryManager => route('admin.inventory.index'),
            UserRole::OrderManager => route('admin.orders.index'),
            UserRole::SupportAgent => route('admin.modules.index', ['module' => 'support']),
            default => route('storefront.home', ['locale' => App::getLocale()]),
        };
    }
}
