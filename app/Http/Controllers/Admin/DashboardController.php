<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Product;
use App\Support\AdminNavigation;
use App\Support\SystemHealth;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    private const int CHART_DAYS = 14;

    public function index(Request $request): Response
    {
        $role = $request->user()->role;

        return Inertia::render('admin/dashboard', [
            'can' => [
                'catalog' => $role->canManageCatalog(),
                'inventory' => $role->canManageInventory(),
                'orders' => $role->canManageOrders(),
                'support' => $role->canManageSupport(),
                'reports' => $role->canManageAdministration(),
            ],
            'metrics' => [
                'products' => Product::query()->count(),
                'active_products' => Product::query()->where('is_active', true)->count(),
                'categories' => Category::query()->count(),
                'orders' => DB::table('orders')->count(),
                'pending_orders' => DB::table('orders')->where('status', 'pending')->count(),
                'unread_messages' => DB::table('contact_messages')->where('is_read', false)->count(),
                'unapproved_reviews' => DB::table('reviews')->where('is_approved', false)->count(),
                'low_stock' => Product::query()->where('is_active', true)->where('stock_quantity', '<=', AdminNavigation::LOW_STOCK_THRESHOLD)->count(),
            ],
            'sales' => $role->canManageOrders() ? $this->sales() : null,
            'recentOrders' => $role->canManageOrders() ? $this->recentOrders() : [],
            'lowStock' => $role->canManageInventory() ? $this->lowStock() : [],
            'systemWarnings' => $role->canManageAdministration() ? SystemHealth::warnings() : [],
        ]);
    }

    public function guide(): Response
    {
        return Inertia::render('admin/guide');
    }

    /**
     * Totals exclude cancelled orders, which never became sales.
     *
     * @return array<string, mixed>
     */
    private function sales(): array
    {
        $today = CarbonImmutable::today();
        $firstDay = $today->subDays(self::CHART_DAYS - 1);

        $rows = DB::table('orders')
            ->where('status', '!=', 'cancelled')
            ->where('created_at', '>=', $firstDay)
            ->selectRaw('DATE(created_at) as day, COUNT(*) as order_count, SUM(total) as revenue')
            ->groupByRaw('DATE(created_at)')
            ->get()
            ->keyBy(fn (object $row): string => substr((string) $row->day, 0, 10));

        $days = Collection::times(self::CHART_DAYS, function (int $offset) use ($firstDay, $rows): array {
            $date = $firstDay->addDays($offset - 1)->toDateString();

            return [
                'date' => $date,
                'total' => (float) ($rows->get($date)->revenue ?? 0),
                'orders' => (int) ($rows->get($date)->order_count ?? 0),
            ];
        });

        $todayRow = $days->last();

        return [
            'today' => (string) $todayRow['total'],
            'today_orders' => $todayRow['orders'],
            'period' => (string) $days->sum('total'),
            'period_orders' => $days->sum('orders'),
            'days' => $days->values()->all(),
        ];
    }

    /**
     * @return array<int, array{id: int, customer: string, total: string, status: string, created_at: string|null}>
     */
    private function recentOrders(): array
    {
        return DB::table('orders')
            ->latest('id')
            ->limit(5)
            ->get(['id', 'customer_name', 'total', 'status', 'created_at'])
            ->map(fn (object $order): array => [
                'id' => (int) $order->id,
                'customer' => (string) $order->customer_name,
                'total' => (string) $order->total,
                'status' => __(ucfirst((string) $order->status)),
                'created_at' => $order->created_at === null ? null : CarbonImmutable::parse($order->created_at)->toIso8601String(),
            ])
            ->all();
    }

    /**
     * @return array<int, array{id: int, name: string, stock: int}>
     */
    private function lowStock(): array
    {
        return Product::query()
            ->with('translations')
            ->where('is_active', true)
            ->where('stock_quantity', '<=', AdminNavigation::LOW_STOCK_THRESHOLD)
            ->orderBy('stock_quantity')
            ->limit(5)
            ->get()
            ->map(fn (Product $product): array => [
                'id' => $product->id,
                'name' => (string) ($product->translations->firstWhere('locale', app()->getLocale())->name
                    ?? $product->translations->first()->name
                    ?? $product->sku),
                'stock' => $product->stock_quantity,
            ])
            ->all();
    }
}
