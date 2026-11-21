<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\ProductSpreadsheet;
use App\Support\SalesReport;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportController extends Controller
{
    public function index(Request $request): Response
    {
        [$from, $to] = $this->range($request);

        return Inertia::render('admin/reports/index', [
            'range' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'report' => (new SalesReport($from, $to))->toArray(),
        ]);
    }

    /**
     * Orders of the range as a spreadsheet. It holds customer contact details,
     * so it asks for the password again (see routes/web.php).
     */
    public function exportOrders(Request $request): StreamedResponse
    {
        [$from, $to] = $this->range($request);

        return response()->streamDownload(function () use ($from, $to): void {
            $out = fopen('php://output', 'wb');

            if ($out === false) {
                return;
            }

            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, [
                'reference', 'date', 'customer', 'phone', 'email', 'status', 'payment_status', 'payment_method',
                'delivery', 'items', 'subtotal', 'discount', 'points_discount', 'shipping', 'total', 'coupon',
            ], escape: '');

            Order::query()
                ->whereBetween('created_at', [$from->startOfDay(), $to->endOfDay()])
                ->orderBy('id')
                ->chunk(500, function (Collection $orders) use ($out): void {
                    foreach ($orders as $order) {
                        /** @var Order $order */
                        fputcsv($out, array_map(ProductSpreadsheet::safeCell(...), [
                            $order->reference,
                            $order->created_at?->format('Y-m-d H:i'),
                            $order->customer_name,
                            $order->phone,
                            $order->email,
                            $order->status,
                            $order->payment_status,
                            $order->payment_method,
                            $order->deliveryLabel(),
                            $order->item_count,
                            $order->subtotal,
                            $order->discount_total,
                            $order->points_discount,
                            $order->shipping_fee,
                            $order->total,
                            $order->coupon_code,
                        ]), escape: '');
                    }
                });

            fclose($out);
        }, "orders-{$from->toDateString()}-{$to->toDateString()}.csv", ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * The last 30 days unless chosen, never more than a year.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function range(Request $request): array
    {
        $data = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);

        $to = isset($data['to']) ? CarbonImmutable::parse($data['to']) : CarbonImmutable::today();
        $from = isset($data['from']) ? CarbonImmutable::parse($data['from']) : $to->subDays(29);

        if ($from->diffInDays($to) >= SalesReport::MAX_DAYS) {
            $from = $to->subDays(SalesReport::MAX_DAYS - 1);
        }

        return [$from, $to];
    }
}
