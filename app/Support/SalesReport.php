<?php

namespace App\Support;

use App\Models\Product;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The numbers on the reports page for one date range. Sales are orders that
 * were not cancelled; their totals already include discounts, points and
 * delivery as charged.
 */
class SalesReport
{
    public const int MAX_DAYS = 366;

    public function __construct(private CarbonImmutable $from, private CarbonImmutable $to)
    {
        //
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $totals = $this->sales()->selectRaw(
            'COUNT(*) as orders, COALESCE(SUM(total), 0) as revenue, COALESCE(SUM(item_count), 0) as items,'
            .' COALESCE(SUM(discount_total), 0) as discounts, COALESCE(SUM(points_discount), 0) as points_discounts,'
            .' COALESCE(SUM(shipping_fee), 0) as shipping'
        )->first();

        // Product discounts and offers: what the items would have cost before them.
        $saleDiscounts = (float) DB::table('order_items')
            ->joinSub($this->sales()->select('id'), 'sold', 'sold.id', '=', 'order_items.order_id')
            ->whereNotNull('order_items.original_price')
            ->selectRaw('COALESCE(SUM((order_items.original_price - order_items.price) * order_items.quantity), 0) as amount')
            ->value('amount');

        $orders = (int) ($totals->orders ?? 0);
        $revenue = (float) ($totals->revenue ?? 0);

        return [
            'kpis' => [
                'revenue' => round($revenue, 2),
                'orders' => $orders,
                'average' => $orders > 0 ? round($revenue / $orders, 2) : 0,
                'items' => (int) ($totals->items ?? 0),
                // Coupons, points, and product discounts and offers.
                'discounts' => round((float) ($totals->discounts ?? 0) + (float) ($totals->points_discounts ?? 0) + $saleDiscounts, 2),
                'shipping' => round((float) ($totals->shipping ?? 0), 2),
                'cancelled' => $this->inRange(DB::table('orders'))->where('status', 'cancelled')->count(),
                'new_customers' => $this->inRange(DB::table('users'))->where('role', 'customer')->count(),
            ],
            'days' => $this->days(),
            'top_products' => $this->topProducts(),
            'top_categories' => $this->topCategories(),
            'coupons' => $this->grouped('coupon_code'),
            'payment_methods' => $this->grouped('payment_method'),
            'delivery_methods' => $this->grouped('delivery_method'),
            'statuses' => $this->inRange(DB::table('orders'))
                ->selectRaw('status, COUNT(*) as order_count, COALESCE(SUM(total), 0) as revenue')
                ->groupBy('status')
                ->orderBy('status')
                ->get()
                ->map(fn (object $row): array => [
                    'status' => __(ucfirst(str_replace('_', ' ', (string) $row->status))),
                    'order_count' => (int) $row->order_count,
                    'revenue' => round((float) $row->revenue, 2),
                ])
                ->values()
                ->all(),
            'points' => [
                'outstanding' => (int) DB::table('users')->sum('points_balance'),
                'earned' => (int) $this->inRange(DB::table('point_transactions'))->where('points', '>', 0)->where('type', 'earned')->sum('points'),
                'redeemed' => (int) abs((int) $this->inRange(DB::table('point_transactions'))->where('type', 'redeemed')->sum('points')),
            ],
            'catalog' => [
                'total' => Product::query()->count(),
                'active' => Product::query()->where('is_active', true)->count(),
                'out_of_stock' => Product::query()->where('is_active', true)->where('stock_quantity', 0)->count(),
                'estimated_prices' => Product::query()->where('price_is_estimate', true)->count(),
            ],
        ];
    }

    private function sales(): Builder
    {
        return $this->inRange(DB::table('orders'))->where('status', '!=', 'cancelled');
    }

    private function inRange(Builder $query, string $column = 'created_at'): Builder
    {
        return $query->whereBetween($column, [$this->from->startOfDay(), $this->to->endOfDay()]);
    }

