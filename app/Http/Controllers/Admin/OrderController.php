<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Orders\ChangeOrderStatus;
use App\Actions\Payments\RefundPayment;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderEvent;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Support\CustomerWhatsApp;
use App\Support\ProductCardData;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

class OrderController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = $request->validate([
            'status' => ['nullable', Rule::in(Order::STATUSES)],
            'payment' => ['nullable', Rule::in(Order::PAYMENT_STATUSES)],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'q' => ['nullable', 'string', 'max:100'],
        ]);

        $search = trim($filters['q'] ?? '');

        $orders = Order::query()
            ->when($filters['status'] ?? null, fn (Builder $query, string $status) => $query->where('status', $status))
            ->when($filters['payment'] ?? null, fn (Builder $query, string $payment) => $query->where('payment_status', $payment))
            ->when($filters['from'] ?? null, fn (Builder $query, string $from) => $query->where('created_at', '>=', $from.' 00:00:00'))
            ->when($filters['to'] ?? null, fn (Builder $query, string $to) => $query->where('created_at', '<=', $to.' 23:59:59'))
            ->when($search !== '', function (Builder $query) use ($search): void {
                $term = '%'.addcslashes($search, '%_\\').'%';
                $digits = preg_replace('/\D/', '', $search);

                $query->where(fn (Builder $where) => $where
                    ->where('reference', 'like', $term)
                    ->orWhere('customer_name', 'like', $term)
                    ->orWhere('email', 'like', $term)
                    ->when(strlen((string) $digits) >= 4, fn (Builder $phone) => $phone->orWhere('phone_digits', 'like', '%'.$digits.'%')));
            })
            ->latest('id')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (Order $order): array => [
                'id' => $order->id,
                'reference' => $order->reference,
                'customer' => $order->customer_name,
                'phone' => $order->phone,
                'total' => (string) $order->total,
                'items' => $order->item_count,
                'status' => $order->status,
                'payment_status' => $order->payment_status,
                'payment_method' => $order->payment_method,
                'delivery_method' => $order->delivery_method,
                'created_at' => $order->created_at?->toIso8601String(),
            ]);

        return Inertia::render('admin/orders/index', [
            'orders' => $orders,
            'filters' => [
                'status' => $filters['status'] ?? '',
                'payment' => $filters['payment'] ?? '',
                'from' => $filters['from'] ?? '',
                'to' => $filters['to'] ?? '',
                'q' => $search,
            ],
            'counts' => DB::table('orders')->selectRaw('status, COUNT(*) as total')->groupBy('status')->pluck('total', 'status'),
            'options' => $this->options(),
        ]);
    }

    public function show(Order $order): Response
    {
        $order->load(['items', 'events.user:id,name', 'user:id,name,email', 'payments']);

        return Inertia::render('admin/orders/show', [
            'order' => [
                ...$this->summary($order),
                'items' => $order->items->map(fn (OrderItem $item): array => [
                    'id' => $item->id,
                    'product_id' => $item->product_id,
                    'name' => $this->itemName($item),
                    'color' => $item->color_name,
                    'price' => (string) $item->price,
                    'original_price' => $item->original_price === null ? null : (string) $item->original_price,
                    'quantity' => $item->quantity,
                    'line_total' => $item->lineTotal(),
                    'price_is_estimate' => $item->price_is_estimate,
                ])->values(),
                'events' => $order->events->sortByDesc('id')->map(fn (OrderEvent $event): array => [
                    'id' => $event->id,
                    'type' => $event->type,
                    'from' => $event->from,
                    'to' => $event->to,
                    'note' => $event->note,
                    'by' => $event->user?->name,
                    'at' => $event->created_at?->toIso8601String(),
                ])->values(),
                'account' => $order->user ? ['id' => $order->user->id, 'name' => $order->user->name] : null,
                'payment_is_manual' => $order->paymentIsManual(),
                'payments' => $order->payments->sortByDesc('id')->map(fn (Payment $payment): array => [
                    'id' => $payment->id,
                    'gateway' => $payment->gateway,
                    'status' => $payment->status,
                    'amount' => (string) $payment->amount,
                    'reference' => $payment->provider_reference,
                    'reason' => $payment->failure_reason,
                    'at' => $payment->created_at?->toIso8601String(),
                ])->values(),
                'can_refund' => request()->user()->role->canManageAdministration()
                    && $order->payments->contains('status', Payment::PAID),
            ],
            'options' => $this->options(),
            'whatsappReady' => CustomerWhatsApp::number($order->phone) !== null,
        ]);
    }

    public function update(Request $request, Order $order, ChangeOrderStatus $change): RedirectResponse
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(Order::STATUSES)],
            'payment_status' => ['nullable', Rule::in(Order::PAYMENT_STATUSES)],
            'notify_customer' => ['sometimes', 'boolean'],
        ]);

        $changed = $change->execute(
            $order,
            $data['status'],
            $data['payment_status'] ?? null,
            $request->user(),
            (bool) ($data['notify_customer'] ?? true),
        );

        return back()->with('success', $changed ? __('Changes saved.') : __('Nothing changed.'));
    }

    /**
     * The same change for many orders at once. An order that cannot take it (a
     * cancelled order cannot be reopened) is skipped and counted.
     */
    public function bulk(Request $request, ChangeOrderStatus $change): RedirectResponse
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:100'],
            'ids.*' => ['integer', 'distinct'],
            'status' => ['required', Rule::in(Order::STATUSES)],
            'notify_customer' => ['sometimes', 'boolean'],
        ]);

        $updated = 0;
        $skipped = 0;

        foreach (Order::query()->whereKey($data['ids'])->orderBy('id')->get() as $order) {
            try {
                $updated += (int) $change->execute($order, $data['status'], null, $request->user(), (bool) ($data['notify_customer'] ?? false));
            } catch (HttpException) {
                $skipped++;
            }
        }

        return back()->with('success', __(':updated orders updated, :skipped skipped.', ['updated' => $updated, 'skipped' => $skipped]));
    }

    public function note(Request $request, Order $order): RedirectResponse
    {
        $data = $request->validate(['note' => ['required', 'string', 'max:2000']]);

        OrderEvent::record($order, OrderEvent::NOTE, note: $data['note'], actor: $request->user());

        return back()->with('success', __('Note added.'));
    }

    /**
     * Opens WhatsApp with a status message for the customer, and notes in the
     * order's history who sent it.
     */
    public function whatsapp(Request $request, Order $order): RedirectResponse
    {
        $url = CustomerWhatsApp::url($order);
        abort_if($url === null, 422, __('This phone number cannot be used on WhatsApp.'));

        OrderEvent::record($order, OrderEvent::CUSTOMER_WHATSAPP, to: $order->status, actor: $request->user());

        return redirect()->away($url);
    }

    public function refund(Request $request, Order $order, RefundPayment $refund): RedirectResponse
    {
        $result = $refund->execute($order, $request->user());

        return back()->with('success', $result === 'refunded'
            ? __('The payment was refunded.')
            : __('The refund was sent to the payment provider and will show here once confirmed.'));
    }

    public function invoice(Order $order): Response
    {
        $order->load('items');
        $settings = DB::table('site_settings')->where('id', 1)->first();
        $locale = app()->getLocale();

        return Inertia::render('admin/orders/invoice', [
            'order' => [
                ...$this->summary($order),
                'items' => $order->items->map(fn (OrderItem $item): array => [
                    'name' => $this->itemName($item),
                    'color' => $item->color_name,
                    'price' => (string) $item->price,
                    'quantity' => $item->quantity,
                    'line_total' => $item->lineTotal(),
                ])->values(),
            ],
            'store' => [
                'name' => ProductCardData::localized($settings->site_name ?? null, $locale) ?? config('app.name'),
                'address' => ProductCardData::localized($settings->address ?? null, $locale),
                'phone' => $settings->phone ?? null,
                'logo_url' => $settings->logo_url ?? null,
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function summary(Order $order): array
    {
        return [
            'id' => $order->id,
            'reference' => $order->reference,
            'customer_name' => $order->customer_name,
            'phone' => $order->phone,
            'email' => $order->email,
            'address' => $order->shipping_address['address'] ?? null,
            'city' => $order->shipping_address['city'] ?? null,
            'notes' => $order->notes,
            'locale' => $order->locale,
            'status' => $order->status,
            'payment_status' => $order->payment_status,
            'payment_method' => $order->payment_method,
            'payment_reference' => $order->payment_reference,
            'delivery_method' => $order->delivery_method,
            'delivery_label' => $order->deliveryLabel(),
            'shipping_fee' => (string) $order->shipping_fee,
            'subtotal' => (string) $order->subtotal,
            'discount_total' => (string) $order->discount_total,
            'coupon_code' => $order->coupon_code,
            'points_used' => $order->points_used,
            'points_discount' => (string) $order->points_discount,
            'points_earned' => $order->points_earned,
            'total' => (string) $order->total,
            'item_count' => $order->item_count,
            'created_at' => $order->created_at?->toIso8601String(),
        ];
    }

    /**
     * @return array{statuses: array<string, string>, payment_statuses: array<string, string>}
     */
    private function options(): array
    {
        $label = fn (string $value): string => __(ucfirst(str_replace('_', ' ', $value)));

        return [
            'statuses' => collect(Order::STATUSES)->mapWithKeys(fn (string $status): array => [$status => $label($status)])->all(),
            'payment_statuses' => collect(Order::PAYMENT_STATUSES)->mapWithKeys(fn (string $status): array => [$status => $label($status)])->all(),
        ];
    }

    private function itemName(OrderItem $item): string
    {
        $names = $item->name;
        $name = $names[app()->getLocale()] ?? $names['ar'] ?? $names['en'] ?? reset($names);

        return is_string($name) ? $name : '';
    }
}
