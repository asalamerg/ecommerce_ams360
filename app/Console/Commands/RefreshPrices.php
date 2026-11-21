<?php

namespace App\Console\Commands;

use App\Services\ProductPricing;
use Illuminate\Console\Command;

class RefreshPrices extends Command
{
    protected $signature = 'store:refresh-prices';

    protected $description = 'Start and end discounts and offers on time: recompute every product\'s sale price';

    public function handle(): int
    {
        $this->info('Updated the sale price of '.ProductPricing::refreshAll().' products.');

        return self::SUCCESS;
    }
}