    /**
     * @return list<array{date: string, total: float, orders: int}>
     */
    private function days(): array
    {
        $rows = $this->sales()
            ->selectRaw('DATE(created_at) as day, COUNT(*) as order_count, SUM(total) as revenue')
            ->groupByRaw('DATE(created_at)')
            ->get()
            ->keyBy(fn (object $row): string => substr((string) $row->day, 0, 10));

        $days = [];

        for ($day = $this->from->startOfDay(); $day->lessThanOrEqualTo($this->to); $day = $day->addDay()) {
            $date = $day->toDateString();
            $days[] = [
                'date' => $date,
                'total' => round((float) ($rows->get($date)->revenue ?? 0), 2),
                'orders' => (int) ($rows->get($date)->order_count ?? 0),
            ];
        }

        return $days;
    }

    /**
     * @return list<array{id: int|null, name: string, quantity: int, revenue: float}>
     */
    private function topProducts(): array
    {
        $rows = DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->where('orders.status', '!=', 'cancelled')
            ->whereBetween('orders.created_at', [$this->from->startOfDay(), $this->to->endOfDay()])
            ->selectRaw('order_items.product_id, SUM(order_items.quantity) as quantity, SUM(order_items.price * order_items.quantity) as revenue, MAX(order_items.id) as last_item')
            ->groupBy('order_items.product_id')
            ->orderByDesc('quantity')
            ->limit(10)
            ->get();

        // The name as sold, from the order line, so deleted products still show.
        $names = DB::table('order_items')->whereIn('id', $rows->pluck('last_item'))->pluck('name', 'id');

        return array_values($rows->map(fn (object $row): array => [
            'id' => $row->product_id === null ? null : (int) $row->product_id,
            'name' => $this->localized($names->get($row->last_item)),
            'quantity' => (int) $row->quantity,
            'revenue' => round((float) $row->revenue, 2),
        ])->all());
    }

    /**
     * @return list<array{name: string, quantity: int, revenue: float}>
     */
    private function topCategories(): array
    {
        $rows = DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->join('products', 'products.id', '=', 'order_items.product_id')
            ->where('orders.status', '!=', 'cancelled')
            ->whereBetween('orders.created_at', [$this->from->startOfDay(), $this->to->endOfDay()])
            ->whereNotNull('products.category_id')
            ->selectRaw('products.category_id, SUM(order_items.quantity) as quantity, SUM(order_items.price * order_items.quantity) as revenue')
            ->groupBy('products.category_id')
            ->orderByDesc('revenue')
            ->limit(10)
            ->get();

        $names = DB::table('category_translations')
            ->whereIn('category_id', $rows->pluck('category_id'))
            ->get(['category_id', 'locale', 'name'])
            ->groupBy('category_id');

        return array_values($rows->map(function (object $row) use ($names): array {
            $translations = $names->get($row->category_id)?->pluck('name', 'locale');

            return [
                'name' => (string) ($translations?->get(app()->getLocale()) ?? $translations?->first() ?? "#{$row->category_id}"),
                'quantity' => (int) $row->quantity,
                'revenue' => round((float) $row->revenue, 2),
            ];
        })->all());
    }

    /**
     * @param  'coupon_code'|'payment_method'|'delivery_method'  $column
     * @return list<array{key: string, orders: int, amount: float}>
     */
    private function grouped(string $column): array
    {
        // Fixed SQL per grouping: nothing from outside ever reaches selectRaw().
        $select = match ($column) {
            'coupon_code' => 'coupon_code as group_key, COUNT(*) as order_count, COALESCE(SUM(discount_total), 0) as amount',
            'payment_method' => 'payment_method as group_key, COUNT(*) as order_count, COALESCE(SUM(total), 0) as amount',
            'delivery_method' => 'delivery_method as group_key, COUNT(*) as order_count, COALESCE(SUM(shipping_fee), 0) as amount',
        };

        return array_values($this->sales()
            ->whereNotNull($column)
            ->selectRaw($select)
            ->groupBy($column)
            ->orderByDesc('order_count')
            ->limit(20)
            ->get()
            ->map(fn (object $row): array => [
                'key' => (string) $row->group_key,
                'orders' => (int) $row->order_count,
                'amount' => round((float) $row->amount, 2),
            ])
            ->all());
    }

    private function localized(mixed $value): string
    {
        $names = is_string($value) ? json_decode($value, true) : $value;

        if (! is_array($names)) {
            return '';
        }

        $name = $names[app()->getLocale()] ?? $names['ar'] ?? $names['en'] ?? reset($names);

        return is_string($name) ? $name : '';
    }
}
