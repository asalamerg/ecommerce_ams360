<?php

use App\Jobs\RecordQueueHeartbeat;
use App\Support\SystemHealth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;

Schedule::command('model:prune')->daily()->description('Prune expired audit records');
Schedule::command('store:reindex-search')->daily()->description('Pick up brand and category renames in product search');
// Discounts and offers start and end on the minute, see ProductPricing.
Schedule::command('store:refresh-prices')->everyMinute()->withoutOverlapping()->description('Start and end discounts and offers on time');
// Every five minutes, because unpaid online orders are released after half an hour.
Schedule::command('store:expire-pending-orders')->everyFiveMinutes()->withoutOverlapping()->description('Return stock held by orders nobody confirmed or paid');

// Proof of life for the dashboard's system warnings, see SystemHealth.
Schedule::call(fn () => Cache::forever(SystemHealth::SCHEDULER_HEARTBEAT, time()))->everyMinute()->name('scheduler-heartbeat');
Schedule::job(new RecordQueueHeartbeat)->everyFiveMinutes()->name('queue-heartbeat');

Schedule::call(fn () => DB::table('notifications')->where('created_at', '<', now()->subDays(90))->delete())
    ->daily()->name('prune-staff-notifications')->description('Remove staff notifications older than 90 days');

// Keep no more personal or operational data than needed (privacy).
Schedule::call(function (): void {
    DB::table('carts')->whereNull('user_id')->where('updated_at', '<', now()->subDays(30))->delete();
    DB::table('stock_alerts')->whereNotNull('notified_at')->where('notified_at', '<', now()->subDays(90))->delete();
    DB::table('payment_webhook_events')->where('created_at', '<', now()->subDays(180))->delete();
})->daily()->name('prune-stale-data')->description('Remove abandoned guest carts, sent stock alerts and old payment notifications');
