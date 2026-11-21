<?php

namespace App\Jobs;

use App\Support\SystemHealth;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

/**
 * Queued every few minutes by the scheduler. When it stops arriving, the queue
 * worker is down and e-mails and alerts are waiting; the dashboard says so.
 */
class RecordQueueHeartbeat implements ShouldQueue
{
    use Queueable;

    public function handle(): void
    {
        Cache::forever(SystemHealth::QUEUE_HEARTBEAT, time());
    }
}
