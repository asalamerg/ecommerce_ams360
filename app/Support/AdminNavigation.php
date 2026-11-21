<?php

namespace App\Support;

use App\Enums\UserRole;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Builds the admin sidebar and alert list on the server, so a role only ever
 * receives links it can open. Routes still enforce the same rules themselves.
 */
class AdminNavigation
{
    public const int LOW_STOCK_THRESHOLD = 5;

    /**
     * @return array{groups: list<array<string, mixed>>, alerts: list<array{key: string, label: string, count: int, href: string}>, role: string}
     */
    public static function for(User $user): array
    {
        return [
            'groups' => self::groups($user->role),
            'alerts' => self::alerts($user->role),
            'notifications' => self::notifications($user),
            'role' => __(str($user->role->value)->replace('_', ' ')->ucfirst()->toString()),
        ];
    }

    /**
     * The latest events for this staff member, newest first, in their language.
     *
     * @return array{unread: int, items: list<array{id: string, kind: string, title: string, read: bool, at: string|null}>}
     */
    private static function notifications(User $user): array
    {
        return [
            'unread' => $user->unreadNotifications()->count(),
            'items' => array_values($user->notifications()->latest()->limit(8)->get()->map(function ($entry): array {
                $title = __((string) ($entry->data['title'] ?? ''), (array) ($entry->data['params'] ?? []));

                return [
                    'id' => (string) $entry->id,
                    'kind' => (string) ($entry->data['kind'] ?? ''),
                    'title' => is_string($title) ? $title : '',
                    'read' => $entry->read_at !== null,
                    'at' => $entry->created_at?->toIso8601String(),
                ];
            })->all()),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function groups(UserRole $role): array
    {
        $module = fn (string $key, string $label, string $icon, ?string $alert = null): array => array_filter([
            'label' => __($label),
            'href' => "/admin/modules/{$key}",
            'icon' => $icon,
            'alert' => $alert,
        ]);

        $link = fn (string $href, string $label, string $icon, ?string $alert = null): array => array_filter([
            'label' => __($label),
            'href' => $href,
            'icon' => $icon,
            'alert' => $alert,
        ]);

        $groups = [
            ['label' => __('Overview'), 'items' => [
                $link('/admin/dashboard', 'Dashboard', 'dashboard'),
                ...($role->canManageAdministration() ? [$link('/admin/reports', 'Reports', 'reports')] : []),
            ]],
        ];

        if ($role->canManageOrders()) {
            $groups[] = ['label' => __('Sales'), 'items' => [
                $link('/admin/orders', 'Orders', 'orders', 'orders'),
                ...($role->canManageAdministration() ? [
                    $module('coupons', 'Coupons', 'coupons'),
                    $module('offers', 'Offers', 'offers'),
                ] : []),
            ]];
        }

        if ($role->canManageCatalog()) {
            $groups[] = ['label' => __('Catalog'), 'items' => [
                $link('/admin/products', 'Products', 'products'),
                // The home page's "Featured products" row, found by the menu search.
                $link('/admin/products?status=featured', 'Featured products', 'featured'),
                $link('/admin/categories', 'Categories', 'categories'),
                $module('brands', 'Brands', 'brands'),
                $link('/admin/brands/logos', 'Brand logos', 'brands'),
            ]];
            $groups[] = ['label' => __('Content'), 'items' => [
                $module('hero-slides', 'Hero slides', 'slides'),
                $module('pages', 'Pages', 'pages'),
                $module('sim-plans', 'SIM plans', 'sim'),
                $module('services', 'Services', 'services'),
                $module('branches', 'Branches', 'branches'),
            ]];
        }

        if ($role->canManageInventory()) {
            $groups[] = ['label' => __('Inventory'), 'items' => [
                $link('/admin/inventory', 'Stock levels', 'inventory', 'low_stock'),
            ]];
        }

        if ($role->canManageSupport()) {
            $groups[] = ['label' => __('Customer care'), 'items' => [
                $module('maintenance', 'Maintenance', 'maintenance', 'maintenance'),
                $module('support', 'Support tickets', 'support', 'support'),
                $module('contact', 'Contact messages', 'contact', 'contact'),
                $module('reviews', 'Reviews', 'reviews', 'reviews'),
            ]];
        }

        if ($role->canManageAdministration()) {
            $groups[] = ['label' => __('Store settings'), 'items' => [
                $link('/admin/theme', 'Theme', 'theme'),
                $module('settings', 'Settings', 'settings'),
                $module('loyalty', 'Loyalty points', 'loyalty'),
                $module('shipping-zones', 'Delivery zones', 'shipping'),
                $module('users', 'Users', 'users'),
            ]];
        }

        $help = [$link('/admin/guide', 'Admin guide', 'guide')];

        if ($role->canManageCatalog()) {
            array_unshift($help, $link('/admin/activity', 'Activity log', 'activity'));
        }

        $groups[] = ['label' => __('Help'), 'items' => $help];

        return $groups;
    }

    /**
     * @return list<array{key: string, label: string, count: int, href: string}>
     */
    private static function alerts(UserRole $role): array
    {
        $alerts = [];

        if ($role->canManageOrders()) {
            $alerts[] = self::alert('orders', 'Pending orders', DB::table('orders')->where('status', 'pending')->count(), '/admin/orders?status=pending');
        }

        if ($role->canManageInventory()) {
            $lowStock = Product::query()
                ->where('is_active', true)
                ->where('stock_quantity', '<=', self::LOW_STOCK_THRESHOLD)
                ->count();
            $alerts[] = self::alert('low_stock', 'Low-stock products', $lowStock, '/admin/inventory');
        }

        if ($role->canManageSupport()) {
            $alerts[] = self::alert('maintenance', 'New repair requests', DB::table('maintenance_requests')->where('status', 'received')->count(), '/admin/modules/maintenance');
            $alerts[] = self::alert('support', 'Open support tickets', DB::table('support_tickets')->where('status', 'open')->count(), '/admin/modules/support');
            $alerts[] = self::alert('contact', 'Unread messages', DB::table('contact_messages')->where('is_read', false)->count(), '/admin/modules/contact');
            $alerts[] = self::alert('reviews', 'Reviews to moderate', DB::table('reviews')->where('is_approved', false)->count(), '/admin/modules/reviews');
        }

        return array_values(array_filter($alerts, fn (array $alert): bool => $alert['count'] > 0));
    }

    /**
     * @return array{key: string, label: string, count: int, href: string}
     */
    private static function alert(string $key, string $label, int $count, string $href): array
    {
        return ['key' => $key, 'label' => __($label), 'count' => $count, 'href' => $href];
    }
}
