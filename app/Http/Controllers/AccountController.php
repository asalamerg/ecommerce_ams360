<?php

namespace App\Http\Controllers;

use App\Http\Requests\Settings\PasswordUpdateRequest;
use App\Http\Requests\UpdateAccountRequest;
use App\Models\Order;
use App\Support\Loyalty;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class AccountController extends Controller
{
    public function show(Request $request): Response
    {
        $user = $request->user();

        return Inertia::render('storefront/account', [
            'account' => [
                'name' => $user->name,
                'email' => $user->email,
                'phone' => $user->phone,
                'preferred_locale' => $user->preferred_locale,
                'email_verified' => $user->hasVerifiedEmail(),
            ],
            'status' => $request->session()->get('status'),
            'points' => $this->points($user->id),
            'tickets' => CustomerServiceController::tickets($user->id),
            'repairs' => CustomerServiceController::repairRequests($user->id),
            'orders' => Order::query()
                ->where('user_id', $user->id)
                ->latest('id')
                ->limit(20)
                ->get()
                ->map(fn (Order $order): array => [
                    'reference' => $order->reference,
                    'status' => __(ucfirst($order->status)),
                    'total' => $order->total,
                    'created_at' => $order->created_at?->toIso8601String(),
                ]),
        ]);
    }

    /**
     * Hidden when the programme is off and the customer has no points left.
     *
     * @return array<string, mixed>|null
     */
    private function points(int $userId): ?array
    {
        $balance = (int) DB::table('users')->where('id', $userId)->value('points_balance');

        if (! Loyalty::enabled() && $balance === 0) {
            return null;
        }

        return [
            'balance' => $balance,
            'value' => Loyalty::valueOf($balance),
            'history' => DB::table('point_transactions')
                ->leftJoin('orders', 'orders.id', '=', 'point_transactions.order_id')
                ->where('point_transactions.user_id', $userId)
                ->orderByDesc('point_transactions.id')
                ->limit(15)
                ->get(['point_transactions.type', 'point_transactions.points', 'point_transactions.balance_after', 'point_transactions.created_at', 'orders.reference'])
                ->map(fn (object $row): array => [
                    'type' => __('ui.points.types.'.$row->type),
                    'points' => (int) $row->points,
                    'balance_after' => (int) $row->balance_after,
                    'reference' => $row->reference,
                    'created_at' => $row->created_at === null ? null : date(DATE_ATOM, (int) strtotime((string) $row->created_at)),
                ])
                ->all(),
        ];
    }

    public function update(UpdateAccountRequest $request): RedirectResponse
    {
        $request->user()->update($request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('ui.account.saved')]);

        return back();
    }

    public function updatePassword(PasswordUpdateRequest $request): RedirectResponse
    {
        $request->user()->update(['password' => $request->password]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('ui.account.password_updated')]);

        return back();
    }
}
