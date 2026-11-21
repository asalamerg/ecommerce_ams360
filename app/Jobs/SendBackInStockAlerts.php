<?php

namespace App\Jobs;

use App\Models\Product;
use App\Notifications\BackInStock;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/**
 * E-mails everyone who asked to hear when a product is back. Each address is
 * written to once; marking it sent first means a retried job never repeats it.
 */
class SendBackInStockAlerts implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $productId)
    {
        //
    }

    public function handle(): void
    {
        $product = Product::query()->with('translations')->find($this->productId);

        if ($product === null || ! $product->isPurchasable()) {
            return;
        }

        DB::table('stock_alerts')
            ->where('product_id', $product->id)
            ->whereNull('notified_at')
            ->orderBy('id')
            ->chunkById(100, function ($alerts) use ($product): void {
                foreach ($alerts as $alert) {
                    $claimed = DB::table('stock_alerts')->where('id', $alert->id)->whereNull('notified_at')->update(['notified_at' => now()]);

                    if ($claimed === 1) {
                        Notification::route('mail', $alert->email)->notify(new BackInStock($product, (string) $alert->locale));
                    }
                }
            });
    }
}
