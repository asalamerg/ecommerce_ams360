<?php

namespace App\Services;

use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * The only place that changes a customer's points. Every change locks the user
 * row, writes one point_transactions line with the balance after it, and keeps
 * users.points_balance equal to the sum of those lines.
 */
class PointsLedger
{
    public const string EARNED = 'earned';

    public const string REDEEMED = 'redeemed';

    public const string REFUNDED = 'refunded';

    public const string REVERSED = 'reversed';

    public const string ADJUSTED = 'adjusted';

    /**
     * Adds (positive) or takes (negative) points. A balance never goes below
     * zero: taking back more than is left takes only what is left, and the line
     * records the amount actually taken.
     *
     * @return int The points actually added or taken.
     */
    public function record(int $userId, int $points, string $type, ?Order $order = null, ?User $actor = null, ?string $note = null): int
    {
        return DB::transaction(function () use ($userId, $points, $type, $order, $actor, $note): int {
            $balance = (int) DB::table('users')->where('id', $userId)->lockForUpdate()->value('points_balance');
            $applied = max($points, -$balance);

            if ($applied === 0) {
                return 0;
            }

            $after = $balance + $applied;

            DB::table('users')->where('id', $userId)->update(['points_balance' => $after]);
            DB::table('point_transactions')->insert([
                'user_id' => $userId,
                'order_id' => $order?->id,
                'type' => $type,
                'points' => $applied,
                'balance_after' => $after,
                'actor_id' => $actor?->id,
                'note' => $note === null ? null : mb_substr($note, 0, 255),
                'created_at' => now(),
            ]);

            return $applied;
        });
    }
}
